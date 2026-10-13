<?php

declare(strict_types=1);

namespace App\Llm;

use App\Entity\ErrorClass;

/**
 * Why a streamed generation ended before the endpoint finished it.
 *
 * The client stops a request itself in four situations — the model's output
 * started repeating, it never started at all, it went quiet mid-way, or it
 * was still producing at the hard cap — and the endpoint can also fail
 * mid-stream. Each case is a different operator story ("too much context" vs
 * "wedged server" vs "the model is looping" vs "the run simply asked for a
 * lot of output"), so each is its own reason and its own error class rather
 * than one bucket labelled "timeout".
 */
enum LlmStopReason: string
{
    /** The output started repeating itself — generation was cut at detection. */
    case LoopDetected = 'loop_detected';

    /** No output at all within TASKLOOM_LLM_FIRST_TOKEN_TIMEOUT — prefill/queue, never started. */
    case FirstTokenTimeout = 'first_token_timeout';

    /** Output was flowing, then stopped for TASKLOOM_LLM_SILENCE_TIMEOUT seconds mid-stream. */
    case SilenceTimeout = 'silence_timeout';

    /** Output was still flowing at TASKLOOM_LLM_MAX_DURATION — too much output, cut at the cap. */
    case DurationExceeded = 'duration_exceeded';

    /** The transport or the endpoint failed mid-generation; the partial output was kept. */
    case StreamError = 'stream_error';

    /** The stream carried bytes that are not a valid completion — the partial output was kept. */
    case Malformed = 'malformed';

    public function errorClass(): ErrorClass
    {
        return match ($this) {
            self::LoopDetected => ErrorClass::LlmLoopDetected,
            self::FirstTokenTimeout => ErrorClass::LlmFirstTokenTimeout,
            self::SilenceTimeout => ErrorClass::LlmSilenceTimeout,
            self::DurationExceeded => ErrorClass::LlmDurationExceeded,
            self::StreamError => ErrorClass::LlmError,
            self::Malformed => ErrorClass::LlmMalformedResponse,
        };
    }
}
