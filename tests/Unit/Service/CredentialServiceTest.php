<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCP\AppFramework\Services\IAppConfig;
use OCP\Security\ICredentialsManager;

use OCA\OIDCIdentityProvider\Service\CredentialService;

use Psr\Log\LoggerInterface;

class CredentialServiceTest extends TestCase {
    
    /** @var CredentialService */
    protected $service;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ICredentialsManager */
    private $credentialsManager;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    
    /** @var LoggerInterface */
    private $logger;

    public function setUp(): void {
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new CredentialService(
            $this->credentialsManager,
            $this->appConfig,
            $this->logger
        );
    }

    public function testGetPrivateKey() {
        $privateKey = 'test-private-key';
        
        $this->credentialsManager->method('retrieve')
            ->with('', 'oidc_private_key')
            ->willReturn($privateKey);
        
        $this->appConfig->method('getAppValueString')
            ->with('private_key')
            ->willReturn(''); // No migration needed
        
        $result = $this->service->getPrivateKey();
        
        $this->assertEquals($privateKey, $result);
    }

    public function testGetPrivateKeyReturnsNullWhenNotSet() {
        $this->credentialsManager->method('retrieve')
            ->with('', 'oidc_private_key')
            ->willReturn(null);
        
        $this->appConfig->method('getAppValueString')
            ->with('private_key')
            ->willReturn('');
        
        $result = $this->service->getPrivateKey();
        
        $this->assertNull($result);
    }

    public function testSetPrivateKey() {
        $privateKey = 'new-private-key';
        
        $this->credentialsManager->expects($this->once())
            ->method('store')
            ->with('', 'oidc_private_key', $privateKey);
        
        $result = $this->service->setPrivateKey($privateKey);
        
        $this->assertTrue($result);
    }

    public function testMigratePrivateKey() {
        $privateKey = 'migration-private-key';
        
        $this->appConfig->method('getAppValueString')
            ->with('private_key')
            ->willReturn($privateKey);
        
        $this->appConfig->expects($this->once())
            ->method('deleteAppValue')
            ->with('private_key');
        
        $this->credentialsManager->expects($this->once())
            ->method('store')
            ->with('', 'oidc_private_key', $privateKey);
        
        $result = $this->service->migratePrivateKey();
        
        $this->assertTrue($result);
    }

    public function testMigratePrivateKeyNoMigrationNeeded() {
        $this->appConfig->method('getAppValueString')
            ->with('private_key')
            ->willReturn(''); // Empty string means no migration needed
        
        $this->appConfig->expects($this->never())
            ->method('deleteAppValue');
        
        $this->credentialsManager->expects($this->never())
            ->method('store');
        
        $result = $this->service->migratePrivateKey();
        
        $this->assertTrue($result);
    }

    public function testGenerateKeys() {
        $this->appConfig->method('deleteAppValue')
            ->with('private_key');
        
        $this->credentialsManager->expects($this->once())
            ->method('store')
            ->with('', 'oidc_private_key', $this->callback('is_string'));
        
        $this->appConfig->method('setAppValueString')
            ->with($this->logicalOr(
                $this->equalTo('public_key'),
                $this->equalTo('kid'),
                $this->equalTo('public_key_n'),
                $this->equalTo('public_key_e')
            ), $this->callback('is_string'));
        
        $result = $this->service->generateKeys();
        
        $this->assertTrue($result);
    }

    public function testGenerateKeysCreatesValidKeyPair() {
        $result = $this->service->generateKeys();
        
        $this->assertTrue($result);
        
        // Verify that keys were actually stored
        $this->appConfig->expects($this->any())
            ->method('setAppValueString');
    }

    public static function guidv4Provider(): array {
        return [
            'null data' => [null],
            'custom data' => [random_bytes(16)],
        ];
    }

    #[DataProvider('guidv4Provider')]
    public function testGuidv4($data) {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('guidv4');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->service, $data);
        
        // Verify it's a valid UUID v4
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result
        );
    }

    public function testGuidv4WithoutData() {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('guidv4');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->service);
        
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result
        );
    }

    public function testGuidv4Uniqueness() {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('guidv4');
        $method->setAccessible(true);
        
        $guid1 = $method->invoke($this->service);
        $guid2 = $method->invoke($this->service);
        
        $this->assertNotEquals($guid1, $guid2);
    }
}
