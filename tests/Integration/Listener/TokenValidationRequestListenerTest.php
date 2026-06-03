<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Integration\Listener;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Listener\TokenValidationRequestListener;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Integration test for TokenValidationRequestListener.
 * Tests both positive and negative scenarios for token validation.
 */
#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class TokenValidationRequestListenerTest extends \Test\TestCase
{

    private ClientMapper $clientMapper;
    private AccessTokenMapper $accessTokenMapper;
    private $userManager;
    private ITimeFactory $time;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;

    private TokenValidationRequestListener $listener;

    private string $testUserId = 'test-oidc-user';
    private string $testClientId = 'test-client-id';
    private string $testClientSecret = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // Get real services from the container
        $this->clientMapper = Server::get(ClientMapper::class);
        $this->accessTokenMapper = Server::get(AccessTokenMapper::class);
        $this->userManager = Server::get(\OCP\IUserManager::class);
        $this->time = Server::get(ITimeFactory::class);
        $this->logger = Server::get(LoggerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        // Create the listener
        $this->listener = new TokenValidationRequestListener(
            $this->logger,
            $this->time,
            $this->appConfig,
            $this->userManager,
            $this->accessTokenMapper,
            $this->clientMapper
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

    private function setupAppConfigMock(): void
    {
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => 'test-n',
                    'public_key_e' => 'test-e',
                ];
                return $config[$key] ?? $default;
            });
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
     * Test validation of a valid access token (positive scenario)
     */
    public function testValidationOfValidAccessToken(): void
    {
        // Setup app config mock
        $this->setupAppConfigMock();

        // Create a valid access token in the database
        $client = $this->clientMapper->getByIdentifier($this->testClientId);
        $uniqueToken = 'valid-access-token-' . uniqid();
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($this->testUserId);
        $accessToken->setHashedCode(hash('sha512', 'refresh-token-' . uniqid()));
        $accessToken->setScope('openid profile email');
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime() + 900); // Not expired
        $accessToken->setNonce('');
        $accessToken->setAccessToken($uniqueToken);
        $accessToken->setResource(null);

        $this->accessTokenMapper->insert($accessToken);

        // Create event with the valid access token
        $event = new TokenValidationRequestEvent($accessToken->getAccessToken());

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was validated successfully
        $this->assertTrue($event->getIsValid(), 'Token should be valid');
        $this->assertEquals($this->testUserId, $event->getUserId(), 'User ID should be set');
    }

    /**
     * Test validation of an expired access token (negative scenario)
     */
    public function testValidationOfExpiredAccessToken(): void
    {
        // Setup app config mock
        $this->setupAppConfigMock();

        // Create an expired access token in the database
        $client = $this->clientMapper->getByIdentifier($this->testClientId);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($this->testUserId);
        $accessToken->setHashedCode(hash('sha512', 'expired-refresh-token-' . uniqid()));
        $accessToken->setScope('openid profile email');
        $accessToken->setCreated($this->time->getTime() - 1800); // Created 30 minutes ago
        $accessToken->setRefreshed($this->time->getTime() - 1800); // Expired (900 seconds = 15 minutes max)
        $accessToken->setNonce('');
        $accessToken->setAccessToken('expired-access-token-' . uniqid());
        $accessToken->setResource(null);

        $this->accessTokenMapper->insert($accessToken);

        // Create event with the expired access token
        $event = new TokenValidationRequestEvent($accessToken->getAccessToken());

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was marked as invalid
        $this->assertFalse($event->getIsValid(), 'Expired token should be invalid');
        $this->assertNull($event->getUserId(), 'User ID should not be set for invalid token');

        // Verify the expired token was deleted from the database
        try {
            $this->accessTokenMapper->getByAccessToken($accessToken->getAccessToken());
            $this->fail('Expired access token should have been deleted');
        } catch (AccessTokenNotFoundException) {
            // Expected - token should have been deleted
        }
    }

    /**
     * Test validation of a non-existent access token (negative scenario)
     */
    public function testValidationOfNonExistentAccessToken(): void
    {
        // Setup app config mock
        $this->setupAppConfigMock();

        $nonExistentToken = 'non-existent-token-' . uniqid();

        // Create event with a non-existent token
        $event = new TokenValidationRequestEvent($nonExistentToken);

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was marked as invalid
        $this->assertFalse($event->getIsValid(), 'Non-existent token should be invalid');
        $this->assertNull($event->getUserId(), 'User ID should not be set for invalid token');
    }

    /**
     * Test validation of an invalid JWT token (negative scenario)
     */
    public function testValidationOfInvalidJwtToken(): void
    {
        // Setup app config mock with invalid key configuration
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    'kid' => 'invalid-kid', // Invalid kid to cause signature verification failure
                    'public_key_n' => 'invalid-n',
                    'public_key_e' => 'invalid-e',
                ];
                return $config[$key] ?? $default;
            });

        $invalidJwt = 'invalid.jwt.token';

        // Create event with invalid JWT
        $event = new TokenValidationRequestEvent($invalidJwt);

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was marked as invalid
        $this->assertFalse($event->getIsValid(), 'Invalid JWT should be marked as invalid');
        $this->assertNull($event->getUserId(), 'User ID should not be set for invalid JWT');
    }

    /**
     * Test validation of a JWT with unknown audience (negative scenario)
     */
    public function testValidationOfJwtWithUnknownAudience(): void
    {
        // Generate a key pair for testing
        $keyPair = $this->generateTestKeyPair();
        $privateKey = $keyPair['private_key'];
        $publicKeyDetails = $keyPair['public_key_details'];

        // Setup app config mock with the generated public key components
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($publicKeyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($publicKeyDetails['rsa']['e']));

        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) use ($modulus, $exponent) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                ];
                return $config[$key] ?? $default;
            });

        $unknownAudience = 'unknown-client-id';
        $payload = [
            'iss' => 'https://nextcloud.local',
            'sub' => $this->testUserId,
            'aud' => $unknownAudience,
            'exp' => $this->time->getTime() + 3600,
            'iat' => $this->time->getTime(),
            'preferred_username' => $this->testUserId,
        ];

        $jwtToken = JWT::encode($payload, $privateKey, 'RS256', 'test-kid');

        // Create event with the JWT
        $event = new TokenValidationRequestEvent($jwtToken);

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was marked as invalid due to unknown audience
        $this->assertFalse($event->getIsValid(), 'JWT with unknown audience should be invalid');
        $this->assertNull($event->getUserId(), 'User ID should not be set for JWT with unknown audience');
    }

    /**
     * Test validation of a JWT with unknown user (negative scenario)
     */
    public function testValidationOfJwtWithUnknownUser(): void
    {
        // Generate a key pair for testing
        $keyPair = $this->generateTestKeyPair();
        $privateKey = $keyPair['private_key'];
        $publicKeyDetails = $keyPair['public_key_details'];

        // Setup app config mock with the generated public key components
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($publicKeyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($publicKeyDetails['rsa']['e']));

        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) use ($modulus, $exponent) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                ];
                return $config[$key] ?? $default;
            });

        $unknownUserId = 'unknown-user-id';
        $payload = [
            'iss' => 'https://nextcloud.local',
            'sub' => $unknownUserId,
            'aud' => $this->testClientId,
            'exp' => $this->time->getTime() + 3600,
            'iat' => $this->time->getTime(),
            'preferred_username' => $unknownUserId,
        ];

        $jwtToken = JWT::encode($payload, $privateKey, 'RS256', 'test-kid');

        // Create event with the JWT
        $event = new TokenValidationRequestEvent($jwtToken);

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was marked as invalid due to unknown user
        $this->assertFalse($event->getIsValid(), 'JWT with unknown user should be invalid');
        $this->assertNull($event->getUserId(), 'User ID should not be set for JWT with unknown user');
    }

    /**
     * Test that listener ignores non-TokenValidationRequestEvent events (negative scenario)
     */
    public function testListenerIgnoresOtherEvents(): void
    {
        // Create a mock event that is not a TokenValidationRequestEvent
        $mockEvent = $this->createMock(\OCP\EventDispatcher\Event::class);

        // Handle the event - should do nothing
        $this->listener->handle($mockEvent);

        // No assertions needed - the method should just return without errors
        $this->assertTrue(true, 'Listener should ignore non-TokenValidationRequestEvent events');
    }

    /**
     * Test validation of an expired JWT token (negative scenario)
     */
    public function testValidationOfExpiredJwtToken(): void
    {
        // Generate a key pair for testing
        $keyPair = $this->generateTestKeyPair();
        $privateKey = $keyPair['private_key'];
        $publicKeyDetails = $keyPair['public_key_details'];

        // Setup app config mock with the generated public key components
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($publicKeyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($publicKeyDetails['rsa']['e']));

        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function ($key, $default) use ($modulus, $exponent) {
                $config = [
                    Application::APP_CONFIG_DEFAULT_EXPIRE_TIME => Application::DEFAULT_EXPIRE_TIME,
                    'kid' => 'test-kid',
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                ];
                return $config[$key] ?? $default;
            });

        $payload = [
            'iss' => 'https://nextcloud.local',
            'sub' => $this->testUserId,
            'aud' => $this->testClientId,
            'exp' => $this->time->getTime() - 3600, // Expired 1 hour ago
            'iat' => $this->time->getTime() - 7200,
            'preferred_username' => $this->testUserId,
        ];

        $jwtToken = JWT::encode($payload, $privateKey, 'RS256', 'test-kid');

        // Create event with the expired JWT
        $event = new TokenValidationRequestEvent($jwtToken);

        // Handle the event
        $this->listener->handle($event);

        // Verify that the token was marked as invalid
        $this->assertFalse($event->getIsValid(), 'Expired JWT should be invalid');
        $this->assertNull($event->getUserId(), 'User ID should not be set for expired JWT');
    }

    /**
     * Generate a test RSA key pair for JWT testing
     *
     * @return array Array with 'private_key' and 'public_key_details'
     */
    private function generateTestKeyPair(): array
    {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keyPair = openssl_pkey_new($config);
        if ($keyPair === false) {
            throw new \RuntimeException('Failed to generate test key pair');
        }

        $privateKey = '';
        $result = openssl_pkey_export($keyPair, $privateKey);
        if ($result === false) {
            throw new \RuntimeException('Failed to export private key');
        }

        $publicKeyDetails = openssl_pkey_get_details($keyPair);
        if ($publicKeyDetails === false) {
            throw new \RuntimeException('Failed to get public key details');
        }

        return [
            'private_key' => $privateKey,
            'public_key_details' => $publicKeyDetails,
        ];
    }
}
