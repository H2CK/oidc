<?php

namespace OCA\OIDCIdentityProvider\Tests\Integration\Controller;

use OCP\AppFramework\App;
use Test\TestCase;


/**
 * This test just checks if the application is installed and enabled.
 */
class AppTest extends TestCase {

    private $container;

    public function setUp(): void {
        parent::setUp();
        $app = new App('oidc');
        $this->container = $app->getContainer();
    }

    public function testAppInstalled() {
        $appManager = $this->container->query('OCP\App\IAppManager');
        $this->assertTrue($appManager->isInstalled('oidc'));
    }

}
