<?php

namespace OCA\OIDCIdentityProvider\Tests\Integration\Controller;

use OCP\AppFramework\App;
use PHPUnit\Framework\TestCase;


/**
 * This test just checks if the application is installed and enabled.
 */
class AppTest extends TestCase {

    private $appContainer;

    public function setUp(): void {
        parent::setUp();
        $app = new App('oidc');
        $this->appContainer = $app->getContainer();
    }

    public function testAppInstalled() {
        $appManager = $this->appContainer->query('OCP\App\IAppManager');
        $this->assertTrue($appManager->isInstalled('oidc'));
    }

}
