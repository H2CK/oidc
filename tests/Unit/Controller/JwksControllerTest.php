<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Http;
use OCP\IRequest;
use OC\Security\Bruteforce\Throttler;
use OC\Security\Ip\BruteforceAllowList;
use OC\Security\Ip\Factory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OC\Security\Bruteforce\Backend\IBackend;
use OCP\AppFramework\Services\IAppConfig;


use OCA\OIDCIdentityProvider\Controller\JwksController;

class JwksControllerTest extends TestCase {
    protected $controller;
    /** @var IRequest */
    protected $request;
    /** @var ITimeFactory */
    private $time;
    /** @var Throttler */
    private $throttler;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IDBConnection */
    private $db;
    /** @var IConfig */
    private $config;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;
    /** @var IBackend */
    private $throttlerBackend;
    /** @var BruteforceAllowList */
    private $bruteforceAllowList;

    public function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->throttlerBackend = $this->createMock(IBackend::class);
        $this->config = $this->createMock(IConfig::class);
        $appConfigMock = $this->createMock(\OCP\IAppConfig::class);
        $this->bruteforceAllowList = new BruteforceAllowList($appConfigMock, new Factory());
        
        // Create throttler with constructor arguments
        $this->throttler = $this->createMock(Throttler::class);
        $reflection = new \ReflectionClass(Throttler::class);
        $constructor = $reflection->getConstructor();
        $constructor->invoke($this->throttler, $this->time, $this->logger, $this->config, $this->throttlerBackend, $this->bruteforceAllowList);
        
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->controller = new JwksController(
            'oidc',
            $this->request,
            $this->time,
            $this->throttler,
            $this->urlGenerator,
            $this->appConfig,
            $this->logger
        );
    }

    public function testDiscoveryResponse() {
        $keyOps = [
            // 'sign',       // (compute digital signature or MAC)
            'verify',     // (verify digital signature or MAC)
            // 'encrypt',    // (encrypt content)
            // 'decrypt',    // (decrypt content and validate decryption, if applicable)
            // 'wrapKey',    // (encrypt key)
            // 'unwrapKey',  // (decrypt key and validate decryption, if applicable)
            // 'deriveKey',  // (derive key)
            // 'deriveBits', // (derive bits not to be used as a key)
        ];

        $oidcKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'key_ops' => $keyOps,
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValueString('kid'),
            'n' => $this->appConfig->getAppValueString('public_key_n'),
            'e' => $this->appConfig->getAppValueString('public_key_e'),
        ];

        $result = $this->controller->getKeyInfo();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertEquals($oidcKey, $result->getData()['keys'][0]);
    }


}
