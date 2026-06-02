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
 * Integration test for the OpenID Connect code flow.
 * Tests the complete flow: client setup, authorization code generation,
 * token exchange, and user info retrieval.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class OIDCCodeFlowTest extends \Test\TestCase
{

    /** @var ClientMapper */
    private $clientMapper;

    /** @var AccessTokenMapper */
    private $accessTokenMapper;

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

    /** @var string */
    private $testUserId = 'test-oidc-user';

    /** @var string */
    private $testClientId = 'test-client';

    /** @var string */
    private $testClientSecret = 'test-secret';

    /** @var string */
    private $testRedirectUri = 'https://client.example.com/callback';

    /** @var \OCP\AppFramework\App */
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        // Load the app to ensure its services are registered
        $this->app = new \OCP\AppFramework\App('oidc');
        $appContainer = $this->app->getContainer();

        // Get real services from the container
        $this->clientMapper = Server::get(ClientMapper::class);
        $this->accessTokenMapper = Server::get(AccessTokenMapper::class);
        $this->groupMapper = Server::get(GroupMapper::class);
        $this->userManager = Server::get(IUserManager::class);
        $this->groupManager = Server::get(IGroupManager::class);
        $this->accountManager = Server::get(\OCP\Accounts\IAccountManager::class);
        $this->urlGenerator = Server::get(IURLGenerator::class);
        $this->time = Server::get(ITimeFactory::class);
        $this->secureRandom = Server::get(SecureRandom::class);
        $this->crypto = Server::get(ICrypto::class);
        
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
        
        $this->config = Server::get(IConfig::class);

        // Get app-specific services from the app container
        $this->tokenProvider = $appContainer->get(IProvider::class);

        // Get JwtGenerator and other app services
        $customClaimMapper = Server::get(\OCA\OIDCIdentityProvider\Db\CustomClaimMapper::class);
        $userConfig = Server::get(\OCP\Config\IUserConfig::class);
        
        $customClaimService = new \OCA\OIDCIdentityProvider\Service\CustomClaimService(
            $customClaimMapper,
            $this->userManager,
            $this->groupManager,
            Server::get(\OCP\Group\ISubAdmin::class),
            $this->accountManager,
            $this->logger
        );

        $credentialService = new \OCA\OIDCIdentityProvider\Service\CredentialService(
            Server::get(\OCP\Security\ICredentialsManager::class),
            $appContainer->get(\OCP\AppFramework\Services\IAppConfig::class),
            $this->logger
        );

        $jwtGenerator = new \OCA\OIDCIdentityProvider\Util\JwtGenerator(
            $this->crypto,
            $this->tokenProvider,
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
            $this->clientMapper,
            $this->groupMapper,
            $this->tokenProvider,
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
            // Delete the test client (this will cascade and delete related redirect URIs and access tokens)
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

    private function createTestClient(): Client
    {
        // Create client - the redirect URIs will be automatically created by ClientMapper
        $client = new Client(
            'Test Client',
            [$this->testRedirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile email',
            '',
            false
        );
        $client->setClientIdentifier($this->testClientId);
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

    private function createAccessToken(Client $client, \OCP\IUser $user, string $scope = 'openid profile email'): array
    {
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($user->getUID());
        $accessToken->setScope($scope);
        
        // Generate a code
        $rawCode = $this->secureRandom->generate(64, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $hashedCode = hash('sha512', $rawCode);
        $accessToken->setHashedCode($hashedCode);
        
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('test-nonce-' . $this->secureRandom->generate(16));
        
        $insertedToken = $this->accessTokenMapper->insert($accessToken);
        
        return ['token' => $insertedToken, 'rawCode' => $rawCode];
    }

    /**
     * Test the complete OpenID Connect code flow
     */
    public function testOIDCCodeFlow(): void
    {
        // Step 1: Create a test client
        $client = $this->createTestClient();
        $this->assertNotNull($client->getId(), 'Client was not created successfully');
        $this->assertEquals($this->testClientId, $client->getClientIdentifier(), 'Client identifier does not match');

        // Step 2: Create a test user
        $user = $this->createTestUser();
        $this->assertNotNull($user, 'User was not created successfully');
        $this->assertEquals($this->testUserId, $user->getUID(), 'User ID does not match');

        // Step 3: Create an access token (simulating authorization code grant)
        $scope = 'openid profile email';
        $tokenResult = $this->createAccessToken($client, $user, $scope);
        $accessToken = $tokenResult['token'];
        $this->assertNotNull($accessToken->getId(), 'Access token was not created successfully');
        $this->assertNotEmpty($accessToken->getHashedCode(), 'Access token code is empty');

        // Step 4: Exchange the authorization code for tokens
        $rawCode = $tokenResult['rawCode'];
        
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            null
        );

        // Verify the token response
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        $this->assertEquals(200, $response->getStatus(), 'Token endpoint returned non-200 status');

        $responseData = $response->getData();
        $this->assertArrayHasKey('access_token', $responseData, 'Response missing access_token');
        $this->assertArrayHasKey('token_type', $responseData, 'Response missing token_type');
        $this->assertArrayHasKey('expires_in', $responseData, 'Response missing expires_in');
        $this->assertArrayHasKey('id_token', $responseData, 'Response missing id_token');
        $this->assertEquals('Bearer', $responseData['token_type'], 'Token type is not Bearer');

        // Verify the tokens are not empty
        $this->assertNotEmpty($responseData['access_token'], 'Access token is empty');
        $this->assertNotEmpty($responseData['id_token'], 'ID token is empty');

        // Step 5: Use the access token to get user info
        $accessTokenString = $responseData['access_token'];
        
        // Set up the request to include the Authorization header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessTokenString;
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessTokenString;

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

        // Verify profile claims are present (since we requested 'profile' scope)
        $this->assertArrayHasKey('name', $userInfoData, 'UserInfo missing name claim');
        
        // Verify scope claim is present
        $this->assertArrayHasKey('scope', $userInfoData, 'UserInfo missing scope claim');
        $this->assertStringContainsString('openid', $userInfoData['scope'], 'Scope does not contain openid');
        $this->assertStringContainsString('profile', $userInfoData['scope'], 'Scope does not contain profile');
        $this->assertStringContainsString('email', $userInfoData['scope'], 'Scope does not contain email');

        // Verify email claim if user has email
        if ($user->getEMailAddress() !== null) {
            $this->assertArrayHasKey('email', $userInfoData, 'UserInfo missing email claim');
            $this->assertEquals($user->getEMailAddress(), $userInfoData['email'], 'Email does not match user email');
        }
    }

    /**
     * Test token exchange with invalid client credentials
     */
    public function testTokenExchangeWithInvalidClient(): void
    {
        // Create a test client
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Create an access token
        $tokenResult = $this->createAccessToken($client, $user);
        $rawCode = $tokenResult['rawCode'];

        // Try to exchange with wrong client secret
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            'wrong-secret',
            null
        );

        // Verify error response
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        $this->assertEquals(400, $response->getStatus(), 'Token endpoint should return 400 for invalid client');

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData, 'Response missing error field');
        $this->assertEquals('invalid_client', $responseData['error'], 'Error should be invalid_client');
    }

    /**
     * Test user info with invalid access token
     */
    public function testUserInfoWithInvalidToken(): void
    {
        // Set up the request with an invalid token
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-xyz';
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-xyz';

        $userInfoResponse = $this->userInfoController->getInfo();

        // Verify error response
        $this->assertInstanceOf(JSONResponse::class, $userInfoResponse, 'UserInfo response is not a JSONResponse');
        $this->assertEquals(400, $userInfoResponse->getStatus(), 'UserInfo endpoint should return 400 for invalid token');

        $responseData = $userInfoResponse->getData();
        $this->assertArrayHasKey('error', $responseData, 'Response missing error field');
        $this->assertEquals('invalid_request', $responseData['error'], 'Error should be invalid_request');
    }

    /**
     * Test user info with missing access token
     */
    public function testUserInfoWithMissingToken(): void
    {
        // Clear any existing auth header
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->request->server = [];

        $userInfoResponse = $this->userInfoController->getInfo();

        // Verify error response
        $this->assertInstanceOf(JSONResponse::class, $userInfoResponse, 'UserInfo response is not a JSONResponse');
        $this->assertEquals(400, $userInfoResponse->getStatus(), 'UserInfo endpoint should return 400 for missing token');

        $responseData = $userInfoResponse->getData();
        $this->assertArrayHasKey('error', $responseData, 'Response missing error field');
        $this->assertEquals('invalid_request', $responseData['error'], 'Error should be invalid_request');
    }

    /**
     * Generate PKCE code challenge and verifier
     */
    private function generatePKCEChallenge(string $method = 'S256'): array
    {
        // Generate code verifier: 43-128 unreserved characters
        $codeVerifier = $this->secureRandom->generate(128, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~');
        
        if ($method === 'S256') {
            // S256: code_challenge = BASE64URL(SHA256(code_verifier))
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        } else {
            // plain: code_challenge = code_verifier
            $codeChallenge = $codeVerifier;
        }
        
        return [
            'code_verifier' => $codeVerifier,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $method
        ];
    }

    /**
     * Create access token with PKCE parameters
     */
    private function createAccessTokenWithPKCE(Client $client, \OCP\IUser $user, string $codeChallenge, string $codeChallengeMethod, string $scope = 'openid profile email'): array
    {
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($user->getUID());
        $accessToken->setScope($scope);
        
        // Generate authorization code
        $rawCode = $this->secureRandom->generate(64, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $hashedCode = hash('sha512', $rawCode);
        $accessToken->setHashedCode($hashedCode);
        
        // Store PKCE parameters
        $accessToken->setCodeChallenge($codeChallenge);
        $accessToken->setCodeChallengeMethod($codeChallengeMethod);
        
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('test-nonce-' . $this->secureRandom->generate(16));
        
        $insertedToken = $this->accessTokenMapper->insert($accessToken);
        
        return ['token' => $insertedToken, 'rawCode' => $rawCode];
    }

    /**
     * Test code flow with PKCE using S256 (recommended)
     */
    public function testOIDCCodeFlowWithPKCES256(): void
    {
        // Step 1: Create test client, user
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Step 2: Generate PKCE challenge and verifier
        $pkce = $this->generatePKCEChallenge('S256');
        $codeChallenge = $pkce['code_challenge'];
        $codeChallengeMethod = $pkce['code_challenge_method'];
        $codeVerifier = $pkce['code_verifier'];

        // Step 3: Create authorization code with PKCE parameters
        $tokenResult = $this->createAccessTokenWithPKCE($client, $user, $codeChallenge, $codeChallengeMethod);
        $accessToken = $tokenResult['token'];
        $rawCode = $tokenResult['rawCode'];

        // Verify PKCE parameters are stored
        $this->assertEquals($codeChallenge, $accessToken->getCodeChallenge(), 'Code challenge not stored correctly');
        $this->assertEquals('S256', $accessToken->getCodeChallengeMethod(), 'Code challenge method should be S256');

        // Step 4: Exchange authorization code for tokens, providing code_verifier
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            $codeVerifier  // PKCE code_verifier
        );

        // Verify successful token response
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        $this->assertEquals(200, $response->getStatus(), 'Token endpoint should accept valid PKCE code_verifier');

        $responseData = $response->getData();
        $this->assertArrayHasKey('access_token', $responseData, 'Response missing access_token');
        $this->assertArrayHasKey('id_token', $responseData, 'Response missing id_token');
        $this->assertNotEmpty($responseData['access_token'], 'Access token is empty');
    }

    /**
     * Test code flow with PKCE using plain method
     */
    public function testOIDCCodeFlowWithPKCEPlain(): void
    {
        // Step 1: Create test client, user
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Step 2: Generate PKCE challenge and verifier (plain method)
        $pkce = $this->generatePKCEChallenge('plain');
        $codeChallenge = $pkce['code_challenge'];
        $codeChallengeMethod = $pkce['code_challenge_method'];
        $codeVerifier = $pkce['code_verifier'];

        // Verify plain method: verifier and challenge should be identical
        $this->assertEquals($codeVerifier, $codeChallenge, 'Plain method should have identical verifier and challenge');

        // Step 3: Create authorization code with PKCE parameters
        $tokenResult = $this->createAccessTokenWithPKCE($client, $user, $codeChallenge, $codeChallengeMethod);
        $rawCode = $tokenResult['rawCode'];

        // Step 4: Exchange authorization code for tokens with PKCE verifier
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            $codeVerifier  // PKCE code_verifier
        );

        // Verify successful token response
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        $this->assertEquals(200, $response->getStatus(), 'Token endpoint should accept valid PKCE plain verifier');

        $responseData = $response->getData();
        $this->assertArrayHasKey('access_token', $responseData, 'Response missing access_token');
        $this->assertNotEmpty($responseData['access_token'], 'Access token is empty');
    }

    /**
     * Test code flow without PKCE (optional for confidential clients)
     */
    public function testOIDCCodeFlowWithoutPKCE(): void
    {
        // Step 1: Create test client, user
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Step 2: Create authorization code WITHOUT PKCE parameters
        $tokenResult = $this->createAccessToken($client, $user);
        $accessToken = $tokenResult['token'];
        $rawCode = $tokenResult['rawCode'];

        // Verify no PKCE parameters are stored
        $this->assertNull($accessToken->getCodeChallenge(), 'Code challenge should be null without PKCE');
        $this->assertNull($accessToken->getCodeChallengeMethod(), 'Code challenge method should be null without PKCE');

        // Step 3: Exchange authorization code WITHOUT PKCE verifier (should still work for confidential clients)
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            null  // No code_verifier for non-PKCE flow
        );

        // Verify successful token response
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        $this->assertEquals(200, $response->getStatus(), 'Token endpoint should accept code flow without PKCE');

        $responseData = $response->getData();
        $this->assertArrayHasKey('access_token', $responseData, 'Response missing access_token');
        $this->assertNotEmpty($responseData['access_token'], 'Access token is empty');
    }

    /**
     * Test PKCE with mismatched code verifier (security test)
     */
    public function testOIDCCodeFlowWithInvalidPKCEVerifier(): void
    {
        // Step 1: Create test client, user
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Step 2: Generate PKCE challenge and verifier
        $pkce = $this->generatePKCEChallenge('S256');
        $codeChallenge = $pkce['code_challenge'];
        $codeChallengeMethod = $pkce['code_challenge_method'];

        // Step 3: Create authorization code with valid PKCE parameters
        $tokenResult = $this->createAccessTokenWithPKCE($client, $user, $codeChallenge, $codeChallengeMethod);
        $rawCode = $tokenResult['rawCode'];

        // Step 4: Try to exchange with WRONG code_verifier
        $wrongCodeVerifier = $this->secureRandom->generate(128, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~');
        
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            $wrongCodeVerifier  // Wrong PKCE verifier
        );

        // Verify error response (invalid_grant is appropriate for PKCE mismatch)
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        // Should be 400 or 401 for invalid PKCE
        $this->assertThat(
            $response->getStatus(),
            $this->logicalOr($this->equalTo(400), $this->equalTo(401)),
            'Token endpoint should reject mismatched PKCE verifier'
        );

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData, 'Response missing error field');
    }

    /**
     * Test PKCE with missing code verifier when required
     */
    public function testOIDCCodeFlowWithMissingPKCEVerifier(): void
    {
        // Step 1: Create test client, user
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Step 2: Generate PKCE challenge and verifier
        $pkce = $this->generatePKCEChallenge('S256');
        $codeChallenge = $pkce['code_challenge'];
        $codeChallengeMethod = $pkce['code_challenge_method'];

        // Step 3: Create authorization code with PKCE parameters
        $tokenResult = $this->createAccessTokenWithPKCE($client, $user, $codeChallenge, $codeChallengeMethod);
        $rawCode = $tokenResult['rawCode'];

        // Step 4: Try to exchange WITHOUT providing code_verifier
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            null  // Missing code_verifier
        );

        // Verify error response - should reject because PKCE was required
        $this->assertInstanceOf(JSONResponse::class, $response, 'Response is not a JSONResponse');
        $this->assertThat(
            $response->getStatus(),
            $this->logicalOr($this->equalTo(400), $this->equalTo(401)),
            'Token endpoint should reject missing PKCE verifier when PKCE was used'
        );

        $responseData = $response->getData();
        $this->assertArrayHasKey('error', $responseData, 'Response missing error field');
    }

    /**
     * Test complete code flow with PKCE and user info retrieval
     */
    public function testOIDCCodeFlowWithPKCEAndUserInfo(): void
    {
        // Step 1: Create test client and user
        $client = $this->createTestClient();
        $user = $this->createTestUser();

        // Step 2: Generate PKCE challenge
        $pkce = $this->generatePKCEChallenge('S256');
        $codeChallenge = $pkce['code_challenge'];
        $codeChallengeMethod = $pkce['code_challenge_method'];
        $codeVerifier = $pkce['code_verifier'];

        // Step 3: Create authorization code with PKCE
        $scope = 'openid profile email';
        $tokenResult = $this->createAccessTokenWithPKCE($client, $user, $codeChallenge, $codeChallengeMethod, $scope);
        $rawCode = $tokenResult['rawCode'];

        // Step 4: Exchange code for tokens with PKCE verifier
        $response = $this->oidcApiController->getToken(
            'authorization_code',
            $rawCode,
            null,
            $this->testClientId,
            $this->testClientSecret,
            $codeVerifier
        );

        $this->assertEquals(200, $response->getStatus(), 'Token exchange with PKCE should succeed');

        $responseData = $response->getData();
        $accessTokenString = $responseData['access_token'];

        // Step 5: Use access token to retrieve user info
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessTokenString;
        $this->request->server['HTTP_AUTHORIZATION'] = 'Bearer ' . $accessTokenString;

        $userInfoResponse = $this->userInfoController->getInfo();

        // Verify user info response
        $this->assertEquals(200, $userInfoResponse->getStatus(), 'UserInfo endpoint should return 200');

        $userInfoData = $userInfoResponse->getData();
        
        // Verify user info claims
        $this->assertArrayHasKey('sub', $userInfoData, 'UserInfo missing sub claim');
        $this->assertEquals($this->testUserId, $userInfoData['sub'], 'Sub claim should match user ID');
        $this->assertArrayHasKey('scope', $userInfoData, 'UserInfo missing scope claim');
        $this->assertStringContainsString('openid', $userInfoData['scope'], 'Scope should contain openid');
    }
}
