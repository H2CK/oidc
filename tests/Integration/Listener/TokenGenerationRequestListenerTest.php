<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Integration\Listener;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Listener\TokenGenerationRequestListener;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Integration test for TokenGenerationRequestListener.
 * Tests both positive and negative scenarios for token generation.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class TokenGenerationRequestListenerTest extends \Test\TestCase
{

    private ClientMapper $clientMapper;
    private AccessTokenMapper $accessTokenMapper;
    private $userManager;
    private ITimeFactory $time;
    private ISecureRandom $secureRandom;
    private IURLGenerator $urlGenerator;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    private JwtGenerator $jwtGenerator;

    private TokenGenerationRequestListener $listener;

    private string $testUserId = 'test-oidc-user';
    private string $testClientId = 'test-client-id';
    private string $testClientSecret = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Get real services from the container
        $this->clientMapper = Server::get(ClientMapper::class);
        $this->accessTokenMapper = Server::get(AccessTokenMapper::class);
        $this->userManager = Server::get(IUserManager::class);
        $this->time = Server::get(ITimeFactory::class);
        $this->secureRandom = Server::get(ISecureRandom::class);
        $this->urlGenerator = Server::get(IURLGenerator::class);
        $this->logger = Server::get(LoggerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        // Create JwtGenerator with real dependencies
        $app = new \OCP\AppFramework\App('oidc');
        $appContainer = $app->getContainer();
        $this->jwtGenerator = $appContainer->get(JwtGenerator::class);

        // Create the listener
        $this->listener = new TokenGenerationRequestListener(
            $this->logger,
            $this->secureRandom,
            $this->time,
            $this->appConfig,
            $this->accessTokenMapper,
            $this->jwtGenerator,
            $this->clientMapper,
            $this->urlGenerator
        );

        // Clean up any existing test data
        $this->cleanupTestData();
        $this->setupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function cleanupTestData(): void
    {
        try {
            // Delete the test client (this will cascade and delete related access tokens)
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

    private function setupTestData(): void
    {
        // Create test user if it doesn't exist
        if (!$this->userManager->userExists($this->testUserId)) {
            $this->userManager->createUser($this->testUserId, 'test-password');
        }

        // Create test client if it doesn't exist
        try {
            $existingClient = $this->clientMapper->getByIdentifier($this->testClientId);
            if ($existingClient === null) {
                $client = new Client(
                    'Test Client',
                    ['https://client.example.com/callback'],
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
                $this->clientMapper->insert($client);
            }
        } catch (ClientNotFoundException) {
            $client = new Client(
                'Test Client',
                ['https://client.example.com/callback'],
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
            $this->clientMapper->insert($client);
        }
    }

    /**
     * Test token generation with valid client and user (positive scenario)
     */
    public function testTokenGenerationWithValidClientAndUser(): void
    {
        // Setup app config mock
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME => Application::DEFAULT_REFRESH_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME => Application::DEFAULT_CLIENT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });

        // Create event
        $event = new TokenGenerationRequestEvent(
            $this->testClientId,
            $this->testUserId,
            '',
            ''
        );

        // Handle the event
        $this->listener->handle($event);

        // Verify that tokens were generated
        $this->assertNotNull($event->getAccessToken(), 'Access token should be generated');
        $this->assertNotNull($event->getIdToken(), 'ID token should be generated');
        $this->assertNotNull($event->getRefreshToken(), 'Refresh token should be generated');
        $this->assertNotNull($event->getExpiresIn(), 'Expires in should be set');
        $this->assertEquals(Application::DEFAULT_EXPIRE_TIME, $event->getExpiresIn(), 'Expires in should match default');

        // Verify the access token was stored in the database by trying to retrieve it
        try {
            $storedToken = $this->accessTokenMapper->getByAccessToken($event->getAccessToken());
            $this->assertNotNull($storedToken, 'Access token should be stored in database');
            $this->assertEquals($this->testUserId, $storedToken->getUserId(), 'Stored token should have correct user ID');
        } catch (\Exception $e) {
            $this->fail('Failed to retrieve stored access token: ' . $e->getMessage());
        }
    }

    /**
     * Test token generation with extra scopes (positive scenario)
     */
    public function testTokenGenerationWithExtraScopes(): void
    {
        // Setup app config mock
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME => Application::DEFAULT_REFRESH_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME => Application::DEFAULT_CLIENT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });

        $extraScopes = 'custom:scope:read custom:scope:write';

        // Create event with extra scopes
        $event = new TokenGenerationRequestEvent(
            $this->testClientId,
            $this->testUserId,
            $extraScopes,
            ''
        );

        // Handle the event
        $this->listener->handle($event);

        // Verify that tokens were generated
        $this->assertNotNull($event->getAccessToken(), 'Access token should be generated');
        $this->assertNotNull($event->getIdToken(), 'ID token should be generated');

        // Verify the scope in the stored access token includes both default and extra scopes
        try {
            $storedToken = $this->accessTokenMapper->getByAccessToken($event->getAccessToken());
            $scope = $storedToken->getScope();

            $this->assertStringContainsString('openid', $scope, 'Scope should contain default scope');
            $this->assertStringContainsString('profile', $scope, 'Scope should contain default scope');
            $this->assertStringContainsString('email', $scope, 'Scope should contain default scope');
            $this->assertStringContainsString('custom:scope:read', $scope, 'Scope should contain extra scope');
            $this->assertStringContainsString('custom:scope:write', $scope, 'Scope should contain extra scope');
        } catch (\Exception $e) {
            $this->fail('Failed to retrieve stored access token: ' . $e->getMessage());
        }
    }

    /**
     * Test token generation with resource (positive scenario)
     */
    public function testTokenGenerationWithResource(): void
    {
        // Setup app config mock
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME => Application::DEFAULT_REFRESH_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME => Application::DEFAULT_CLIENT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });

        $resource = 'https://api.example.com/resource';

        // Create event with resource
        $event = new TokenGenerationRequestEvent(
            $this->testClientId,
            $this->testUserId,
            '',
            $resource
        );

        // Handle the event
        $this->listener->handle($event);

        // Verify that tokens were generated
        $this->assertNotNull($event->getAccessToken(), 'Access token should be generated');
        $this->assertNotNull($event->getIdToken(), 'ID token should be generated');

        // Verify the resource in the stored access token
        try {
            $storedToken = $this->accessTokenMapper->getByAccessToken($event->getAccessToken());
            $this->assertEquals($resource, $storedToken->getResource(), 'Resource should be set correctly');
        } catch (\Exception $e) {
            $this->fail('Failed to retrieve stored access token: ' . $e->getMessage());
        }
    }

    /**
     * Test token generation with non-existent client (negative scenario)
     */
    public function testTokenGenerationWithNonExistentClient(): void
    {
        // Setup app config mock (won't be used as client won't be found)
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME => Application::DEFAULT_REFRESH_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME => Application::DEFAULT_CLIENT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });

        $nonExistentClientId = 'non-existent-client';

        // Create event with non-existent client
        $event = new TokenGenerationRequestEvent(
            $nonExistentClientId,
            $this->testUserId,
            '',
            ''
        );

        // Handle the event
        $this->listener->handle($event);

        // Verify that tokens were NOT generated
        $this->assertNull($event->getAccessToken(), 'Access token should not be generated for non-existent client');
        $this->assertNull($event->getIdToken(), 'ID token should not be generated for non-existent client');
        $this->assertNull($event->getRefreshToken(), 'Refresh token should not be generated for non-existent client');
    }

    /**
     * Test token generation with expired client (negative scenario)
     */
    public function testTokenGenerationWithExpiredClient(): void
    {
        // Setup app config mock
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME => Application::DEFAULT_REFRESH_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME => Application::DEFAULT_CLIENT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });

        // Clean up any existing expired client from previous runs
        $expiredClientId = 'expired-client-id-' . uniqid();
        $client = new Client(
            'Expired Client',
            ['https://client.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile email',
            '',
            true  // DCR client
        );
        $client->setClientIdentifier($expiredClientId);
        $client->setSecret('expired-secret');
        $client->setIssuedAt($this->time->getTime() - 7200); // Issued 2 hours ago
        $this->clientMapper->insert($client);

        // Create event with expired client
        $event = new TokenGenerationRequestEvent(
            $expiredClientId,
            $this->testUserId,
            '',
            ''
        );

        // Handle the event
        $this->listener->handle($event);

        // Verify that tokens were NOT generated
        $this->assertNull($event->getAccessToken(), 'Access token should not be generated for expired client');
        $this->assertNull($event->getIdToken(), 'ID token should not be generated for expired client');
        $this->assertNull($event->getRefreshToken(), 'Refresh token should not be generated for expired client');

        // Cleanup
        try {
            $expiredClient = $this->clientMapper->getByIdentifier($expiredClientId);
            if ($expiredClient !== null) {
                $this->clientMapper->delete($expiredClient);
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    /**
     * Test that listener ignores non-TokenGenerationRequestEvent events (negative scenario)
     */
    public function testListenerIgnoresOtherEvents(): void
    {
        // Create a mock event that is not a TokenGenerationRequestEvent
        $mockEvent = $this->createMock(\OCP\EventDispatcher\Event::class);

        // Handle the event - should do nothing
        $this->listener->handle($mockEvent);

        // No assertions needed - the method should just return without errors
        $this->assertTrue(true, 'Listener should ignore non-TokenGenerationRequestEvent events');
    }

    /**
     * Test token generation with refresh token expiration set to 'never' (positive scenario)
     */
    public function testTokenGenerationWithRefreshTokenNeverExpires(): void
    {
        // Setup app config mock
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME => 'never',
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME => Application::DEFAULT_CLIENT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });

        // Create event
        $event = new TokenGenerationRequestEvent(
            $this->testClientId,
            $this->testUserId,
            '',
            ''
        );

        // Handle the event
        $this->listener->handle($event);

        // Verify that tokens were generated
        $this->assertNotNull($event->getAccessToken(), 'Access token should be generated');
        $this->assertNotNull($event->getIdToken(), 'ID token should be generated');
        $this->assertNotNull($event->getRefreshToken(), 'Refresh token should be generated');
        $this->assertNull($event->getRefreshExpiresIn(), 'Refresh expires in should be null when set to never');

        // Cleanup the generated token
        try {
            $storedToken = $this->accessTokenMapper->getByAccessToken($event->getAccessToken());
            $this->accessTokenMapper->delete($storedToken);
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }
}
