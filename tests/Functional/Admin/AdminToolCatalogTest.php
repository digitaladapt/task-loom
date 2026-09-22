<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\McpServer;
use App\Entity\ServerProtocol;
use App\Entity\Tool;
use App\Repository\ToolRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The tool-catalog admin surface (SPEC §8): the catalog view, the known-tag
 * list, and per-tool tag editing — the fix path for tasks whose tags
 * resolve to nothing ('core': tag the right tools, re-check the preview,
 * enable).
 */
final class AdminToolCatalogTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (assigned in setUp)

    #[\Override]
    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->setServerParameter('PHP_AUTH_USER', 'admin');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test-admin-password');

        $em = $this->em();
        $em->createQuery('DELETE FROM App\Entity\Tool')->execute();
        $em->createQuery('DELETE FROM App\Entity\McpServer')->execute();
        $em->flush();
        $em->clear();
    }

    public function testListShowsToolsAndKnownTags(): void
    {
        $tool = $this->makeTool('get_forecast', ['weather']);

        $this->client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('get_forecast', $content);
        self::assertStringContainsString('Known tags', $content);
        self::assertStringContainsString('weather', $content);
    }

    public function testSetTagsUpdatesToolTags(): void
    {
        $tool = $this->makeTool('get_forecast', ['weather']);

        // Visit first (real session → real CSRF token), then submit.
        $crawler = $this->client->request('GET', '/tools');
        $form = $crawler->filter('form[action*="/tools/'.$tool->getId().'/tags"]')->form();
        $form['tags'] = 'core, weather ,'; // trailing comma + spaces: normalization is the point

        $this->client->submit($form);

        self::assertResponseRedirects();
        $fresh = $this->tools()->find($tool->getId());
        self::assertNotNull($fresh);
        self::assertSame(['core', 'weather'], $fresh->getTags());
    }

    public function testSetTagsRejectsMissingToken(): void
    {
        $tool = $this->makeTool('get_forecast', ['weather']);

        $this->client->request('POST', '/tools/'.$tool->getId().'/tags', ['tags' => 'core']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $fresh = $this->tools()->find($tool->getId());
        self::assertNotNull($fresh);
        self::assertSame(['weather'], $fresh->getTags());
    }

    public function testSetTagsOnMissingToolIs404(): void
    {
        $this->client->request('POST', '/tools/99999/tags', ['tags' => 'core', '_token' => 'irrelevant']);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param list<string> $tags
     */
    private function makeTool(string $name, array $tags): Tool
    {
        $em = $this->em();
        $server = new McpServer('weather-srv', 'https://weather.example/mcp', ServerProtocol::Mcp);
        $em->persist($server);

        $tool = new Tool($server, $name, 'Get the forecast', ['type' => 'object'], $tags);
        $em->persist($tool);
        $em->flush();

        return $tool;
    }

    private function tools(): ToolRepository
    {
        $tools = $this->client->getContainer()->get(ToolRepository::class);
        \assert($tools instanceof ToolRepository);

        return $tools;
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);

        return $em;
    }
}
