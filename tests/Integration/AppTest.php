<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\Controller\SessionController;
use OCA\OIDCIdentityProvider\Listener\BackChannelLogoutListener;
use OCA\OIDCIdentityProvider\Listener\SessionManagementLoginListener;
use OCA\OIDCIdentityProvider\Middleware\LogoutMiddleware;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\App;
use PHPUnit\Framework\TestCase;

/** Checks installation and DI wiring of the OIDC session/logout extensions. */
class AppTest extends TestCase {
    private $appContainer;

    protected function setUp(): void {
        parent::setUp();
        $app = new App('oidc');
        $this->appContainer = $app->getContainer();
    }

    public function testAppInstalled(): void {
        $appManager = $this->appContainer->query('OCP\App\IAppManager');
        $this->assertTrue($appManager->isInstalled('oidc'));
    }

    public function testFrontChannelAndSessionManagementServicesAreResolvable(): void {
        $this->assertInstanceOf(
            FrontChannelLogoutService::class,
            $this->appContainer->query(FrontChannelLogoutService::class)
        );
        $this->assertInstanceOf(
            SessionManagementService::class,
            $this->appContainer->query(SessionManagementService::class)
        );
    }

    public function testSessionControllerIsResolvable(): void {
        $this->assertInstanceOf(SessionController::class, $this->appContainer->query(SessionController::class));
    }

    public function testSessionLifecycleComponentsAreResolvable(): void {
        $this->assertInstanceOf(LogoutMiddleware::class, $this->appContainer->query(LogoutMiddleware::class));
        $this->assertInstanceOf(BackChannelLogoutListener::class, $this->appContainer->query(BackChannelLogoutListener::class));
        $this->assertInstanceOf(SessionManagementLoginListener::class, $this->appContainer->query(SessionManagementLoginListener::class));
    }
}
