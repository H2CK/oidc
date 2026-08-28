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
use OCA\OIDCIdentityProvider\Db\TexSubjectClient;
use OCA\OIDCIdentityProvider\Db\TexSubjectClientMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\Exception as DatabaseException;
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
    private const RESOURCE_SERVER_CLIENT_ID = 'tex-http-resource-server';
    private const WRONG_RESOURCE_SERVER_CLIENT_ID = 'tex-http-wrong-resource-server';
    private const CLIENT_SECRET = 'tex-http-test-secret-0123456789abcdef';
    private const TEST_USER_ID = 'tex-http-user';
    private const TOKEN_PATH = '/index.php/apps/oidc/token';
    private const INTROSPECTION_PATH = '/index.php/apps/oidc/introspect';

    private ClientMapper $clientMapper;
    private AccessTokenMapper $accessTokenMapper;
    private TexTargetMapper $texTargetMapper;
    private TexSubjectClientMapper $texSubjectClientMapper;
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
        $this->texSubjectClientMapper = Server::get(TexSubjectClientMapper::class);
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
        $this->allowSubjectClient($requestingClient, $subjectClient);

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
        $discovery = $this->assertJwtSignatureValidViaDiscovery($accessToken);
        $this->assertSame($discovery['issuer'] ?? null, $jwt['iss'] ?? null);
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
        $this->assertSame($subjectToken->getId(), $persisted->getParentTokenId());
        $this->assertSame(self::TEST_USER_ID, $persisted->getUserId());
        $this->assertSame($resource, $persisted->getResource());
        $this->assertSame('profile', $persisted->getScope());
        $this->assertSame((int)$jwt['iat'], $persisted->getRefreshed());
        $this->assertSame((int)$jwt['exp'], $persisted->getExpiresAt());
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
        $this->allowSubjectClient($requestingClient, $subjectClient);
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
        $this->allowSubjectClient($requestingClient, $subjectClient);
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

        $resourceServer = $this->createClient(
            self::RESOURCE_SERVER_CLIENT_ID,
            'opaque',
            false,
            null,
            $resource
        );
        $wrongResourceServer = $this->createClient(
            self::WRONG_RESOURCE_SERVER_CLIENT_ID,
            'opaque',
            false,
            null,
            'https://wrong-resource.example.test/'
        );

        $introspection = $this->postForm(
            self::INTROSPECTION_PATH,
            $this->buildFormBody([
                ['token', $accessToken],
                ['token_type_hint', 'access_token'],
            ]),
            $resourceServer
        );

        $this->assertSame(200, $introspection['status'], $introspection['body']);
        $introspectionData = $this->requireJsonObject($introspection);
        $this->assertTrue($introspectionData['active'] ?? false);
        $this->assertSame(self::OPAQUE_CLIENT_ID, $introspectionData['client_id'] ?? null);
        $this->assertSame(self::TEST_USER_ID, $introspectionData['sub'] ?? null);
        $this->assertSame('profile', $introspectionData['scope'] ?? null);
        $this->assertSame($resource, $introspectionData['aud'] ?? null);
        $this->assertArrayHasKey('exp', $introspectionData);

        $wrongIntrospection = $this->postForm(
            self::INTROSPECTION_PATH,
            $this->buildFormBody([
                ['token', $accessToken],
                ['token_type_hint', 'access_token'],
            ]),
            $wrongResourceServer
        );
        $this->assertSame(200, $wrongIntrospection['status'], $wrongIntrospection['body']);
        $this->assertFalse($this->requireJsonObject($wrongIntrospection)['active'] ?? true);
    }

    public function testHttpSubjectClientMustBeExplicitlyAllowed(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();

        $resource = 'https://api.example.test/not-authorized';
        $this->createTexTarget($requestingClient, $resource);
        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

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

        $this->assertSame(400, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('invalid_request', $data['error'] ?? null);
        $this->assertStringContainsString('not authorized for Token Exchange', $data['error_description'] ?? '');
    }

    public function testHttpUserInfoRejectsTokenExchangedForDifferentResource(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::OPAQUE_CLIENT_ID, 'opaque', true, 'profile');
        $this->createTestUser();

        $resource = 'https://backend.example.test/orders';
        $this->createTexTarget($requestingClient, $resource);
        $this->allowSubjectClient($requestingClient, $subjectClient);
        $subjectToken = $this->createSubjectToken($subjectClient, 'openid profile', '', $this->shortSubjectLifetime());

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
        $accessToken = $this->requireJsonObject($exchange)['access_token'] ?? '';
        $this->assertIsString($accessToken);
        $this->assertNotSame('', $accessToken);

        $discovery = $this->getJsonUrl($this->baseUrl . '/index.php/apps/oidc/openid-configuration');
        $userInfoEndpoint = $discovery['userinfo_endpoint'] ?? null;
        $this->assertIsString($userInfoEndpoint);

        $userInfo = $this->getWithBearer($userInfoEndpoint, $accessToken);
        $this->assertSame(401, $userInfo['status'], $userInfo['body']);
        $data = $this->requireJsonObject($userInfo);
        $this->assertSame('invalid_token', $data['error'] ?? null);
        $this->assertStringContainsString('invalid_token', $userInfo['headers']['www-authenticate'] ?? '');
    }

    public function testHttpMixedDuplicateGrantTypeCannotBypassTokenExchangeValidation(): void {
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');

        // Put authorization_code last so PHP/Nextcloud may expose that value to
        // controller argument binding. The raw body still contains Token Exchange
        // and must therefore be rejected for duplicate grant_type before dispatch.
        $response = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['grant_type', 'authorization_code'],
                ['code', 'not-a-real-code'],
            ]),
            $requestingClient
        );

        $this->assertSame(400, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('invalid_request', $data['error'] ?? null);
        $this->assertStringContainsString('grant_type', $data['error_description'] ?? '');
    }

    public function testHttpDuplicateSingletonParameterIsRejected(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();
        $resource = 'https://api.example.test/duplicate-scope';
        $this->createTexTarget($requestingClient, $resource);
        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', $resource, $this->shortSubjectLifetime());

        $response = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $subjectToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', $resource],
                ['scope', 'profile'],
                ['scope', 'profile'],
            ]),
            $requestingClient
        );

        $this->assertSame(400, $response['status'], $response['body']);
        $this->assertSame('invalid_request', $this->requireJsonObject($response)['error'] ?? null);
    }

    public function testHttpWrongContentTypeIsRejected(): void {
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $body = json_encode([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ], JSON_THROW_ON_ERROR);

        $response = $this->postRaw(self::TOKEN_PATH, $body, $requestingClient, 'application/json');
        $this->assertSame(400, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('invalid_request', $data['error'] ?? null);
    }

    public function testHttpInvalidBasicCredentialsReturnChallenge(): void {
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $response = $this->postRaw(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', 'token'],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', 'https://api.example.test/'],
            ]),
            $requestingClient,
            'application/x-www-form-urlencoded',
            'wrong-secret'
        );

        $this->assertSame(401, $response['status'], $response['body']);
        $this->assertSame('invalid_client', $this->requireJsonObject($response)['error'] ?? null);
        $this->assertSame('Basic realm="token"', $response['headers']['www-authenticate'] ?? null);
    }

    public function testHttpEmptyParameterValuesAreTreatedAsOmitted(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();

        $resource = 'https://api.example.test/empty-values';
        $this->createTexTarget($requestingClient, $resource);
        $this->allowSubjectClient($requestingClient, $subjectClient);
        $subjectToken = $this->createSubjectToken($subjectClient, 'openid profile email', '', $this->shortSubjectLifetime());

        // RFC 6749 section 3.2 requires parameters sent without a value to be
        // treated as omitted. Empty occurrences must therefore not affect
        // cardinality, client authentication, or unsupported-option checks.
        $response = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['grant_type', ''],
                ['subject_token', $subjectToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', ''],
                ['resource', $resource],
                ['scope', ''],
                ['requested_token_type', ''],
                ['audience', ''],
                ['actor_token', ''],
                ['actor_token_type', ''],
                ['client_id', ''],
                ['client_secret', ''],
            ]),
            $requestingClient
        );

        $this->assertSame(200, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('profile', $data['scope'] ?? null, 'Omitted scope must be subject scope intersected with the configured TEX allow-list.');
        $persisted = $this->accessTokenMapper->getByAccessToken((string)($data['access_token'] ?? ''));
        $this->assertSame($subjectToken->getId(), $persisted->getParentTokenId());
        $this->assertSame($resource, $persisted->getResource());
    }

    public function testHttpNormalResourceBoundTokenStillWorksAtUserInfo(): void {
        $normalClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $this->createTestUser();
        $normalToken = $this->createSubjectToken(
            $normalClient,
            'openid profile',
            'https://ordinary-api.example.test/',
            $this->shortSubjectLifetime()
        );
        $this->assertNull($normalToken->getParentTokenId());

        $discovery = $this->getJsonUrl($this->baseUrl . '/index.php/apps/oidc/openid-configuration');
        $userInfoEndpoint = $discovery['userinfo_endpoint'] ?? null;
        $this->assertIsString($userInfoEndpoint);

        $userInfo = $this->getWithBearer($userInfoEndpoint, $normalToken->getAccessToken());
        $this->assertSame(200, $userInfo['status'], $userInfo['body']);
        $this->assertSame(self::TEST_USER_ID, $this->requireJsonObject($userInfo)['sub'] ?? null);
    }

    public function testHttpTokenExchangeFailsClosedWithoutAllowedScopes(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, null);
        $this->createTestUser();
        $resource = 'https://api.example.test/no-scope-policy';
        $this->createTexTarget($requestingClient, $resource);
        $this->allowSubjectClient($requestingClient, $subjectClient);
        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

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

        $this->assertSame(400, $response['status'], $response['body']);
        $data = $this->requireJsonObject($response);
        $this->assertSame('invalid_scope', $data['error'] ?? null);
        $this->assertStringContainsString('no token exchange scopes', strtolower($data['error_description'] ?? ''));
    }

    public function testHttpBasicCredentialsUseFormUrlencodedDecoding(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $specialSecret = 'secret with space+plus%value-0123456789';
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile', null, $specialSecret);
        $this->createTestUser();
        $resource = 'https://api.example.test/basic-form-encoding';
        $this->createTexTarget($requestingClient, $resource);
        $this->allowSubjectClient($requestingClient, $subjectClient);
        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

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
    }

    public function testTokenLineageForeignKeyRejectsOrphanedExchangeToken(): void {
        $requestingClient = $this->createClient(self::OPAQUE_CLIENT_ID, 'opaque', true, 'profile');
        $this->createTestUser();

        $now = time();
        $orphan = new AccessToken();
        $orphan->setClientId($requestingClient->getId());
        $orphan->setParentTokenId(2147483647);
        $orphan->setUserId(self::TEST_USER_ID);
        $orphan->setScope('profile');
        $orphan->setHashedCode(hash('sha512', 'orphan-' . bin2hex(random_bytes(16))));
        $orphan->setAccessToken('orphan-' . bin2hex(random_bytes(24)));
        $orphan->setCreated($now);
        $orphan->setRefreshed($now);
        $orphan->setExpiresAt($now + 60);
        $orphan->setNonce('');
        $orphan->setResource('https://api.example.test/orphan');
        $orphan->setCodeChallenge('');
        $orphan->setCodeChallengeMethod('');

        try {
            $this->accessTokenMapper->insert($orphan);
            $this->fail('Database accepted an exchanged token whose parent token does not exist.');
        } catch (DatabaseException $e) {
            $this->assertSame(DatabaseException::REASON_FOREIGN_KEY_VIOLATION, $e->getReason());
        }
    }

    public function testHttpSubjectRevocationCascadesThroughMultiHopExchange(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $firstClient = $this->createClient(self::OPAQUE_CLIENT_ID, 'opaque', true, 'profile');
        $secondClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();

        $resourceOne = 'https://api-one.example.test/';
        $resourceTwo = 'https://api-two.example.test/';
        $this->createTexTarget($firstClient, $resourceOne);
        $this->createTexTarget($secondClient, $resourceTwo);
        $this->allowSubjectClient($firstClient, $subjectClient);
        $this->allowSubjectClient($secondClient, $firstClient);
        $rootToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

        $firstExchange = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $rootToken->getAccessToken()],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', $resourceOne],
                ['scope', 'profile'],
            ]),
            $firstClient
        );
        $this->assertSame(200, $firstExchange['status'], $firstExchange['body']);
        $firstTokenValue = (string)($this->requireJsonObject($firstExchange)['access_token'] ?? '');
        $firstToken = $this->accessTokenMapper->getByAccessToken($firstTokenValue);
        $this->assertSame($rootToken->getId(), $firstToken->getParentTokenId());

        $secondExchange = $this->postForm(
            self::TOKEN_PATH,
            $this->buildFormBody([
                ['grant_type', 'urn:ietf:params:oauth:grant-type:token-exchange'],
                ['subject_token', $firstTokenValue],
                ['subject_token_type', 'urn:ietf:params:oauth:token-type:access_token'],
                ['resource', $resourceTwo],
                ['scope', 'profile'],
            ]),
            $secondClient
        );
        $this->assertSame(200, $secondExchange['status'], $secondExchange['body']);
        $secondTokenValue = (string)($this->requireJsonObject($secondExchange)['access_token'] ?? '');
        $secondToken = $this->accessTokenMapper->getByAccessToken($secondTokenValue);
        $this->assertSame($firstToken->getId(), $secondToken->getParentTokenId());

        $this->accessTokenMapper->delete($rootToken);

        foreach ([$firstTokenValue, $secondTokenValue] as $revokedTokenValue) {
            try {
                $this->accessTokenMapper->getByAccessToken($revokedTokenValue);
                $this->fail('Descendant exchanged token remained stored after subject-token revocation.');
            } catch (AccessTokenNotFoundException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testHttpExchangeWithoutEffectiveResourceIsRejected(): void {
        $subjectClient = $this->createClient(self::SUBJECT_CLIENT_ID, 'opaque', false, null);
        $requestingClient = $this->createClient(self::JWT_CLIENT_ID, 'jwt', true, 'profile');
        $this->createTestUser();
        $this->allowSubjectClient($requestingClient, $subjectClient);
        $subjectToken = $this->createSubjectToken($subjectClient, 'profile', '', $this->shortSubjectLifetime());

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
        $this->assertSame('invalid_target', $this->requireJsonObject($response)['error'] ?? null);
    }

    private function createClient(
        string $clientIdentifier,
        string $tokenType,
        bool $texEnabled,
        ?string $texAllowedScopes,
        ?string $resourceUrl = null,
        ?string $secret = null
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
        $client->setSecret($secret ?? self::CLIENT_SECRET);
        if ($resourceUrl !== null) {
            $client->setResourceUrl($resourceUrl);
        }

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

    private function allowSubjectClient(Client $requestingClient, Client $subjectClient): TexSubjectClient {
        $entry = new TexSubjectClient();
        $entry->setClientId($requestingClient->getId());
        $entry->setSubjectClientId($subjectClient->getId());
        return $this->texSubjectClientMapper->insert($entry);
    }

    private function createSubjectToken(
        Client $subjectClient,
        string $scope,
        string $resource,
        int $remainingLifetime
    ): AccessToken {
        $now = time();
        $rawToken = 'subject-' . bin2hex(random_bytes(24));

        $token = new AccessToken();
        $token->setClientId($subjectClient->getId());
        $token->setUserId(self::TEST_USER_ID);
        $token->setScope($scope);
        $token->setHashedCode(hash('sha512', 'code-' . $rawToken));
        $token->setAccessToken($rawToken);
        $token->setCreated($now - 30);
        $token->setRefreshed($now);
        $token->setExpiresAt($now + $remainingLifetime);
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
        return $this->postRaw($path, $body, $client, 'application/x-www-form-urlencoded');
    }

    /**
     * @return array{status:int,body:string,headers:array<string,string>,json:mixed}
     */
    private function postRaw(
        string $path,
        string $body,
        Client $client,
        string $contentType,
        ?string $secretOverride = null
    ): array {
        $headers = [];
        $curl = curl_init($this->baseUrl . $path);
        $this->assertNotFalse($curl);

        $basicCredentials = urlencode($client->getClientIdentifier())
            . ':'
            . urlencode($secretOverride ?? $client->getSecret());

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: ' . $contentType,
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
     * @return array{status:int,body:string,headers:array<string,string>,json:mixed}
     */
    private function getWithBearer(string $url, string $accessToken): array {
        $headers = [];
        $curl = curl_init($url);
        $this->assertNotFalse($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
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
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $this->assertNotFalse($body, 'HTTP GET failed: ' . $error);

        return [
            'status' => $status,
            'body' => (string)$body,
            'headers' => $headers,
            'json' => json_decode((string)$body, true),
        ];
    }

    /** @return array<string,mixed> */
    private function getJsonUrl(string $url): array {
        $curl = curl_init($url);
        $this->assertNotFalse($curl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $this->assertNotFalse($body, 'HTTP GET failed: ' . $error);
        $this->assertSame(200, $status, (string)$body);
        $json = json_decode((string)$body, true);
        $this->assertIsArray($json, 'Expected JSON object from ' . $url);
        return $json;
    }

    /** @return array<string,mixed> */
    private function assertJwtSignatureValidViaDiscovery(string $jwt): array {
        $discovery = $this->getJsonUrl($this->baseUrl . '/index.php/apps/oidc/openid-configuration');
        $jwksUri = $discovery['jwks_uri'] ?? null;
        $this->assertIsString($jwksUri);
        $jwks = $this->getJsonUrl($jwksUri);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $this->assertIsArray($header);
        $kid = $header['kid'] ?? null;

        $selected = null;
        foreach (($jwks['keys'] ?? []) as $key) {
            if (!is_array($key) || ($key['kty'] ?? null) !== 'RSA') {
                continue;
            }
            if ($kid === null || ($key['kid'] ?? null) === $kid) {
                $selected = $key;
                break;
            }
        }
        $this->assertIsArray($selected, 'No matching RSA JWK found.');
        $publicKey = openssl_pkey_get_public($this->rsaJwkToPem($selected));
        $this->assertNotFalse($publicKey, 'Unable to parse RSA JWK as a public key.');
        $signature = $this->base64UrlDecode($parts[2]);
        $verified = openssl_verify($parts[0] . '.' . $parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $verified, 'JWT signature did not verify against the advertised JWKS.');
        return $discovery;
    }

    private function rsaJwkToPem(array $jwk): string {
        $modulus = $this->base64UrlDecode((string)$jwk['n']);
        $exponent = $this->base64UrlDecode((string)$jwk['e']);
        $rsaPublicKey = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $subjectPublicKeyInfo = $this->derSequence($algorithm . "\x03" . $this->derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derSequence(string $value): string {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function base64UrlDecode(string $value): string {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        $this->assertNotFalse($decoded);
        return $decoded;
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
        foreach ([self::JWT_CLIENT_ID, self::OPAQUE_CLIENT_ID, self::SUBJECT_CLIENT_ID, self::RESOURCE_SERVER_CLIENT_ID, self::WRONG_RESOURCE_SERVER_CLIENT_ID] as $clientIdentifier) {
            try {
                $client = $this->clientMapper->getByIdentifier($clientIdentifier);
                if ($client !== null) {
                    $this->texTargetMapper->deleteByClientId($client->getId());
                    $this->texSubjectClientMapper->deleteAllForClientId($client->getId());
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
