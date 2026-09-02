<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use OCA\OIDCIdentityProvider\Controller\SessionController;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class SessionControllerTest extends TestCase {
    private SessionManagementService $sessionManagementService;
    private IURLGenerator $urlGenerator;
    private SessionController $controller;

    protected function setUp(): void {
        parent::setUp();
        $request = $this->createMock(IRequest::class);
        $this->sessionManagementService = $this->createMock(SessionManagementService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->controller = new SessionController(
            'oidc',
            $request,
            $this->sessionManagementService,
            $this->urlGenerator,
        );
    }

    public function testCheckDelegatesToSessionManagementService(): void {
        $this->sessionManagementService->expects($this->once())
            ->method('checkSessionState')
            ->with('client-1', 'https://rp.example', 'state-1')
            ->willReturn('unchanged');

        $response = $this->controller->check('client-1', 'state-1', 'https://rp.example');

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(['status' => 'unchanged'], $response->getData());
        $this->assertSame('no-store, no-cache, must-revalidate', $response->getHeaders()['Cache-Control']);
    }

    public function testCheckSessionIframeContainsMessageAndStatusProtocol(): void {
        $statusUrl = 'https://nextcloud.example/index.php/apps/oidc/session/check';
        $this->urlGenerator->expects($this->once())
            ->method('linkToRouteAbsolute')
            ->with('oidc.Session.check', [])
            ->willReturn($statusUrl);

        $response = $this->controller->checkSessionIframe();

        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $html = $response->getData();
        $this->assertStringContainsString('window.addEventListener("message"', $html);
        $this->assertStringContainsString('encodeURIComponent(e.origin)', $html);
        $this->assertStringContainsString('fetch(u,{credentials:"include",cache:"no-store"})', $html);
        $this->assertStringContainsString($statusUrl, $html);
        $headers = $response->getHeaders();
        $this->assertSame('text/html; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('no-store, no-cache, must-revalidate', $headers['Cache-Control']);
        $this->assertStringContainsString("frame-ancestors *", $headers['Content-Security-Policy']);
        $this->assertStringContainsString("connect-src 'self'", $headers['Content-Security-Policy']);
    }
    public function testSessionEndpointsDoNotForceNextcloudSession(): void {
        foreach (['checkSessionIframe', 'check'] as $methodName) {
            $method = new \ReflectionMethod(SessionController::class, $methodName);
            $this->assertSame([], $method->getAttributes(UseSession::class), $methodName . ' must not force a PHP session');
        }
    }

}
