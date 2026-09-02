<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class SessionManagementServiceTest extends TestCase {
    /** @var array<string,mixed> */
    private array $sessionData = [];
    private ISession $session;
    private ISecureRandom $secureRandom;
    private ClientMapper $clientMapper;
    private RedirectUriMapper $redirectUriMapper;
    private SessionManagementService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->sessionData = [];
        $this->session = $this->createMock(ISession::class);
        $this->session->method('get')->willReturnCallback(fn (string $key) => $this->sessionData[$key] ?? null);
        $this->session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->sessionData[$key] = $value;
        });
        $this->session->method('remove')->willReturnCallback(function (string $key): void {
            unset($this->sessionData[$key]);
        });
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $this->service = new SessionManagementService(
            $this->session,
            $this->secureRandom,
            $this->clientMapper,
            $this->redirectUriMapper,
        );
    }

    public function testGenerateAndCheckSessionStateForRegisteredOrigin(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat('O', 64)
        );

        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback?foo=bar');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\\.[A-Za-z0-9]{32}$/', $state);
        $this->assertSame('unchanged', $this->service->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testResetBrowserStateChangesPreviouslyIssuedSessionState(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $opbsCount = 0;
        $this->secureRandom->method('generate')->willReturnCallback(static function (int $length) use (&$opbsCount): string {
            if ($length === 32) {
                return str_repeat('S', 32);
            }
            $opbsCount++;
            return str_repeat($opbsCount === 1 ? 'A' : 'B', 64);
        });

        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $this->assertSame('unchanged', $this->service->checkSessionState('client-1', 'https://rp.example', $state));

        $this->service->resetBrowserState();

        $this->assertSame('changed', $this->service->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testCheckRejectsMalformedStateAndUnregisteredOrigin(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat('O', 64)
        );
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');

        $this->assertSame('error', $this->service->checkSessionState('client-1', 'not-an-origin', $state));
        $this->assertSame('error', $this->service->checkSessionState('client-1', 'https://other.example', $state));
        $this->assertSame('error', $this->service->checkSessionState('client-1', 'https://rp.example', 'not-a-session-state'));
        $this->assertSame('error', $this->service->checkSessionState('client-1', 'https://rp.example', str_repeat('0', 64) . '.bad salt'));
    }

    public function testCheckReturnsErrorForUnknownClient(): void {
        $this->clientMapper->method('getByIdentifier')->willThrowException(new \RuntimeException('not found'));
        $this->assertSame(
            'error',
            $this->service->checkSessionState('missing', 'https://rp.example', str_repeat('0', 64) . '.' . str_repeat('S', 32))
        );
    }

    public function testGetOriginNormalizesWebOriginsAndRejectsNonWebUris(): void {
        $this->assertSame('https://rp.example', SessionManagementService::getOrigin('HTTPS://RP.EXAMPLE/callback?x=1'));
        $this->assertSame('https://rp.example:8443', SessionManagementService::getOrigin('https://RP.EXAMPLE:8443/callback'));
        $this->assertSame('http://localhost:8080', SessionManagementService::getOrigin('http://localhost:8080/cb'));
        $this->assertNull(SessionManagementService::getOrigin('com.example.app:/callback'));
        $this->assertNull(SessionManagementService::getOrigin('/relative/path'));
    }

    private function configureClientAndRedirect(string $identifier, int $id, string $redirect): void {
        $client = new Client();
        $client->setId($id);
        $client->setClientIdentifier($identifier);
        $redirectUri = new RedirectUri();
        $redirectUri->setClientId($id);
        $redirectUri->setRedirectUri($redirect);

        $this->clientMapper->method('getByIdentifier')->with($identifier)->willReturn($client);
        $this->redirectUriMapper->method('getByClientId')->with($id)->willReturn([$redirectUri]);
    }
}
