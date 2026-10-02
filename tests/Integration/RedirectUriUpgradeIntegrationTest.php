<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Migration\Version0037Date20261002080000;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\RedirectUriUpgradeService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use Psr\Log\NullLogger;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class RedirectUriUpgradeIntegrationTest extends \Test\TestCase {
    public function testMigrationAddsExactAliasesReportsUnsafePatternsAndIsIdempotent(): void {
        $clients = Server::get(ClientMapper::class);
        $redirects = Server::get(RedirectUriMapper::class);
        $client = $clients->insert(new Client('migration fixture', ['https://RP.example', 'https://*/cb']));
        try {
            $schema = $this->createMock(ISchemaWrapper::class);
            $schema->method('hasTable')->willReturn(true);
            $config = $this->createMock(IAppConfig::class);
            $review = [];
            $config->method('setAppValueString')->willReturnCallback(function (string $key, string $value) use (&$review): bool {
                if ($key === 'redirect_uri_upgrade_review') { $review = json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
                return true;
            });
            $logger = new NullLogger();
            $migration = new Version0037Date20261002080000(Server::get(IDBConnection::class),
                new RedirectUriUpgradeService(new RedirectUriService($logger)), $config, $logger);
            $output = $this->createMock(IOutput::class);
            $migration->postSchemaChange($output, static fn () => $schema, []);
            $uris = array_map(static fn ($row): string => $row->getRedirectUri(), $redirects->getByClientId($client->getId()));
            $this->assertContains('https://RP.example', $uris);
            $this->assertContains('https://rp.example/', $uris);
            $this->assertContains('https://rp.example', $uris);
            $this->assertContains('https://*/cb', $uris);
            $this->assertNotEmpty(array_filter($review, static fn (array $row): bool => $row['client_id'] === $client->getId()));
            $migration->postSchemaChange($output, static fn () => $schema, []);
            $this->assertCount(count($uris), $redirects->getByClientId($client->getId()));
        } finally {
            $clients->delete($client);
        }
    }
}
