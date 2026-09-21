<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class AssetMapperTest extends WebTestCase
{
    public function testBaseTemplateRendersImportmap(): void
    {
        $client = static::createClient();

        // There is no HTML route yet in v1 (health/ready are JSON), so render
        // the real base template through Twig to prove the importmap wiring.
        $twig = static::getContainer()->get(Environment::class);
        \assert($twig instanceof Environment);

        $html = $twig->render('base.html.twig', ['title' => 'test', 'body' => '']);

        self::assertStringContainsString('importmap', $html);
        self::assertStringContainsString('/assets/app-', $html, 'AssetMapper should emit a hashed entrypoint script.');
        // The stylesheet is now imported by app.js, so the mapper emits a hashed
        // <link> (or importmap entry keyed by its logical path) — not the legacy
        // unhashed asset() link. Assert on the tag, not the bare string: the
        // logical path "styles/app.css" legitimately appears in the importmap.
        self::assertStringNotContainsString('href="/styles/app.css"', $html, 'Legacy unhashed asset() CSS link should be gone.');
        self::assertStringContainsString('href="/assets/styles/app-', $html, 'AssetMapper should emit a hashed stylesheet link.');
    }
}
