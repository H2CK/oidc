<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Http;
use OCP\IRequest;
use OC\Security\Bruteforce\Throttler;
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

	public function setUp(): void {
		$this->request = $this->getMockBuilder(IRequest::class)->getMock();
		$this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
		$this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
		$this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
		$this->throttlerBackend = $this->getMockBuilder(IBackend::class)->getMock();
		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
		$this->throttler = $this->getMockBuilder(Throttler::class)->setConstructorArgs([$this->time,
																						$this->logger,
																						$this->config,
																						$this->throttlerBackend])->getMock();
		$this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
		$this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
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
            'kid' => $this->appConfig->getAppValue('kid'),
            'n' => $this->appConfig->getAppValue('public_key_n'),
            'e' => $this->appConfig->getAppValue('public_key_e'),
        ];

		$result = $this->controller->getKeyInfo();

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$this->assertEquals($oidcKey, $result->getData()['keys'][0]);
	}


}
