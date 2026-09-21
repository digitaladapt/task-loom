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
        self::assertStringNotContainsString('styles/app.css', $html, 'Legacy plain asset() CSS link should be gone.');
    }
}
