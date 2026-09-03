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
use OCA\OIDCIdentityProvider\Service\CredentialService;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SessionManagementServiceTest extends TestCase {
    /** @var array{0:string,1:string,2:string}|null */
    private static ?array $rsaKeyMaterial = null;
    /** @var array<string,mixed> */
    private array $sessionData = [];
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,mixed> */
    private array $cacheData = [];
    private ISession $session;
    private IRequest $request;
    private IOutput $output;
    private ISecureRandom $secureRandom;
    private ClientMapper $clientMapper;
    private RedirectUriMapper $redirectUriMapper;
    private RedirectUriService $redirectUriService;
    private CredentialService $credentialService;
    private IAppConfig $appConfig;
    private ICacheFactory $cacheFactory;
    private IConfig $config;
    private SessionManagementService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->sessionData = [];
        $this->cookies = [];
        $this->cacheData = [];
        $this->session = $this->createSessionMock($this->sessionData);
        $this->request = $this->createRequestMock($this->cookies, 'https');
        $this->output = $this->createMock(IOutput::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $this->redirectUriService = new RedirectUriService($this->createMock(LoggerInterface::class));
        $this->credentialService = $this->createMock(CredentialService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        self::$rsaKeyMaterial ??= $this->createRsaKeyMaterial();
        [$privateKey, $modulus, $exponent] = self::$rsaKeyMaterial;
        $this->credentialService->method('getPrivateKey')->willReturn($privateKey);
        $this->appConfig->method('getAppValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => match ($key) {
                'public_key_n' => $modulus,
                'public_key_e' => $exponent,
                default => $default,
            }
        );
        $this->cacheFactory = $this->createCacheFactoryMock($this->cacheData);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getSystemValueInt')->with('session_lifetime', 86400)->willReturn(3600);
        $this->service = $this->newService($this->session, $this->request);
    }

    public function testGenerateAndCheckSessionStateForRegisteredOrigin(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');

        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $this->assertMatchesRegularExpression('/^3\.[A-Za-z0-9_-]+\.[a-f0-9]{64}\.[A-Za-z0-9]{32}\.[A-Za-z0-9_-]{64,2048}$/', $state);
        $parsed = SessionManagementService::parseSessionState($state);
        $this->assertSame('https://rp.example', $parsed['origin'] ?? null);

        $this->cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE] = str_repeat('O', 64);
        $this->assertSame('unchanged', $this->service->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testMissingThirdPartyCookieReturnsErrorToAvoidReauthLoop(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');

        $iframeSessionData = [];
        $iframeCookies = [];
        $iframeService = $this->newService(
            $this->createSessionMock($iframeSessionData),
            $this->createRequestMock($iframeCookies, 'https')
        );

        $this->assertSame('error', $iframeService->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testInvalidatedBrowserStateReturnsChangedEvenWhenStaleCookieRemains(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $this->cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE] = str_repeat('O', 64);
        $this->assertSame('unchanged', $this->service->checkSessionState('client-1', 'https://rp.example', $state));

        $this->service->invalidateCurrentBrowserState();

        $this->assertSame('changed', $this->service->checkSessionState('client-1', 'https://rp.example', $state));
        $this->assertFalse($this->service->isBrowserStateActiveForIframe());
    }

    public function testGeneratedBrowserStateIsSentAsSameSiteNoneCookieAndWorksInNewRequest(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $this->output->expects($this->once())
            ->method('setCookie')
            ->with(
                SessionManagementService::OP_BROWSER_STATE_COOKIE,
                str_repeat('O', 64),
                $this->greaterThan(time()),
                '/',
                null,
                true,
                false,
                'None'
            );
        $this->service->applyBrowserStateCookie(new Response());

        $iframeSessionData = [];
        $iframeCookies = [SessionManagementService::OP_BROWSER_STATE_COOKIE => str_repeat('O', 64)];
        $iframeService = $this->newService(
            $this->createSessionMock($iframeSessionData),
            $this->createRequestMock($iframeCookies, 'https')
        );
        $this->assertSame('unchanged', $iframeService->checkSessionState('client-1', 'https://rp.example', $state));
    }

    public function testResetBrowserStateInvalidatesOldStateAndEmitsNewCookie(): void {
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
        $this->cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE] = str_repeat('A', 64);

        $this->service->resetBrowserState();

        $this->assertSame('changed', $this->service->checkSessionState('client-1', 'https://rp.example', $state));
        $this->output->expects($this->once())
            ->method('setCookie')
            ->with(
                SessionManagementService::OP_BROWSER_STATE_COOKIE,
                str_repeat('B', 64),
                $this->greaterThan(time()),
                '/',
                null,
                true,
                false,
                'None'
            );
        $this->service->applyBrowserStateCookie(new Response());
    }

    public function testCheckRejectsOriginEmbeddedForDifferentRp(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $this->cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE] = str_repeat('O', 64);

        $this->assertSame('error', $this->service->checkSessionState('client-1', 'https://other.example', $state));
        $this->assertSame('error', $this->service->checkSessionState('client-1', 'not-an-origin', $state));
        $this->assertSame('error', $this->service->checkSessionState('client-1', 'https://rp.example', 'bad-state'));
    }

    public function testGenerateRejectsRedirectUriThatIsNotRegisteredForClient(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->generateSessionState('client-1', 'https://evil.example/callback');
    }

    public function testGenerateAcceptsConcreteRedirectMatchingRegisteredWildcard(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://*.example.com/callback/*');
        $this->configureRandom('O');

        $state = $this->service->generateSessionState('client-1', 'https://app.example.com/callback/complete');
        $parsed = SessionManagementService::parseSessionState($state);

        $this->assertSame('https://app.example.com', $parsed['origin'] ?? null);
        $this->assertNotSame('', $parsed['binding'] ?? '');
    }

    public function testSignedOriginBindingRejectsDifferentClientIdentifier(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $this->cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE] = str_repeat('O', 64);

        $this->assertSame('error', $this->service->checkSessionState('forged-client', 'https://rp.example', $state));
    }

    public function testTamperedOriginBindingIsRejected(): void {
        $this->configureClientAndRedirect('client-1', 7, 'https://rp.example/callback');
        $this->configureRandom('O');
        $state = $this->service->generateSessionState('client-1', 'https://rp.example/callback');
        $parts = explode('.', $state);
        $this->assertCount(5, $parts);
        $parts[4] = substr($parts[4], 0, -1) . ($parts[4][-1] === 'A' ? 'B' : 'A');
        $this->cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE] = str_repeat('O', 64);

        $this->assertSame(
            'error',
            $this->service->checkSessionState('client-1', 'https://rp.example', implode('.', $parts))
        );
    }

    public function testOriginBindingJwkUsesConfiguredPublicRsaKey(): void {
        $jwk = $this->service->getOriginBindingJwk();
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertNotSame('', $jwk['n']);
        $this->assertNotSame('', $jwk['e']);
        $this->assertTrue($jwk['ext']);
    }

    public function testGetOriginUsesRfc6454DefaultPortSerialization(): void {
        $this->assertSame('https://rp.example', SessionManagementService::getOrigin('HTTPS://RP.EXAMPLE/callback?x=1'));
        $this->assertSame('https://rp.example', SessionManagementService::getOrigin('https://RP.EXAMPLE:443/callback'));
        $this->assertSame('http://rp.example', SessionManagementService::getOrigin('http://RP.EXAMPLE:80/callback'));
        $this->assertSame('https://rp.example:8443', SessionManagementService::getOrigin('https://RP.EXAMPLE:8443/callback'));
        $this->assertSame('http://localhost:8080', SessionManagementService::getOrigin('http://localhost:8080/cb'));
        $this->assertNull(SessionManagementService::getOrigin('com.example.app:/callback'));
        $this->assertNull(SessionManagementService::getOrigin('/relative/path'));
    }

    public function testSessionManagementIsOnlySupportedOnHttps(): void {
        $httpCookies = [];
        $service = $this->newService($this->session, $this->createRequestMock($httpCookies, 'http'));
        $this->assertFalse($service->isSupported());
        $this->assertTrue($this->service->isSupported());
    }

    private function configureRandom(string $browserStateCharacter): void {
        $this->secureRandom->method('generate')->willReturnCallback(
            static fn (int $length): string => $length === 32 ? str_repeat('S', 32) : str_repeat($browserStateCharacter, 64)
        );
    }

    private function newService(ISession $session, IRequest $request): SessionManagementService {
        return new SessionManagementService(
            $session,
            $this->secureRandom,
            $this->clientMapper,
            $this->redirectUriMapper,
            $this->redirectUriService,
            $this->credentialService,
            $this->appConfig,
            $request,
            $this->output,
            $this->cacheFactory,
            $this->config,
        );
    }

    /** @param array<string,mixed> $data */
    private function createSessionMock(array &$data): ISession {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$data): mixed {
            return $data[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$data): void { $data[$key] = $value; });
        $session->method('remove')->willReturnCallback(static function (string $key) use (&$data): void { unset($data[$key]); });
        return $session;
    }

    /** @param array<string,string> $cookies */
    private function createRequestMock(array &$cookies, string $protocol): IRequest {
        $request = $this->createMock(IRequest::class);
        $request->method('getCookie')->willReturnCallback(static function (string $key) use (&$cookies): ?string {
            return $cookies[$key] ?? null;
        });
        $request->method('getServerProtocol')->willReturn($protocol);
        return $request;
    }

    /** @param array<string,mixed> $data */
    private function createCacheFactoryMock(array &$data): ICacheFactory {
        $cache = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$data): mixed {
            return $data[$key] ?? null;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value, int $ttl = 0) use (&$data): bool { $data[$key] = $value; return true; });
        $cache->method('remove')->willReturnCallback(static function (string $key) use (&$data): bool { unset($data[$key]); return true; });
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->with('oidc_session_management')->willReturn($cache);
        return $factory;
    }

    /** @return array{0:string,1:string,2:string} */
    private function createRsaKeyMaterial(): array {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $privateKey = '';
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('rsa', $details);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        return [$privateKey, $encode($details['rsa']['n']), $encode($details['rsa']['e'])];
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
