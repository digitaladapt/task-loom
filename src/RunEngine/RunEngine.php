<?php

declare(strict_types=1);

namespace App\RunEngine;

use App\Context\ContextExhaustedException;
use App\Context\ContextWindow;
use App\Entity\ErrorClass;
use App\Entity\Run;
use App\Entity\RunEvent;
use App\Entity\RunEventType;
use App\Entity\Task;
use App\Entity\Tool;
use App\Llm\LlmClientInterface;
use App\Llm\LlmRequestException;
use App\Repository\RunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The run loop (SPEC §3, §5): compile prompt → LLM → (validate → execute →
 * append results) → checkpoint → repeat, until a justified completion
 * declaration or a budget failure. Every exchange is a typed RunEvent row
 * in the attempt ledger (§5.3), persisted before the next LLM request
 * (checkpoint, §5.5).
 */
final class RunEngine
{
    /**
     * @param array<string, int> $budgets step_budget, tool_retries, circuit_breaker
     */
    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly PromptCompiler $prompts,
        private readonly ToolboxResolver $resolver,
        private readonly ToolExecutorInterface $executor,
        private readonly ContextWindow $context,
        private readonly RunRepository $runs,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly array $budgets = [],
    ) {
    }

    /**
     * Run a task to completion (v1: synchronous, one run at a time —
     * the LLM_MAX_CONCURRENCY semaphore is chunk 4's Messenger work).
     */
    public function run(Task $task): Run
    {
        // Resolve + freeze the toolbox before anything else (SPEC §4.1).
        $tools = $this->resolver->resolve($task);

        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->getName()] = $tool;
        }

        $promptHead = $this->prompts->compile($task, $tools);
        $openAiTools = $this->prompts->toolsToOpenAi($tools);

        $run = new Run($task);
        $run->setToolboxSnapshot(array_map(
            static fn (Tool $t): array => [
                'server' => $t->getServer()->getName(),
                'tool' => $t->getName(),
                'schema' => $t->getSchema(),
            ],
            $tools,
        ));
        $run->markStarted();
        $this->runs->save($run);

        $state = new LoopState(
            stepBudget: $this->budgets['step_budget'] ?? 50,
            toolRetries: $this->budgets['tool_retries'] ?? 2,
            circuitBreakerThreshold: $this->budgets['circuit_breaker'] ?? 3,
        );

        try {
            $this->loop($run, $task, $promptHead, $openAiTools, $toolMap, $state);
        } catch (ContextExhaustedException $e) {
            $run->markFailed($e->errorClass);
            $this->appendEvent($run, RunEventType::Failure, ['reason' => $e->getMessage()], errorClass: $e->errorClass);
        } catch (LlmRequestException $e) {
            $run->markFailed($e->errorClass);
            $this->appendEvent($run, RunEventType::Failure, ['reason' => $e->getMessage()], errorClass: $e->errorClass);
        } catch (ToolExecutionException $e) {
            $run->markFailed($e->errorClass);
            $this->appendEvent($run, RunEventType::Failure, ['reason' => $e->getMessage()], errorClass: $e->errorClass);
        } catch (RunTerminatedException $e) {
            // Circuit breaker already marked the run needs_attention and
            // wrote its event; the run is deliberately stopped, not failed.
        } catch (\Throwable $e) {
            $run->markFailed(ErrorClass::Unknown);
            $this->appendEvent($run, RunEventType::Failure, ['reason' => $e->getMessage()], errorClass: ErrorClass::Unknown);
        }

        $this->runs->save($run);

        return $run;
    }

    /**
     * @param array{system: string, user: string} $promptHead
     * @param list<array<string, mixed>>          $openAiTools
     * @param array<string, Tool>                 $toolMap
     */
    private function loop(
        Run $run,
        Task $task,
        array $promptHead,
        array $openAiTools,
        array $toolMap,
        LoopState $state,
    ): void {
        while (true) {
            if ($state->step >= $state->stepBudget) {
                $run->markIncomplete();
                $this->appendEvent(
                    $run,
                    RunEventType::Failure,
                    ['reason' => \sprintf('step budget exhausted: %d exchanges, no completion declaration', $state->stepBudget)],
                    errorClass: ErrorClass::BudgetExceeded,
                );
                $this->logger->warning('Run {run}: step budget exhausted.', ['run' => $run->getId()]);

                return;
            }

            ++$state->step;
            $run->incrementStepCount();

            $this->appendEvent($run, RunEventType::LlmRequest, [
                'step' => $state->step,
                'exchangesSoFar' => \count($state->exchanges),
            ]);
            $this->em->flush();

            $messages = $this->context->buildMessages($promptHead, $state->exchanges);
            $response = $this->llm->chat($messages, $openAiTools);

            $this->appendEvent(
                $run,
                RunEventType::LlmResponse,
                [
                    'step' => $state->step,
                    'finishReason' => $response->finishReason,
                    'content' => $response->content,
                    'reasoningContent' => $response->reasoningContent,
                    'usage' => $response->usage,
                ],
                durationMs: $response->durationMs,
            );
            $this->em->flush();

            if (!$response->wantsToolCall()) {
                // Terminal message: completion must BE the result (SPEC §5.4).
                if (null !== $response->content && '' !== trim($response->content)) {
                    $run->markSucceeded();
                    $this->appendEvent($run, RunEventType::Completion, ['result' => $response->content]);
                } else {
                    $run->markIncomplete();
                    $this->appendEvent(
                        $run,
                        RunEventType::Failure,
                        ['reason' => 'terminal message with no content — no completion declaration'],
                        errorClass: ErrorClass::LlmMalformedResponse,
                    );
                }
                $this->em->flush();

                return;
            }

            $toolResults = [];
            foreach ($response->getToolCalls() as $call) {
                $toolResults[] = $this->executeToolCall($run, $toolMap, $state, $call);
            }

            $state->exchanges[] = [
                'assistant' => [
                    'content' => $response->content,
                    'toolCalls' => $response->getToolCalls(),
                ],
                'toolResults' => $toolResults,
            ];

            // Checkpoint: every exchange persisted before the next request (§5.5).
            $run->setCheckpoint(['step' => $state->step]);
            $this->appendEvent($run, RunEventType::Checkpoint, ['step' => $state->step]);
            $this->em->flush();
        }
    }

    /**
     * Execute one tool call: validate → dispatch → result, with retries
     * feeding errors back to the model (§5.1, §5.2).
     *
     * @param array<string, Tool>                                              $toolMap
     * @param array{id: string, name: string, arguments: array<string, mixed>} $call
     *
     * @return array{toolCallId: string, content: string}
     */
    private function executeToolCall(Run $run, array $toolMap, LoopState $state, array $call): array
    {
        $callId = $call['id'];
        $toolName = $call['name'];
        $arguments = $call['arguments'];

        $tool = $toolMap[$toolName] ?? null;

        if (null === $tool) {
            // Prompt-injection mitigation by construction: a tool outside
            // the frozen toolbox never dispatches (SPEC §2.1, §4.1).
            $this->appendEvent(
                $run,
                RunEventType::ToolValidationError,
                ['tool' => $toolName, 'detail' => 'tool not in this run\'s toolbox'],
                errorClass: ErrorClass::ToolNotFound,
            );

            return [
                'toolCallId' => $callId,
                'content' => $this->errorFeedbackJson('tool_not_found', $toolName, 'not in this run\'s toolbox'),
            ];
        }

        $attempt = 1;

        while (true) {
            $errors = $this->executor->validate($tool, $arguments);

            if ([] !== $errors) {
                $this->appendEvent(
                    $run,
                    RunEventType::ToolValidationError,
                    ['tool' => $toolName, 'detail' => implode('; ', $errors), 'attempt' => $attempt],
                    errorClass: ErrorClass::InvalidArguments,
                    attemptNo: $attempt,
                );
                $this->em->flush();

                return [
                    'toolCallId' => $callId,
                    'content' => $this->errorFeedbackJson('invalid_arguments', $toolName, implode('; ', $errors), $attempt),
                ];
            }

            $this->appendEvent(
                $run,
                RunEventType::ToolCall,
                ['tool' => $toolName, 'arguments' => $arguments, 'attempt' => $attempt],
                attemptNo: $attempt,
            );

            try {
                $payload = $this->executor->execute($tool, $arguments);
            } catch (ToolExecutionException $e) {
                $this->appendEvent(
                    $run,
                    RunEventType::ToolResult,
                    ['tool' => $toolName, 'detail' => $e->getMessage(), 'attempt' => $attempt],
                    errorClass: $e->errorClass,
                    attemptNo: $attempt,
                );
                $this->em->flush();

                // Circuit breaker: same tool + same error class, N times
                // (§5.2) → needs_attention. The run STOPS — feeding the
                // breaker back to the model would just loop it.
                if ($this->bumpFailureCount($state, $toolName, $e->errorClass) >= $state->circuitBreakerThreshold) {
                    $this->tripCircuitBreaker($run, $toolName, $e->errorClass);
                    $this->em->flush();

                    throw new RunTerminatedException(\sprintf('circuit breaker tripped on tool "%s" (%s)', $toolName, $e->errorClass->value), $e->errorClass);
                }

                if ($attempt <= $state->toolRetries) {
                    $this->appendEvent(
                        $run,
                        RunEventType::ToolRetry,
                        ['tool' => $toolName, 'attempt' => $attempt],
                        errorClass: $e->errorClass,
                        attemptNo: $attempt,
                    );
                    $this->em->flush();
                    ++$attempt;

                    continue;
                }

                return [
                    'toolCallId' => $callId,
                    'content' => $this->errorFeedbackJson('tool_error', $toolName, $e->getMessage(), $attempt),
                ];
            }

            $cappedContent = $this->context->capToolResult($payload['content'] ?? '');

            $this->appendEvent(
                $run,
                RunEventType::ToolResult,
                ['tool' => $toolName, 'content' => $cappedContent, 'isError' => false],
                attemptNo: $attempt,
                durationMs: (int) ($payload['durationMs'] ?? 0),
            );
            $this->em->flush();

            // Success clears the tool's circuit-breaker streak.
            unset($state->failureCounts[$this->failureKey($toolName, ErrorClass::ServerError)]);

            return ['toolCallId' => $callId, 'content' => $cappedContent];
        }
    }

    private function tripCircuitBreaker(Run $run, string $toolName, ErrorClass $errorClass): void
    {
        $run->markNeedsAttention($errorClass);
        $this->appendEvent(
            $run,
            RunEventType::CircuitBreaker,
            ['tool' => $toolName, 'errorClass' => $errorClass->value],
            errorClass: $errorClass,
        );
        $this->em->flush();
        $this->logger->error('Run {run}: circuit breaker tripped on {tool}.', [
            'run' => $run->getId(),
            'tool' => $toolName,
        ]);
    }

    private function bumpFailureCount(LoopState $state, string $toolName, ErrorClass $errorClass): int
    {
        $key = $this->failureKey($toolName, $errorClass);
        $state->failureCounts[$key] = ($state->failureCounts[$key] ?? 0) + 1;

        return $state->failureCounts[$key];
    }

    private function failureKey(string $toolName, ErrorClass $errorClass): string
    {
        return $toolName.'|'.$errorClass->value;
    }

    /**
     * Structured, actionable error fed back to the model so the loop
     * self-corrects (SPEC §5.1).
     *
     * @return string JSON
     */
    private function errorFeedbackJson(string $error, string $tool, string $detail, ?int $attempt = null): string
    {
        $payload = [
            'error' => $error,
            'tool' => $tool,
            'detail' => $detail,
        ];

        if (null !== $attempt) {
            $payload['attempt'] = $attempt;
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendEvent(
        Run $run,
        RunEventType $type,
        array $payload,
        ?ErrorClass $errorClass = null,
        ?int $attemptNo = null,
        ?int $durationMs = null,
    ): RunEvent {
        $event = new RunEvent($type);
        $event->setPayload($payload);

        if (null !== $errorClass) {
            $event->setErrorClass($errorClass);
        }
        if (null !== $attemptNo) {
            $event->setAttemptNo($attemptNo);
        }
        if (null !== $durationMs) {
            $event->setDurationMs($durationMs);
        }

        $run->appendEvent($event);
        $this->em->persist($event);

        return $event;
    }
}
