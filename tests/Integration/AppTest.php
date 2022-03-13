<?php

namespace OCA\OIDCIdentityProvider\Tests\Integration\Controller;

use OCP\AppFramework\App;
use Test\TestCase;


/**
 * This test just checks if the application is installed and enabled.
 */
class AppTest extends TestCase {

    private $appContainer;
	private $serverContainer;
	private $appConfig;

    public function setUp(): void {
        parent::setUp();
        $app = new App('oidc');
        $this->appContainer = $app->getContainer();
		$this->serverContainer = $this->appContainer->getServer();
		$this->appConfig = $this->serverContainer->getAppConfig();
    }

    public function testAppInstalled() {
        $appManager = $this->appContainer->query('OCP\App\IAppManager');
        $this->assertTrue($appManager->isInstalled('oidc'));
    }

}
