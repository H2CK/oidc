<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Middleware;

use OCA\OIDCIdentityProvider\Middleware\LogoutMiddleware;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutContext;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class LogoutMiddlewareTest extends TestCase {
    public function testEventMarkedLogoutReplacesRedirectWithFrontChannelFanout(): void {
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $context = new FrontChannelLogoutContext($this->createMock(IRequest::class));
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $userSession = $this->createMock(IUserSession::class);

        $context->recordLogout(['https://rp.example/frontchannel?iss=x&sid=sid-7']);
        $replacement = new DataDisplayResponse('logout');
        $replacement->addHeader('Content-Security-Policy', "default-src 'none'; frame-src https://rp.example");
        $frontChannel->expects($this->once())
            ->method('createBrowserLogoutResponse')
            ->with(['https://rp.example/frontchannel?iss=x&sid=sid-7'], 'https://op.example/login')
            ->willReturn($replacement);
        $sessionManagement->expects($this->never())->method('refreshBrowserStateValidity');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');

        $middleware = new LogoutMiddleware($frontChannel, $context, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $response = new RedirectResponse('https://op.example/login');
        $response->addHeader('Clear-Site-Data', '"cache"');
        $response->addHeader('Content-Security-Policy', "default-src 'self'");
        $result = $middleware->afterController($controller, 'anything', $response);

        $this->assertSame($replacement, $result);
        $this->assertSame('"cache"', $result->getHeaders()['Clear-Site-Data'] ?? null);
        $this->assertSame("default-src 'none'; frame-src https://rp.example", $result->getHeaders()['Content-Security-Policy'] ?? null);
    }

    public function testEventMarkedLogoutWithoutFrontChannelTargetsPreservesOriginalResponse(): void {
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->never())->method('createBrowserLogoutResponse');
        $context = new FrontChannelLogoutContext($this->createMock(IRequest::class));
        $context->recordLogout([]);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->never())->method('refreshBrowserStateValidity');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');
        $userSession = $this->createMock(IUserSession::class);

        $middleware = new LogoutMiddleware($frontChannel, $context, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $response = new RedirectResponse('https://op.example/login');

        $this->assertSame($response, $middleware->afterController($controller, 'logout', $response));
    }

    public function testAlreadyRenderedOidcLogoutResponseIsNotWrappedAgain(): void {
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->never())->method('createBrowserLogoutResponse');
        $context = new FrontChannelLogoutContext($this->createMock(IRequest::class));
        $context->recordLogout(['https://rp.example/frontchannel?iss=x&sid=sid-7']);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $userSession = $this->createMock(IUserSession::class);

        $middleware = new LogoutMiddleware($frontChannel, $context, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $response = new DataDisplayResponse('already-rendered-frontchannel-page');

        $this->assertSame($response, $middleware->afterController($controller, 'logout', $response));
    }

    public function testAuthenticatedNonLogoutRequestRefreshesSessionManagementState(): void {
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->never())->method('createBrowserLogoutResponse');
        $context = new FrontChannelLogoutContext($this->createMock(IRequest::class));
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('isLoggedIn')->willReturn(true);

        $sessionManagement->expects($this->once())->method('refreshBrowserStateValidity');
        $response = new DataDisplayResponse('ok');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie')->with($response);

        $middleware = new LogoutMiddleware($frontChannel, $context, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();

        $this->assertSame($response, $middleware->afterController($controller, 'index', $response));
    }

    public function testAnonymousNonLogoutRequestDoesNotRefreshOrFanOut(): void {
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->never())->method('createBrowserLogoutResponse');
        $context = new FrontChannelLogoutContext($this->createMock(IRequest::class));
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->never())->method('refreshBrowserStateValidity');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('isLoggedIn')->willReturn(false);

        $middleware = new LogoutMiddleware($frontChannel, $context, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $response = new RedirectResponse('https://op.example/login');

        $this->assertSame($response, $middleware->afterController($controller, 'index', $response));
    }
}
