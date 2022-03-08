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

use OCA\OIDCIdentityProvider\Controller\DiscoveryController;

class DiscoveryControllerTest extends TestCase {
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
		$this->controller = new DiscoveryController(
            'oidc',
            $this->request,
			$this->time,
			$this->throttler,
			$this->urlGenerator);
	}

	public function testDiscoveryResponse() {
		$scopesSupported = [
            'openid',
            'profile',
            'email',
            'roles',
            'groups',
        ];

		$result = $this->controller->getInfo();

		$this->assertEquals(Http::STATUS_OK, $result->getStatus());
		$this->assertEquals($scopesSupported, $result->getData()['scopes_supported']);
	}


}
