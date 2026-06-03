<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCA\OIDCIdentityProvider\Db\RegistrationToken;
use OCA\OIDCIdentityProvider\Db\RegistrationTokenMapper;
use OCA\OIDCIdentityProvider\Service\RegistrationTokenService;
use OCP\Security\ISecureRandom;
use OCP\AppFramework\Db\DoesNotExistException;

use Psr\Log\LoggerInterface;

class RegistrationTokenServiceTest extends TestCase {
    
    /** @var RegistrationTokenService */
    protected $service;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|RegistrationTokenMapper */
    private $tokenMapper;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISecureRandom */
    private $secureRandom;
    
    /** @var LoggerInterface */
    private $logger;

    public function setUp(): void {
        $this->tokenMapper = $this->createMock(RegistrationTokenMapper::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new RegistrationTokenService(
            $this->tokenMapper,
            $this->secureRandom,
            $this->logger
        );
    }

    public function testGenerateToken() {
        $clientId = 123;
        $expiresAt = time() + 3600;
        
        $tokenString = 'test-token-string';
        $createdAt = time();
        
        $this->secureRandom->method('generate')
            ->with(64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789')
            ->willReturn($tokenString);
        
        $this->tokenMapper->method('insert')
            ->willReturnCallback(function($token) use ($clientId, $tokenString, $createdAt, $expiresAt) {
                $this->assertEquals($clientId, $token->getClientId());
                $this->assertEquals($tokenString, $token->getToken());
                $this->assertEquals($createdAt, $token->getCreatedAt());
                $this->assertEquals($expiresAt, $token->getExpiresAt());
                return $token;
            });
        
        $result = $this->service->generateToken($clientId, $expiresAt);
        
        $this->assertInstanceOf(RegistrationToken::class, $result);
    }

    public function testGenerateTokenWithNullExpiry() {
        $clientId = 123;
        $tokenString = 'test-token-string';
        $createdAt = time();
        
        $this->secureRandom->method('generate')
            ->willReturn($tokenString);
        
        $this->tokenMapper->method('insert')
            ->willReturnCallback(function($token) {
                return $token;
            });
        
        $result = $this->service->generateToken($clientId, null);
        
        $this->assertInstanceOf(RegistrationToken::class, $result);
    }

    public function testRotateTokenWithExistingToken() {
        $clientId = 123;
        $expiresAt = time() + 3600;
        $now = time();
        
        // Create a real old token
        $oldToken = new RegistrationToken();
        $oldToken->id = 1;
        
        $newToken = new RegistrationToken();
        
        $tokenString = 'new-token-string';
        
        $this->tokenMapper->method('getCurrentToken')
            ->with($clientId)
            ->willReturn($oldToken);
        
        $this->secureRandom->method('generate')
            ->willReturn($tokenString);
        
        $this->tokenMapper->method('update')
            ->with($this->callback(function($t) use ($oldToken) {
                return $t === $oldToken;
            }))
            ->willReturn($oldToken);
        
        $this->tokenMapper->method('insert')
            ->willReturn($newToken);
        
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Rotated registration token for client', $this->anything());
        
        $result = $this->service->rotateToken($clientId, $expiresAt);
        
        $this->assertInstanceOf(RegistrationToken::class, $result);
        
        // Verify old token was set to expire in grace period
        $this->assertEquals($now + 60, $oldToken->getExpiresAt());
    }

    public function testRotateTokenWithNoExistingToken() {
        $clientId = 123;
        $expiresAt = time() + 3600;
        
        $newToken = new RegistrationToken();
        
        $tokenString = 'new-token-string';
        
        $this->tokenMapper->method('getCurrentToken')
            ->with($clientId)
            ->willReturn(null);
        
        $this->secureRandom->method('generate')
            ->willReturn($tokenString);
        
        $this->tokenMapper->method('insert')
            ->willReturn($newToken);
        
        $this->logger->expects($this->never())
            ->method('debug');
        
        $result = $this->service->rotateToken($clientId, $expiresAt);
        
        $this->assertInstanceOf(RegistrationToken::class, $result);
    }

    public function testValidateTokenSuccess() {
        $tokenString = 'valid-token';
        $clientId = 123;
        $expiresAt = time() + 3600;
        
        $registrationToken = new RegistrationToken();
        $registrationToken->id = 1;
        $registrationToken->setClientId($clientId);
        $registrationToken->setExpiresAt($expiresAt);
        
        $this->tokenMapper->method('getByToken')
            ->with($tokenString)
            ->willReturn($registrationToken);
        
        $result = $this->service->validateToken($tokenString);
        
        $this->assertEquals($clientId, $result);
    }

    public function testValidateTokenExpired() {
        $tokenString = 'expired-token';
        $clientId = 123;
        $expiresAt = time() - 3600; // Expired 1 hour ago
        
        $registrationToken = new RegistrationToken();
        $registrationToken->id = 1;
        $registrationToken->setClientId($clientId);
        $registrationToken->setExpiresAt($expiresAt);
        
        $this->tokenMapper->method('getByToken')
            ->with($tokenString)
            ->willReturn($registrationToken);
        
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Registration token expired', $this->anything());
        
        $result = $this->service->validateToken($tokenString);
        
        $this->assertNull($result);
    }

    public function testValidateTokenNotFound() {
        $tokenString = 'non-existent-token';
        
        $this->tokenMapper->method('getByToken')
            ->with($tokenString)
            ->willThrowException(new DoesNotExistException('Token not found'));
        
        $result = $this->service->validateToken($tokenString);
        
        $this->assertNull($result);
    }

    public function testValidateTokenWithNullExpiry() {
        $tokenString = 'valid-token-no-expiry';
        $clientId = 123;
        
        $registrationToken = new RegistrationToken();
        $registrationToken->id = 1;
        $registrationToken->setClientId($clientId);
        $registrationToken->setExpiresAt(null); // No expiration
        
        $this->tokenMapper->method('getByToken')
            ->with($tokenString)
            ->willReturn($registrationToken);
        
        $result = $this->service->validateToken($tokenString);
        
        $this->assertEquals($clientId, $result);
    }

    public function testRevokeTokens() {
        $clientId = 123;
        
        $this->tokenMapper->expects($this->once())
            ->method('deleteByClientId')
            ->with($clientId);
        
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Revoked all registration tokens for client', $this->anything());
        
        $this->service->revokeTokens($clientId);
    }

    public static function tokenLengthProvider(): array {
        return [
            'exact length' => [64],
        ];
    }

    #[DataProvider('tokenLengthProvider')]
    public function testTokenLengthConstant(int $expectedLength) {
        $reflection = new \ReflectionClass($this->service);
        $constant = $reflection->getConstant('TOKEN_LENGTH');
        
        $this->assertEquals($expectedLength, $constant);
    }

    public function testTokenCharactersConstant() {
        $reflection = new \ReflectionClass($this->service);
        $constant = $reflection->getConstant('TOKEN_CHARS');
        
        $this->assertEquals('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', $constant);
    }

    public function testGracePeriodConstant() {
        $reflection = new \ReflectionClass($this->service);
        $constant = $reflection->getConstant('GRACE_PERIOD');
        
        $this->assertEquals(60, $constant);
    }

    public function testTokenGenerationUsesCorrectCharacterSet() {
        $clientId = 123;
        
        $tokenString = 'Test123ABC';
        
        $this->secureRandom->expects($this->once())
            ->method('generate')
            ->with(64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');
        
        $this->tokenMapper->method('insert')
            ->willReturn(new RegistrationToken());
        
        $this->service->generateToken($clientId);
    }

    public function testValidateTokenAddsRandomDelayOnException() {
        $tokenString = 'non-existent-token';
        
        $startTime = microtime(true);
        
        $this->tokenMapper->method('getByToken')
            ->with($tokenString)
            ->willThrowException(new DoesNotExistException('Token not found'));
        
        $result = $this->service->validateToken($tokenString);
        
        $endTime = microtime(true);
        $elapsed = ($endTime - $startTime) * 1000; // Convert to milliseconds
        
        $this->assertNull($result);
        
        // The delay should be between 10-50ms
        $this->assertGreaterThanOrEqual(10, $elapsed);
    }
}
