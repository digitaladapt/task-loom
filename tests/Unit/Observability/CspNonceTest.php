<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\Observability\CspNonce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The per-request CSP nonce (see CspNonce for the bug that motivated it).
 *
 * Two behaviours are load-bearing, and both were wrong in the state this
 * fixed:
 *
 *  - **The same nonce for the header and the tags.** The policy is written by
 *    the response listener and the attribute is written by the template; if
 *    they disagree, the browser blocks the app's own scripts.
 *  - **Lazy minting.** `current()` must not conjure a nonce, or every JSON
 *    response would advertise a nonce it never used — and a response that
 *    emits no inline script has no business loosening its own policy.
 */
final class CspNonceTest extends TestCase
{
    private function stack(?Request $request): RequestStack
    {
        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        return $stack;
    }

    public function testValueMintsAndIsStableForTheRequest(): void
    {
        $nonce = new CspNonce($this->stack(new Request()));

        $first = $nonce->value();
        $second = $nonce->value();

        self::assertNotSame('', $first);
        self::assertSame($first, $second, 'the header and the tags must receive the same nonce');
    }

    public function testValueIsBase64UrlSafeAndHasEnoughEntropy(): void
    {
        $nonce = new CspNonce($this->stack(new Request()))->value();

        // 16 random bytes → 22 base64url characters, no padding: safe to drop
        // into an attribute value without escaping, and far beyond guessable.
        self::assertSame(22, \strlen($nonce));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $nonce);
    }

    public function testDifferentRequestsGetDifferentNonces(): void
    {
        $a = new CspNonce($this->stack(new Request()))->value();
        $b = new CspNonce($this->stack(new Request()))->value();

        self::assertNotSame($a, $b);
    }

    /**
     * The whole point of `current()`: the header subscriber asks whether a
     * nonce exists, and must not create one by asking.
     */
    public function testCurrentDoesNotMint(): void
    {
        $request = new Request();
        $nonce = new CspNonce($this->stack($request));

        self::assertNull($nonce->current(), 'no nonce has been asked for yet');

        $minted = $nonce->value();
        self::assertSame($minted, $nonce->current(), 'once minted, current() reports it');
    }

    public function testMintCachesOnTheRequestNotTheService(): void
    {
        $request = new Request();
        $first = new CspNonce($this->stack($request));

        $minted = $first->value();

        // A second instance over the same request sees the same nonce: the
        // cache lives on the request, so a service rebuilt mid-request (or a
        // sub-request sharing the stack) cannot produce a mismatched nonce.
        $second = new CspNonce($this->stack($request));
        self::assertSame($minted, $second->value());
    }

    /**
     * CLI, workers, and mail rendering have no request — the helpers must
     * return "no nonce" rather than throwing.
     */
    public function testWithoutARequestThereIsNoNonce(): void
    {
        $nonce = new CspNonce($this->stack(null));

        self::assertSame('', $nonce->value());
        self::assertNull($nonce->current());
    }
}
