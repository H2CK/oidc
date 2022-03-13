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
use OCP\ILogger;
use OCP\AppFramework\Services\IAppConfig;


use OCA\OIDCIdentityProvider\Controller\JwksController;

class JwksControllerTest extends TestCase {
	protected $controller;
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
	/** @var ILogger */
	private $logger;

	public function setUp(): void {
		$this->request = $this->getMockBuilder(IRequest::class)->getMock();
		$this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
		$this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
		$this->logger = $this->getMockBuilder(ILogger::class)->getMock();
		$this->config = $this->getMockBuilder(IConfig::class)->getMock();
		$this->throttler = $this->getMockBuilder(Throttler::class)->setConstructorArgs([$this->db,
																						$this->time,
																						$this->logger,
																						$this->config])->getMock();
		$this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
		$this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
		$this->controller = new JwksController(
            'oidc',
            $this->request,
			$this->time,
			$this->throttler,
			$this->urlGenerator,
			$this->appConfig
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

        $use = [
            'sig',
            // 'enc',
        ];

        $oidcKey = [
            'kty' => 'RSA',
            'use' => $use,
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
