<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\App;
use OCP\AppFramework\Http\Response;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class SessionManagementIntegrationTest extends TestCase {
    private ClientMapper $clientMapper;
    private SessionManagementService $sessionManagementService;
    private IDBConnection $db;
    private ?Client $client = null;

    protected function setUp(): void {
        parent::setUp();
        $container = (new App('oidc'))->getContainer();
        $this->clientMapper = $container->query(ClientMapper::class);
        $this->sessionManagementService = $container->query(SessionManagementService::class);
        $this->db = $container->query(IDBConnection::class);
        $this->sessionManagementService->resetBrowserState();
    }

    protected function tearDown(): void {
        $this->sessionManagementService->resetBrowserState();
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

    public function testPersistedClientRedirectUriParticipatesInSessionStateChecking(): void {
        $identifier = 'integration-session-' . bin2hex(random_bytes(8));
        $client = new Client('Session Management Integration RP', ['https://rp.example/callback']);
        $client->setClientIdentifier(str_pad($identifier, 32, 'x'));
        $client->setSecret(str_repeat('s', 32));
        $this->client = $this->clientMapper->insert($client);

        $state = $this->sessionManagementService->generateSessionState(
            $this->client->getClientIdentifier(),
            'https://rp.example/callback'
        );

        $response = new Response();
        $this->sessionManagementService->applyBrowserStateCookie($response);
        $cookies = $response->getCookies();
        $this->assertArrayHasKey(SessionManagementService::OP_BROWSER_STATE_COOKIE, $cookies);
        $this->assertSame('None', $cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE]['sameSite']);

        $this->assertSame(
            'unchanged',
            $this->sessionManagementService->checkSessionState(
                $this->client->getClientIdentifier(),
                'https://rp.example',
                $state
            )
        );
        $this->assertSame(
            'error',
            $this->sessionManagementService->checkSessionState(
                $this->client->getClientIdentifier(),
                'https://unregistered.example',
                $state
            )
        );

        $this->sessionManagementService->resetBrowserState();
        $this->assertSame(
            'changed',
            $this->sessionManagementService->checkSessionState(
                $this->client->getClientIdentifier(),
                'https://rp.example',
                $state
            )
        );
    }
}
