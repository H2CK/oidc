<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\CredentialService;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\App;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SessionManagementIntegrationTest extends TestCase {
    private ClientMapper $clientMapper;
    private RedirectUriMapper $redirectUriMapper;
    private IDBConnection $db;
    private ?Client $client = null;
    private ?SessionManagementService $sessionManagementService = null;

    protected function setUp(): void {
        parent::setUp();
        $container = (new App('oidc'))->getContainer();
        $this->clientMapper = $container->query(ClientMapper::class);
        $this->redirectUriMapper = $container->query(RedirectUriMapper::class);
        $this->db = $container->query(IDBConnection::class);
    }

    protected function tearDown(): void {
        if ($this->sessionManagementService !== null) {
            $this->sessionManagementService->resetBrowserState();
        }
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
        $container = (new App('oidc'))->getContainer();

        // Model two browser requests in one integration test. Session Management
        // emits oidc_opbs through IOutput (not Response::addCookie()) because the
        // cookie must deliberately remain JavaScript-readable for the OP iframe.
        $cookies = [];
        $sessionData = [];

        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getCookie')->willReturnCallback(
            static function (string $name) use (&$cookies): ?string {
                $value = $cookies[$name]['value'] ?? null;
                return is_string($value) ? $value : null;
            }
        );

        $output = $this->createMock(IOutput::class);
        $output->method('setCookie')->willReturnCallback(
            static function (
                string $name,
                string $value,
                int $expire,
                string $path,
                ?string $domain,
                bool $secure,
                bool $httpOnly,
                string $sameSite
            ) use (&$cookies): void {
                $cookies[$name] = [
                    'value' => $value,
                    'expire' => $expire,
                    'path' => $path,
                    'domain' => $domain,
                    'secure' => $secure,
                    'httpOnly' => $httpOnly,
                    'sameSite' => $sameSite,
                ];
            }
        );

        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static function (string $key) use (&$sessionData): mixed {
                return $sessionData[$key] ?? null;
            }
        );
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$sessionData): void {
                $sessionData[$key] = $value;
            }
        );
        $session->method('remove')->willReturnCallback(
            static function (string $key) use (&$sessionData): void {
                unset($sessionData[$key]);
            }
        );

        [$privateKey, $modulus, $exponent] = $this->createRsaKeyMaterial();
        $credentialService = $this->createMock(CredentialService::class);
        $credentialService->method('getPrivateKey')->willReturn($privateKey);
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getAppValueString')->willReturnCallback(
            static fn (string $key, string $default = ''): string => match ($key) {
                'public_key_n' => $modulus,
                'public_key_e' => $exponent,
                default => $default,
            }
        );

        $this->sessionManagementService = new SessionManagementService(
            $session,
            $container->query(ISecureRandom::class),
            $this->clientMapper,
            $this->redirectUriMapper,
            new RedirectUriService($this->createMock(LoggerInterface::class)),
            $credentialService,
            $appConfig,
            $request,
            $output,
            $container->query(ICacheFactory::class),
            $container->query(IConfig::class),
        );
        $this->sessionManagementService->resetBrowserState();

        $identifier = 'integration-session-' . bin2hex(random_bytes(8));
        $client = new Client('Session Management Integration RP', ['https://rp.example/callback']);
        $client->setClientIdentifier(str_pad($identifier, 32, 'x'));
        $client->setSecret(str_repeat('s', 32));
        $this->client = $this->clientMapper->insert($client);

        $state = $this->sessionManagementService->generateSessionState(
            $this->client->getClientIdentifier(),
            'https://rp.example/callback'
        );
        $parsedState = SessionManagementService::parseSessionState($state);
        $this->assertNotNull($parsedState);
        $this->assertSame('https://rp.example', $parsedState['origin']);
        $this->assertNotSame('', $parsedState['binding']);

        $this->sessionManagementService->applyBrowserStateCookie(new Response());
        $this->assertArrayHasKey(SessionManagementService::OP_BROWSER_STATE_COOKIE, $cookies);
        $browserStateCookie = $cookies[SessionManagementService::OP_BROWSER_STATE_COOKIE];
        $this->assertSame('None', $browserStateCookie['sameSite']);
        $this->assertTrue($browserStateCookie['secure']);
        $this->assertFalse($browserStateCookie['httpOnly']);
        $this->assertGreaterThan(time(), $browserStateCookie['expire']);

        // The mocked IRequest now sees the cookie captured by IOutput, modelling
        // the next browser request to the OP check-session iframe/status endpoint.
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
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        return [$privateKey, $encode($details['rsa']['n']), $encode($details['rsa']['e'])];
    }

}
