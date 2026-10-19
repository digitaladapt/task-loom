<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\Type\Utf8JsonType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\SerializationFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The backstop: whatever a malformed byte came from, a `json` write must not
 * fail a live reply.
 *
 * The two halves of the contract are tested separately on purpose. Scrubbing
 * is the behaviour we want; *not* scrubbing everything is equally important,
 * because an unencodable NAN or an infinite recursion is a real bug and
 * quietly mangling it into a stored value would hide it until much later.
 */
final class Utf8JsonTypeTest extends TestCase
{
    private Utf8JsonType $type; // @phpstan-ignore property.uninitialized (assigned in setUp)

    private AbstractPlatform $platform; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        $this->type = new Utf8JsonType();

        // A stub, not a mock: the platform carries no behaviour here. Encoding
        // is platform-independent, so nothing is expected of it.
        $this->platform = self::createStub(AbstractPlatform::class);
    }

    #[Test]
    public function validPayloadsEncodeUnchanged(): void
    {
        $value = ['tool' => 'weather', 'content' => 'café — naïve … 漢字 😀'];

        $encoded = $this->type->convertToDatabaseValue($value, $this->platform);

        self::assertSame(json_encode($value), $encoded);
    }

    #[Test]
    public function aMalformedStringInThePayloadIsScrubbedRatherThanFailingTheWrite(): void
    {
        $value = ['tool' => 'search', 'content' => "before \xC3 after"];

        // The plain type refuses it — that is the production error.
        $plain = new \Doctrine\DBAL\Types\JsonType();
        try {
            $plain->convertToDatabaseValue($value, $this->platform);
            self::fail('the stock JsonType was expected to reject this payload');
        } catch (SerializationFailed) {
            // expected: this is the shape of the bug
        }

        $encoded = $this->type->convertToDatabaseValue($value, $this->platform);

        self::assertNotFalse(json_decode($encoded, true));

        $decoded = json_decode($encoded, true);
        self::assertStringContainsString('before', $decoded['content']);
        self::assertStringContainsString('after', $decoded['content']);
        self::assertTrue(mb_check_encoding($decoded['content'], 'UTF-8'));
    }

    #[Test]
    public function malformedBytesNestedInThePayloadAreScrubbedToo(): void
    {
        $value = [
            'outer' => [
                'inner' => ["\x80", 'fine'],
                'map' => ['key'."\xE2\x80" => 'value'],
            ],
        ];

        $encoded = $this->type->convertToDatabaseValue($value, $this->platform);

        self::assertNotFalse(json_decode($encoded, true));
    }

    #[Test]
    public function nullIsPassedStraightThrough(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform)); // @phpstan-ignore staticMethod.alreadyNarrowedType (the contract under test)
    }

    /**
     * A payload that fails to encode for a reason scrubbing cannot fix must
     * still throw, with its original message intact.
     */
    #[Test]
    public function anEncodingFailureThatIsNotMalformedUtf8StillThrows(): void
    {
        $this->expectException(SerializationFailed::class);
        $this->expectExceptionMessage('Inf and NaN cannot be JSON encoded');

        $this->type->convertToDatabaseValue(['bad' => \INF], $this->platform);
    }
}
