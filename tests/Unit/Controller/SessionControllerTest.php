<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use OCA\OIDCIdentityProvider\Controller\SessionController;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SessionControllerTest extends TestCase {
    private SessionManagementService $sessionManagementService;
    private SessionController $controller;

    protected function setUp(): void {
        parent::setUp();
        $request = $this->createMock(IRequest::class);
        $this->sessionManagementService = $this->createMock(SessionManagementService::class);
        $this->sessionManagementService->method('getOriginBindingJwk')->willReturn([
            'kty' => 'RSA',
            'n' => 'test-modulus',
            'e' => 'AQAB',
            'alg' => 'RS256',
            'ext' => true,
        ]);
        $this->controller = new SessionController('oidc', $request, $this->sessionManagementService);
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

    public function testCheckSessionIframePerformsLocalWebCryptoCheckWithoutFetch(): void {
        $response = $this->controller->checkSessionIframe();

        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $html = $response->getData();
        $this->assertStringContainsString('window.addEventListener("message"', $html);
        $this->assertStringContainsString('subtle.digest("SHA-256"', $html);
        $this->assertStringContainsString('subtle.verify({name:"RSASSA-PKCS1-v1_5"}', $html);
        $this->assertStringContainsString('subtle.importKey("jwk"', $html);
        $this->assertStringContainsString('WebCrypto unavailable', $html);
        $this->assertStringContainsString('a.length!==5||a[0]!=="3"', $html);
        $this->assertStringContainsString('validBinding(c,e.origin,a[4])', $html);
        $this->assertStringContainsString('document.cookie.split("; ")', $html);
        $this->assertStringContainsString('oidc_opbs', $html);
        $this->assertStringContainsString('observedOpbs?"changed":"error"', $html);
        $this->assertStringNotContainsString('fetch(', $html);
        $headers = $response->getHeaders();
        $this->assertSame('text/html; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('no-store, no-cache, must-revalidate', $headers['Cache-Control']);
        $this->assertStringContainsString("frame-ancestors *", $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('connect-src', $headers['Content-Security-Policy']);
    }

    public function testIframeDistinguishesInitiallyBlockedCookieFromLaterExpiry(): void {
        $html = $this->controller->checkSessionIframe()->getData();
        $this->assertStringContainsString('if(readOpbs()!==null){observedOpbs=true;}', $html);
        $this->assertStringContainsString('opbs===null){e.source.postMessage(observedOpbs?"changed":"error"', $html);
    }

    public function testSessionEndpointsDoNotForceNextcloudSessionAndCheckIsRateLimited(): void {
        foreach (['checkSessionIframe', 'check'] as $methodName) {
            $method = new \ReflectionMethod(SessionController::class, $methodName);
            $this->assertSame([], $method->getAttributes(UseSession::class), $methodName . ' must not force a PHP session');
        }
        $check = new \ReflectionMethod(SessionController::class, 'check');
        $this->assertCount(1, $check->getAttributes(AnonRateLimit::class));
    }
}
