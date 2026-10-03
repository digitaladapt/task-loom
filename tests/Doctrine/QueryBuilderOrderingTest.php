<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use PHPUnit\Framework\TestCase;

/**
 * Guard: no string sort direction may reach QueryBuilder::orderBy()/addOrderBy().
 *
 * Doctrine 3.x deprecated `->orderBy('e.seq', 'ASC')` in favour of a
 * `\SortDirection` enum case (doctrine/orm#11313), and the project has a
 * `SortDirection` available with no import — it is the PHP 8.6 global enum,
 * polyfilled by symfony/polyfill-php86 on anything older.
 *
 * Two things made this drift, and both are the reason this file exists rather
 * than a note in a review checklist:
 *
 *  1. The result is identical. SQLite accepts either form, so a test that
 *     asserts on *rows* cannot tell the two apart. Only the deprecation
 *     distinguishes them.
 *  2. Until this change, `phpunit.dist.xml` set
 *     ignoreIndirectDeprecations="true", which silently disables
 *     `failOnDeprecation` for a vendor deprecation triggered by application
 *     code — the only place this deprecation is ever triggered from. Two
 *     repository tests claimed failOnDeprecation guarded it; it did not, and
 *     fourteen call sites accumulated behind that false confidence.
 *
 * So the guard is a *source* check, deliberately independent of the
 * deprecation plumbing: it fails on the text that would regress, whether or
 * not deprecations happen to be enabled or attributed to app code. It is the
 * same posture as DeploymentContractTest — a file-level assertion that catches
 * drift in review, on a machine that need not be able to boot the app.
 */
final class QueryBuilderOrderingTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * A two-argument orderBy()/addOrderBy() call whose second argument is a
     * quoted 'ASC'/'DESC' string. The backreference keeps the closing quote
     * paired with the opening one.
     *
     * Deliberately specific:
     *  - requires the `,` — a one-argument `->orderBy('e.seq')` is fine (it
     *    defaults to Ascending with no deprecation);
     *  - requires the quoted direction to be the *last* argument before the
     *    closing paren (an optional trailing comma aside), so a `'DESC'`
     *    appearing earlier in the argument list does not match;
     *  - is anchored on the method name, so DQL prose such as
     *    `'… ORDER BY r.id ASC'` is not caught;
     *  - cannot match the enum form, which has no quotes.
     */
    private const string DEPRECATED_ORDER =
        '/\b(?:add)?orderBy\s*\([^;]*?,\s*([\'"])(?:ASC|DESC)\1\s*,?\s*\)/i';

    public function testNoQueryBuilderCallPassesASortDirectionAsAString(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(self::ROOT.'/src') as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                self::fail(sprintf('Could not read %s.', $file));
            }

            if (preg_match(self::DEPRECATED_ORDER, $contents) === 1) {
                $offenders[] = substr($file, \strlen(self::ROOT) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Passing a string as \$order to QueryBuilder::orderBy()/addOrderBy() is "
            ."deprecated (doctrine/orm#11313). Use \\SortDirection::Ascending / "
            ."::Descending instead — the global enum needs no import.\nOffending files:\n  - "
            .implode("\n  - ", $offenders),
        );
    }

    /**
     * The guard is only worth trusting if its pattern actually bites, and only
     * cheap if it leaves correct code alone. Pin both here, so a later edit to
     * the pattern that quietly stops matching is itself a failure.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function orderingSamples(): iterable
    {
        yield 'string ASC (deprecated)' => ["->orderBy('r.id', 'ASC')", true];
        yield 'string DESC (deprecated)' => ["->orderBy('r.finishedAt', 'DESC')", true];
        yield 'string, double-quoted (deprecated)' => ['->orderBy("r.id", "ASC")', true];
        yield 'addOrderBy string (deprecated)' => ["->addOrderBy('t.id', 'ASC')", true];
        yield 'multiline with trailing comma (deprecated)' => ["->orderBy(\n    'e.seq',\n    'DESC',\n)", true];
        yield 'multiline without trailing comma (deprecated)' => ["->orderBy(\n    'e.seq',\n    'DESC'\n)", true];

        yield 'enum form (required)' => ["->orderBy('r.id', \\SortDirection::Ascending)", false];
        yield 'enum Descending (required)' => ["->orderBy('r.id', \\SortDirection::Descending)", false];
        yield 'enum addOrderBy (required)' => ["->addOrderBy('t.id', \\SortDirection::Ascending)", false];
        yield 'single-argument (defaults to Ascending)' => ["->orderBy('e.seq')", false];
        yield 'three non-string arguments' => ['->orderBy($expr, $direction)', false];
        yield 'DQL prose is not a call' => ["'SELECT r FROM Run r ORDER BY r.id ASC'", false];
        yield 'unrelated method with a direction string' => ["->setParameter('dir', 'DESC')", false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orderingSamples')]
    public function testPatternDiscriminatesDeprecatedFromCorrect(
        string $snippet,
        bool $expectedToMatch,
    ): void {
        self::assertSame(
            $expectedToMatch,
            preg_match(self::DEPRECATED_ORDER, $snippet) === 1,
            sprintf('Pattern misjudged: %s', $snippet),
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);

            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        self::assertNotSame([], $files, sprintf('No PHP files found under %s.', $directory));

        return $files;
    }
}
