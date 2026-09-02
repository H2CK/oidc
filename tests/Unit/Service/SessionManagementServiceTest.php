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
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class SessionManagementServiceTest extends TestCase {
    /** @var array<string,mixed> */
    private array $sessionData = [];
    /** @var array<string,string> */
    private array $cookies = [];
    private ISession $session;
    private IRequest $request;
    private ISecureRandom $secureRandom;
    private ClientMapper $clientMapper;
    private RedirectUriMapper $redirectUriMapper;
    private SessionManagementService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->sessionData = [];
        $this->cookies = [];
        $this->session = $this->createSessionMock($this->sessionData);
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getCookie')->willReturnCallback(fn (string $key) => $this->cookies[$key] ?? null);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $this->service = new SessionManagementService(
            $this->session,
            $this->secureRandom,
            $this->clientMapper,
            $this->redirectUriMapper,
            $this->request,
        );
    }

    public function testGenerateAndCheckSessionStateForRegisteredOrigin(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat('O', 64)
        );

        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback?foo=bar');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\.[A-Za-z0-9]{32}$/', $state);
        $this->assertSame('unchanged', $this->service->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testGeneratedBrowserStateIsSentAsSameSiteNoneCookieAndWorksInNewRequest(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat('O', 64)
        );

        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $response = new Response();
        $this->service->applyBrowserStateCookie($response);
        $responseCookies = $response->getCookies();

        $this->assertArrayHasKey(SessionManagementService::OP_BROWSER_STATE_COOKIE, $responseCookies);
        $cookie = $responseCookies[SessionManagementService::OP_BROWSER_STATE_COOKIE];
        $this->assertSame(str_repeat('O', 64), $cookie['value']);
        $this->assertSame('None', $cookie['sameSite']);

        // Simulate check_session_iframe in a separate cross-site request: the
        // normal Nextcloud PHP session is absent, only the dedicated cookie is sent.
        $iframeSessionData = [];
        $iframeSession = $this->createSessionMock($iframeSessionData);
        $iframeRequest = $this->createMock(IRequest::class);
        $iframeRequest->method('getCookie')->willReturnCallback(
            static fn (string $key) => $key === SessionManagementService::OP_BROWSER_STATE_COOKIE ? str_repeat('O', 64) : null
        );
        $iframeService = new SessionManagementService(
            $iframeSession,
            $this->secureRandom,
            $this->clientMapper,
            $this->redirectUriMapper,
            $iframeRequest,
        );

        $this->assertSame('unchanged', $iframeService->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testExistingServerSideStateIsReemittedWhenBrowserCookieIsMissing(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat('O', 64)
        );

        // First request creates the OP browser state and stores a server-side copy.
        $this->service->generateSessionState('client-1', 'https://rp.example/callback');

        // Simulate a later top-level authentication request where the PHP
        // session survived but the dedicated browser cookie did not.
        $requestWithoutCookie = $this->createMock(IRequest::class);
        $requestWithoutCookie->method('getCookie')->willReturn(null);
        $service = new SessionManagementService(
            $this->session,
            $this->secureRandom,
            $this->clientMapper,
            $this->redirectUriMapper,
            $requestWithoutCookie,
        );

        $service->generateSessionState('client-1', 'https://rp.example/callback');
        $response = new Response();
        $service->applyBrowserStateCookie($response);

        $this->assertSame(
            str_repeat('O', 64),
            $response->getCookies()[SessionManagementService::OP_BROWSER_STATE_COOKIE]['value']
        );
        $this->assertSame(
            'None',
            $response->getCookies()[SessionManagementService::OP_BROWSER_STATE_COOKIE]['sameSite']
        );
    }

    public function testCheckWithoutOpBrowserCookieInSeparateRequestReturnsChanged(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat('O', 64)
        );
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');

        $iframeSessionData = [];
        $iframeRequest = $this->createMock(IRequest::class);
        $iframeRequest->method('getCookie')->willReturn(null);
        $iframeService = new SessionManagementService(
            $this->createSessionMock($iframeSessionData),
            $this->secureRandom,
            $this->clientMapper,
            $this->redirectUriMapper,
            $iframeRequest,
        );

        $this->assertSame('changed', $iframeService->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testResetBrowserStateChangesPreviouslyIssuedSessionStateAndCookie(): void {
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
        $response = new Response();
        $this->service->applyBrowserStateCookie($response);
        $this->assertSame(
            str_repeat('B', 64),
            $response->getCookies()[SessionManagementService::OP_BROWSER_STATE_COOKIE]['value']
        );
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

    /** @param array<string,mixed> $data */
    private function createSessionMock(array &$data): ISession {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static fn (string $key) => $data[$key] ?? null);
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$data): void {
            $data[$key] = $value;
        });
        $session->method('remove')->willReturnCallback(static function (string $key) use (&$data): void {
            unset($data[$key]);
        });
        return $session;
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
