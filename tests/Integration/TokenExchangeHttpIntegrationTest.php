<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\TexTargets;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end HTTP integration tests for the constrained RFC 8693 profile.
 *
 * Unlike the controller integration tests, these tests go through a real
 * Nextcloud HTTP server. This exercises routing, client_secret_basic,
 * application/x-www-form-urlencoded parsing (including repeated fields), the
 * real database mappers and the real JWT/opaque-token generators.
 *
 * The CI workflow provides OIDC_INTEGRATION_BASE_URL and starts the Nextcloud
 * HTTP server before phpunit.integration.xml is executed. Local runs without
 * that environment variable skip this class instead of pretending to test the
 * HTTP layer.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
#[\PHPUnit\Framework\Attributes\Group(name: 'HTTP')]
class TokenExchangeHttpIntegrationTest extends TestCase {
    private const SUBJECT_CLIENT_ID = 'tex-http-subject-client';
    private const JWT_CLIENT_ID = 'tex-http-jwt-client';
    private const OPAQUE_CLIENT_ID = 'tex-http-opaque-client';
    private const CLIENT_SECRET = 'tex-http-test-secret-0123456789abcdef';
    private const TEST_USER_ID = 'tex-http-user';
    private const TOKEN_PATH = '/index.php/apps/oidc/token';
    private const INTROSPECTION_PATH = '/index.php/apps/oidc/introspect';

    private ClientMapper $clientMapper;
    private AccessTokenMapper $accessTokenMapper;
    private TexTargetMapper $texTargetMapper;
    private IUserManager $userManager;
    private IAppConfig $appConfig;
    private string $baseUrl;

    protected function setUp(): void {
        parent::setUp();

        $baseUrl = getenv('OIDC_INTEGRATION_BASE_URL');
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            $this->markTestSkipped('OIDC_INTEGRATION_BASE_URL is required for real HTTP integration tests.');
        }
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('The PHP curl extension is required for real HTTP integration tests.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');

        // Make sure the app container is initialized before resolving app services.
        $app = new \OCP\AppFramework\App(Application::APP_ID);
        $appContainer = $app->getContainer();

        $this->clientMapper = Server::get(ClientMapper::class);
        $this->accessTokenMapper = Server::get(AccessTokenMapper::class);
        $this->texTargetMapper = Server::get(TexTargetMapper::class);
        $this->userManager = Server::get(IUserManager::class);
        $this->appConfig = $appContainer->get(IAppConfig::class);

        $this->cleanupTestData();
    }

    protected function tearDown(): void {
        if (isset($this->clientMapper)) {
            $this->cleanupTestData();
        }
        parent::tearDown();
    }

    public function testHttpCrossClientJwtExchangeEnforcesClaimsAndLifetime(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile email');
        $this->createTestUser();

        $resource = 'https://api.example.test/orders';
        $this->createTexTarget($requestingClient, $resource);

        $remainingLifetime = $this->shortSubjectLifetime();
        $subjectToken = $this->createSubjectToken(
            $subjectClient,
            'openid profile email',
            '',
            $remainingLifetime
        );

        $response = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $subjectToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', $resource],
                ['scope', 'profile'],
            ]),
            $requestingClient
        );

        $this->assertSame(200, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('urn:ietf:params:oauth:token-type:access_token', $data['issued_token_type'] ?? null);
        $this->assertSame('Bearer', $data['token_type'] ?? null);
        $this->assertSame('profile', $data['scope'] ?? null);
        $this->assertIsInt($data['expires_in'] ?? null);
        $this->assertGreaterThan(0, $data['expires_in']);
        $this->assertLessThanOrEqual($remainingLifetime, $data['expires_in']);
        $this->assertGreaterThanOrEqual(max(1, $remainingLifetime - 15), $data['expires_in']);
        $this->assertStringContainsString('no-store', strtolower($response['headers']['cache-control'] ?? ''));
        $this->assertStringContainsString('no-cache', strtolower($response['headers']['pragma'] ?? ''));

        $accessToken = $data['access_token'] ?? '';
        $this->assertIsString($accessToken);
        $this->assertNotSame('', $accessToken);

        $jwt = $this->decodeJwtPayload($accessToken);
        $this->assertSame(self::TEST_USER_ID, $jwt['sub'] ?? null);
        $this->assertSame($resource, $jwt['aud'] ?? null);
        $this->assertSame(self::JWT_CLIENT_ID, $jwt['client_id'] ?? null);
        $this->assertSame(self::JWT_CLIENT_ID, $jwt['azp'] ?? null);
        $this->assertSame('profile', $jwt['scope'] ?? null);
        $this->assertArrayHasKey('iat', $jwt);
        $this->assertArrayHasKey('exp', $jwt);
        $this->assertArrayHasKey('jti', $jwt);
        $this->assertArrayNotHasKey('auth_time', $jwt, 'Token Exchange JWTs must not manufacture a new authentication time.');
        $this->assertSame($data['expires_in'], $jwt['exp'] - $jwt['iat']);

        // Verify that the HTTP request persisted the exchanged token with the
        // requesting client (not the subject-token client) and target policy.
        $persisted = $this->accessTokenMapper->getByAccessToken($accessToken);
        $this->assertSame($requestingClient->getId(), $persisted->getClientId());
        $this->assertSame(self::TEST_USER_ID, $persisted->getUserId());
        $this->assertSame($resource, $persisted->getResource());
        $this->assertSame('profile', $persisted->getScope());
        $this->assertSame((string)$persisted->getId(), (string)$jwt['jti']);
    }

    public function testHttpRepeatedResourceParametersAreRejectedBeforeParameterCollapse(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();

        // Whitelist both values. If the framework collapsed the repeated field
        // and the raw-body detection did not work, either individual value would
        // otherwise be accepted and the test would incorrectly return 200.
        $resourceA = 'https://api-a.example.test/';
        $resourceB = 'https://api-b.example.test/';
        $this->createTexTarget($requestingClient, $resourceA);
        $this->createTexTarget($requestingClient, $resourceB);

        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

        $response = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $subjectToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', $resourceA],
                ['resource', $resourceB],
                ['scope', 'profile'],
            ]),
            $requestingClient
        );

        $this->assertSame(400, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('invalid_target', $data['error'] ?? null);
        $this->assertStringContainsString('Multiple resource', $data['error_description'] ?? '');
    }

    public function testHttpInheritedResourceMustBeWhitelistedForRequestingClient(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();

        $this->createTexTarget($requestingClient, 'https://allowed.example.test/');
        $subjectToken = $this->createSubjectToken(
            $subjectClient,
            'profile',
            'https://not-allowed.example.test/',
            $this->shortSubjectLifetime()
        );

        // Deliberately omit resource. The subject token resource is inherited,
        // but it must be checked against the requesting client's whitelist.
        $response = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $subjectToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['scope', 'profile'],
            ]),
            $requestingClient
        );

        $this->assertSame(400, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('invalid_target', $data['error'] ?? null);
        $this->assertStringContainsString('effective resource', strtolower($data['error_description'] ?? ''));
    }

    public function testHttpOpaqueExchangeCanBeIntrospectedWithResourceAudience(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::OPAQUE_CLIENT_ID, 'opaque', true, 'profile');
        $this->createTestUser();

        $resource = 'https://opaque-api.example.test/';
        $this->createTexTarget($requestingClient, $resource);
        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

        $exchange = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $subjectToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', $resource],
                ['scope', 'profile'],
            ]),
            $requestingClient
        );

        $this->assertSame(200, $exchange['status'], $exchange['body']);
        $exchangeData = $this->requireJsonObject($exchange);
        $accessToken = $exchangeData['access_token'] ?? '';
        $this->assertIsString($accessToken);
        $this->assertNotSame('', $accessToken);
        $this->assertStringNotContainsString('.', $accessToken, 'Opaque access token unexpectedly looks like a JWT.');

        $introspection = $this->postForm(
            self::INTROSPECTION_PATH,
            $this->buildFormBody([
                ['token', $accessToken],
                ['token_type_hint', 'access_token'],
            ]),
            $requestingClient
        );

        $this->assertSame(200, $introspection['status'], $introspection['body']);
        $introspectionData = $this->requireJsonObject($introspection);
        $this->assertTrue($introspectionData['active'] ?? false);
        $this->assertSame(self::OPAQUE_CLIENT_ID, $introspectionData['client_id'] ?? null);
        $this->assertSame(self::TEST_USER_ID, $introspectionData['sub'] ?? null);
        $this->assertSame('profile', $introspectionData['scope'] ?? null);
        $this->assertSame($resource, $introspectionData['aud'] ?? null);
        $this->assertArrayHasKey('exp', $introspectionData);
    }

    private function createClient(
        string $clientIdentifier,
        string $tokenType,
        bool $texEnabled,
        ?string $texAllowedScopes
    ): Client {
        $client = new Client(
            'HTTP Token Exchange Test Client',
            ['https://client.example.test/callback'],
            'RS256',
            'confidential',
            'code',
            $tokenType,
            'openid profile email',
            '',
            false,
            $texEnabled,
            $texAllowedScopes
        );
        $client->setClientIdentifier($clientIdentifier);
        $client->setSecret(self::CLIENT_SECRET);

        return $this->clientMapper->insert($client);
    }

    private function createTestUser(): void {
        if (!$this->userManager->userExists(self::TEST_USER_ID)) {
            $this->userManager->createUser(self::TEST_USER_ID, 'tex-http-test-password');
        }
        $this->assertNotNull($this->userManager->get(self::TEST_USER_ID));
    }

    private function createTexTarget(Client $client, string $resource): TexTargets {
        $target = new TexTargets();
        $target->setClientId($client->getId());
        $target->setResourceUrl($resource);
        $target->setCreated(time());
        $target->setUsedAt(0);

        return $this->texTargetMapper->insert($target);
    }

    private function createSubjectToken(
        Client $subjectClient,
        string $scope,
        string $resource,
        int $remainingLifetime
    ): AccessToken {
        $configuredLifetime = $this->configuredAccessTokenLifetime();
        $now = time();
        $rawToken = 'subject-' . bin2hex(random_bytes(24));

        $token = new AccessToken();
        $token->setClientId($subjectClient->getId());
        $token->setUserId(self::TEST_USER_ID);
        $token->setScope($scope);
        $token->setHashedCode(hash('sha512', 'code-' . $rawToken));
        $token->setAccessToken($rawToken);
        $token->setCreated($now - 30);
        $token->setRefreshed($now + $remainingLifetime - $configuredLifetime);
        $token->setNonce('');
        $token->setResource($resource);
        $token->setCodeChallenge('');
        $token->setCodeChallengeMethod('');

        return $this->accessTokenMapper->insert($token);
    }

    private function shortSubjectLifetime(): int {
        $configuredLifetime = $this->configuredAccessTokenLifetime();
        if ($configuredLifetime < 30) {
            $this->markTestSkipped('Configured access-token lifetime is too short for the HTTP lifetime integration test.');
        }

        return min(120, $configuredLifetime - 5);
    }

    private function configuredAccessTokenLifetime(): int {
        $lifetime = (int)$this->appConfig->getAppValueString(
            Application::APP_CONFIG_DEFAULT_EXPIRE_TIME,
            Application::DEFAULT_EXPIRE_TIME
        );
        $this->assertGreaterThan(0, $lifetime, 'Access-token lifetime must be positive for integration tests.');

        return $lifetime;
    }

    /**
     * @param list<array{0:string,1:string}> $pairs
     */
    private function buildFormBody(array $pairs): string {
        return implode('&', array_map(
            static fn (array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]),
            $pairs
        ));
    }

    /**
     * @return array{status:int,body:string,headers:array<string,string>,json:mixed}
     */
    private function postForm(string $path, string $body, Client $client): array {
        $headers = [];
        $curl = curl_init($this->baseUrl . $path);
        $this->assertNotFalse($curl);

        $basicCredentials = rawurlencode($client->getClientIdentifier())
            . ':'
            . rawurlencode($client->getSecret());

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($basicCredentials),
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$headers): int {
                $length = strlen($headerLine);
                $separator = strpos($headerLine, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($headerLine, 0, $separator)));
                    $value = trim(substr($headerLine, $separator + 1));
                    if ($name !== '') {
                        $headers[$name] = isset($headers[$name])
                            ? $headers[$name] . ', ' . $value
                            : $value;
                    }
                }
                return $length;
            },
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $this->assertNotFalse($responseBody, 'HTTP request failed: ' . $curlError);
        $json = json_decode((string)$responseBody, true);

        return [
            'status' => $status,
            'body' => (string)$responseBody,
            'headers' => $headers,
            'json' => $json,
        ];
    }

    /**
     * @param array{status:int,body:string,headers:array<string,string>,json:mixed} $response
     * @return array<string,mixed>
     */
    private function requireJsonObject(array $response): array {
        $this->assertIsArray($response['json'], 'Response is not a JSON object: ' . $response['body']);
        return $response['json'];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJwtPayload(string $jwt): array {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'Expected a compact JWT access token.');

        $encodedPayload = strtr($parts[1], '-_', '+/');
        $padding = strlen($encodedPayload) % 4;
        if ($padding !== 0) {
            $encodedPayload .= str_repeat('=', 4 - $padding);
        }

        $decodedPayload = base64_decode($encodedPayload, true);
        $this->assertNotFalse($decodedPayload, 'JWT payload is not valid base64url.');
        $payload = json_decode($decodedPayload, true);
        $this->assertIsArray($payload, 'JWT payload is not a JSON object.');

        return $payload;
    }

    private function cleanupTestData(): void {
        foreach ([self::JWT_CLIENT_ID, self::OPAQUE_CLIENT_ID, self::SUBJECT_CLIENT_ID] as $clientIdentifier) {
            try {
                $client = $this->clientMapper->getByIdentifier($clientIdentifier);
                if ($client !== null) {
                    $this->texTargetMapper->deleteByClientId($client->getId());
                    $this->accessTokenMapper->deleteByClientId($client->getId());
                    $this->clientMapper->delete($client);
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup also covers partially completed prior runs.
            }
        }

        try {
            if ($this->userManager->userExists(self::TEST_USER_ID)) {
                $user = $this->userManager->get(self::TEST_USER_ID);
                if ($user !== null) {
                    $user->delete();
                }
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup; the next CI database is disposable as well.
        }
    }
}
