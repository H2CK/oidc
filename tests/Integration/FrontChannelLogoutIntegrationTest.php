<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCP\AppFramework\App;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FrontChannelLogoutIntegrationTest extends TestCase {
    private ClientMapper $clientMapper;
    private IDBConnection $db;
    private ?Client $client = null;

    protected function setUp(): void {
        parent::setUp();
        $container = (new App('oidc'))->getContainer();
        $this->clientMapper = $container->query(ClientMapper::class);
        $this->db = $container->query(IDBConnection::class);
    }

    protected function tearDown(): void {
        if ($this->client !== null && $this->client->getId() !== null) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('oidc_redirect_uris')
                ->where($qb->expr()->eq('client_id', $qb->createNamedParameter($this->client->getId())));
            $qb->executeStatement();

            $qb = $this->db->getQueryBuilder();
            $qb->delete('oidc_clients')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->client->getId())));
            $qb->executeStatement();
        }
        parent::tearDown();
    }

    public function testFrontChannelMetadataPersistsAndIsUsedForLogoutNotification(): void {
        $identifier = str_pad('integration-frontchannel-' . bin2hex(random_bytes(6)), 32, 'x');
        $client = new Client(
            'Front-Channel Integration RP',
            ['https://rp.example/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            '',
            '',
            false,
            false,
            null,
            null,
            false,
            'https://rp.example/frontchannel?source=op',
            true,
        );
        $client->setClientIdentifier($identifier);
        $client->setSecret(str_repeat('s', 32));
        $this->client = $this->clientMapper->insert($client);

        $persisted = $this->clientMapper->getByIdentifier($identifier);
        $this->assertSame('https://rp.example/frontchannel?source=op', $persisted->getFrontchannelLogoutUri());
        $this->assertTrue($persisted->getFrontchannelLogoutSessionRequired());

        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getWebroot')->willReturn('/cloud');
        $service = new FrontChannelLogoutService(
            $this->clientMapper,
            $request,
            $urlGenerator,
            $this->createMock(LoggerInterface::class),
        );

        $this->assertSame([
            'https://rp.example/frontchannel?source=op&iss=https%3A%2F%2Fnextcloud.example%2Fcloud&sid=sid-integration',
        ], $service->getLogoutUris([(string)$this->client->getId() => 'sid-integration']));
    }
}
