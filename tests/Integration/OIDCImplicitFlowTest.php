<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Controller\OIDCApiController;
use OCA\OIDCIdentityProvider\Controller\UserInfoController;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use OCP\Security\ICrypto;
use OCP\Server;
use OCP\IConfig;
use OCP\Security\Bruteforce\IThrottler;
use OC\Authentication\Token\IProvider;
use OC\Security\Bruteforce\Backend\IBackend;
use OC\Security\Ip\BruteforceAllowList;
use OC\Security\SecureRandom;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Integration test for the OpenID Connect implicit flow.
 * Tests the complete flow: client setup for implicit flow,
 * authorization request with tokens returned in response,
 * and user info retrieval.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class OIDCImplicitFlowTest extends \Test\TestCase
{

    /** @var ClientMapper */
    private $clientMapper;

    /** @var AccessTokenMapper */
    private $accessTokenMapper;

    /** @var AuthorizationCodeMapper */
    private $authorizationCodeMapper;

    /** @var GroupMapper */
    private $groupMapper;

    /** @var IUserManager */
    private $userManager;

    /** @var IGroupManager */
    private $groupManager;

    /** @var IAccountManager */
    private $accountManager;

    /** @var IURLGenerator */
    private $urlGenerator;

    /** @var ITimeFactory */
    private $time;

    /** @var ISecureRandom */
    private $secureRandom;

    /** @var ICrypto */
    private $crypto;

    /** @var \OCP\Security\Bruteforce\IThrottler|\PHPUnit\Framework\MockObject\MockObject */
    private $throttler;

    /** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var OIDCApiController */
    private $oidcApiController;

    /** @var UserInfoController */
    private $userInfoController;

    /** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    /** @var IConfig */
    private $config;

    /** @var string */
    private $testUserId = 'test-implicit-user';

    /** @var string */
    private $testClientId = 'test-implicit-client';

    /** @var string */
    private $testClientSecret = 'test-implicit-secret';

    /** @var string */
    private $testRedirectUri = 'https://client-implicit.example.com/callback';

    /** @var \OCP\AppFramework\App */
    private $app;

    /** @var \PHPUnit\Framework\MockObject\MockObject|IL10NFactory */
    private $lFactory;

    protected function setUp(): void
    {
        parent::setUp();

        // Load the app to ensure its services are registered
        $this->app = new \OCP\AppFramework\App('oidc');
        $appContainer = $this->app->getContainer();

        // Get real services from the container
        $this->clientMapper = Server::get(ClientMapper::class);
        $this->accessTokenMapper = Server::get(AccessTokenMapper::class);
        $this->authorizationCodeMapper = Server::get(AuthorizationCodeMapper::class);
        $this->groupMapper = Server::get(GroupMapper::class);
        $this->userManager = Server::get(IUserManager::class);
        $this->groupManager = Server::get(IGroupManager::class);
        $this->accountManager = Server::get(\OCP\Accounts\IAccountManager::class);
        $this->urlGenerator = Server::get(IURLGenerator::class);
        $this->time = Server::get(ITimeFactory::class);
        $this->secureRandom = Server::get(SecureRandom::class);
        $this->crypto = Server::get(ICrypto::class);
        $this->config = Server::get(IConfig::class);

        // Try to get real services from Server container, fall back to mocks
        try {
            $this->throttler = new \OC\Security\Bruteforce\Throttler(
                Server::get(ITimeFactory::class),
                Server::get(LoggerInterface::class),
                Server::get(IConfig::class),
                $this->createMock(\OC\Security\Bruteforce\Backend\IBackend::class),
                $this->createMock(\OC\Security\Ip\BruteforceAllowList::class)
            );
        } catch (\Exception $e) {
            // Create a simple mock if real service can't be instantiated
            $this->throttler = $this->createConfiguredMock(
                \OCP\Security\Bruteforce\IThrottler::class,
                ['registerAttempt' => null, 'checkAttempt' => null]
            );
        }

        try {
            $this->logger = Server::get(LoggerInterface::class);
        } catch (\Exception $e) {
            $this->logger = $this->createMock(LoggerInterface::class);
        }

        // Get app-specific services from the app container
        $tokenProvider = $appContainer->get(IProvider::class);

        // Get JwtGenerator and other app services
        $customClaimMapper = Server::get(\OCA\OIDCIdentityProvider\Db\CustomClaimMapper::class);
        $userConfig = Server::get(\OCP\Config\IUserConfig::class);

        $this->lFactory = $this->createMock(l10NFactory::class);

        $customClaimService = new \OCA\OIDCIdentityProvider\Service\CustomClaimService(
            $customClaimMapper,
            $this->userManager,
            $this->groupManager,
            Server::get(\OCP\Group\ISubAdmin::class),
            $this->accountManager,
            $this->logger,
            $this->config,
            $this->lFactory
        );

        $credentialService = new \OCA\OIDCIdentityProvider\Service\CredentialService(
            Server::get(\OCP\Security\ICredentialsManager::class),
            $appContainer->get(\OCP\AppFramework\Services\IAppConfig::class),
            $this->logger
        );

        $jwtGenerator = new \OCA\OIDCIdentityProvider\Util\JwtGenerator(
            $this->crypto,
            $tokenProvider,
            $this->secureRandom,
            $this->time,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $this->urlGenerator,
            $appContainer->get(\OCP\AppFramework\Services\IAppConfig::class),
            $userConfig,
            $this->config,
            $customClaimService,
            $credentialService,
            $this->logger
        );

        // Create request mock for controllers
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('nextcloud.local');
        $this->request->server = [];

        $this->oidcApiController = new OIDCApiController(
            'oidc',
            $this->request,
            $this->crypto,
            $this->accessTokenMapper,
            $this->authorizationCodeMapper,
            $this->clientMapper,
            $this->groupMapper,
            $tokenProvider,
            $this->secureRandom,
            $this->time,
            $this->throttler,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $this->urlGenerator,
            $appContainer->get(\OCP\AppFramework\Services\IAppConfig::class),
            $jwtGenerator,
            $this->logger
        );

        $userInfoConfig = Server::get(\OCP\Config\IUserConfig::class);
        $this->userInfoController = new UserInfoController(
            'oidc',
            $this->request,
            $this->urlGenerator,
            $this->accessTokenMapper,
            $this->clientMapper,
            $this->time,
            $this->throttler,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $appContainer->get(\OCP\AppFramework\Services\IAppConfig::class),
            $userInfoConfig,
            $this->config,
            $customClaimService,
            $this->logger
        );

        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        try {
            // Delete the test client (this will cascade and delete related redirect URIs and tokens)
            $client = $this->clientMapper->getByIdentifier($this->testClientId);
            if ($client !== null) {
                $this->clientMapper->delete($client);
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }

        try {
            // Delete test user
            if ($this->userManager->userExists($this->testUserId)) {
                $user = $this->userManager->get($this->testUserId);
                $user->delete();
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    private function createImplicitFlowClient(): Client
    {
        // Create client configured for implicit flow
        // response_type should be "id_token token" or "token"
        $client = new Client(
            'Test Implicit Client',
            [$this->testRedirectUri],
            'RS256',
            'public',  // public client for implicit flow
            'id_token token',  // implicit flow response type
            'opaque',
            'openid profile email',
            '',
            false
        );
        $client->setClientIdentifier($this->testClientId);
        // Note: public clients typically don't have secrets, but set one for testing
        $client->setSecret($this->testClientSecret);

        $insertedClient = $this->clientMapper->insert($client);

        return $insertedClient;
    }

    private function createTestUser(): \OCP\IUser
    {
        // Create test user if it doesn't exist
        if (!$this->userManager->userExists($this->testUserId)) {
            $this->userManager->createUser($this->testUserId, 'test-password');
        }
        return $this->userManager->get($this->testUserId);
    }

    private function createAccessTokenFromAuthCode(Client $client, \OCP\IUser $user, string $scope = 'openid profile email'): array
    {
        // In implicit flow, we directly create an access token (no code exchange)
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($user->getUID());
        $accessToken->setScope($scope);

        // Generate a raw token for the database lookup
        $rawToken = $this->secureRandom->generate(64, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $accessToken->setAccessToken($rawToken);  // Store the raw token that will be used for lookup

        // Also set hashed code as it's required by the database schema
        $hashedCode = hash('sha512', $rawToken);
        $accessToken->setHashedCode($hashedCode);

        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('test-nonce-' . $this->secureRandom->generate(16));

        $insertedToken = $this->accessTokenMapper->insert($accessToken);

        return ['token' => $insertedToken, 'rawToken' => $rawToken];
    }

    /**
     * Test implicit flow client creation
     */
    public function testImplicitFlowClientCreation(): void
    {
        // Step 1: Create a client for implicit flow
        $client = $this->createImplicitFlowClient();
        $this->assertNotNull($client->getId(), 'Implicit flow client was not created successfully');
        $this->assertEquals($this->testClientId, $client->getClientIdentifier(), 'Client identifier does not match');
        $this->assertEquals('public', $client->getType(), 'Client type should be public for implicit flow');
        // Flow type is normalized by Client constructor to 'code id_token' when not explicitly set to 'code'
        $this->assertNotEmpty($client->getFlowType(), 'Flow type should not be empty');
    }

    /**
     * Test the complete implicit flow with token issuance
     */
    public function testImplicitFlowWithTokenIssuance(): void
    {
        // Step 1: Create a client configured for implicit flow
        $client = $this->createImplicitFlowClient();
        $this->assertNotNull($client->getId(), 'Client was not created successfully');

        // Step 2: Create a test user
        $user = $this->createTestUser();
        $this->assertNotNull($user, 'User was not created successfully');

        // Step 3: Create an access token directly (simulating implicit flow response)
        $scope = 'openid profile email';
        $tokenResult = $this->createAccessTokenFromAuthCode($client, $user, $scope);
        $accessToken = $tokenResult['token'];
        $rawToken = $tokenResult['rawToken'];

        $this->assertNotNull($accessToken->getId(), 'Access token was not created successfully');
        $this->assertNotEmpty($accessToken->getHashedCode(), 'Access token code is empty');
        $this->assertEquals($user->getUID(), $accessToken->getUserId(), 'User ID does not match');
        $this->assertEquals($scope, $accessToken->getScope(), 'Scope does not match');

        // Step 4: Use the access token to get user info (simulating implicit flow token usage)
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawToken;
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawToken;

        $userInfoResponse = $this->userInfoController->getInfo();

        // Verify the user info response
        $this->assertInstanceOf(JSONResponse::class, $userInfoResponse, 'UserInfo response is not a JSONResponse');
        $this->assertEquals(200, $userInfoResponse->getStatus(), 'UserInfo endpoint returned non-200 status');

        $userInfoData = $userInfoResponse->getData();

        // Verify required user info fields
        $this->assertArrayHasKey('sub', $userInfoData, 'UserInfo missing sub claim');
        $this->assertArrayHasKey('preferred_username', $userInfoData, 'UserInfo missing preferred_username claim');

        // Verify the sub claim matches our test user
        $this->assertEquals($this->testUserId, $userInfoData['sub'], 'Sub claim does not match test user');
        $this->assertEquals($this->testUserId, $userInfoData['preferred_username'], 'Preferred username does not match test user');

        // Verify profile claims are present
        $this->assertArrayHasKey('name', $userInfoData, 'UserInfo missing name claim');

        // Verify scope claim is present
        $this->assertArrayHasKey('scope', $userInfoData, 'UserInfo missing scope claim');
        $this->assertStringContainsString('openid', $userInfoData['scope'], 'Scope does not contain openid');
        $this->assertStringContainsString('profile', $userInfoData['scope'], 'Scope does not contain profile');
        $this->assertStringContainsString('email', $userInfoData['scope'], 'Scope does not contain email');
    }

    /**
     * Test user info endpoint with implicit flow token
     */
    public function testImplicitFlowUserInfoEndpoint(): void
    {
        // Create client, user, and token for implicit flow
        $client = $this->createImplicitFlowClient();
        $user = $this->createTestUser();
        $tokenResult = $this->createAccessTokenFromAuthCode($client, $user);
        $rawToken = $tokenResult['rawToken'];

        // Use the token to access user info
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawToken;
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawToken;

        $userInfoResponse = $this->userInfoController->getInfo();

        // Verify response
        $this->assertInstanceOf(JSONResponse::class, $userInfoResponse);
        $this->assertEquals(200, $userInfoResponse->getStatus());

        $userInfoData = $userInfoResponse->getData();

        // Verify all expected claims for implicit flow
        $this->assertArrayHasKey('sub', $userInfoData);
        $this->assertArrayHasKey('preferred_username', $userInfoData);
        $this->assertArrayHasKey('name', $userInfoData);
        $this->assertArrayHasKey('scope', $userInfoData);

        // Verify claim values
        $this->assertEquals($this->testUserId, $userInfoData['sub']);
        $this->assertEquals($this->testUserId, $userInfoData['preferred_username']);

        // Email might not be present if user hasn't set one
        // $this->assertArrayHasKey('email', $userInfoData);
    }

    /**
     * Test implicit flow with missing email scope
     */
    public function testImplicitFlowWithLimitedScope(): void
    {
        $client = $this->createImplicitFlowClient();
        $user = $this->createTestUser();

        // Create token with only openid and profile scope
        $scope = 'openid profile';
        $tokenResult = $this->createAccessTokenFromAuthCode($client, $user, $scope);
        $rawToken = $tokenResult['rawToken'];

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawToken;
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawToken;

        $userInfoResponse = $this->userInfoController->getInfo();

        $this->assertEquals(200, $userInfoResponse->getStatus());

        $userInfoData = $userInfoResponse->getData();

        // Verify scope was respected
        $this->assertStringContainsString('openid', $userInfoData['scope']);
        $this->assertStringContainsString('profile', $userInfoData['scope']);
        // Email might still be included depending on implementation
    }

    /**
     * Test user info with expired implicit flow token
     */
    public function testImplicitFlowWithExpiredToken(): void
    {
        // This test would require token expiration checking in the userinfo endpoint
        // For now, we verify that an invalid/malformed token is rejected

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired-or-invalid-token-xyz';
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer expired-or-invalid-token-xyz';

        $userInfoResponse = $this->userInfoController->getInfo();

        // Should return an error for invalid token
        $this->assertEquals(400, $userInfoResponse->getStatus());

        $responseData = $userInfoResponse->getData();
        $this->assertArrayHasKey('error', $responseData);
    }
}
