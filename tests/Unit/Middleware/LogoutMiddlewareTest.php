<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Middleware;

use OCA\OIDCIdentityProvider\Middleware\LogoutMiddleware;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class LogoutMiddlewareTest extends TestCase {
    public function testNormalCoreLogoutFansOutBeforeBackChannelStateIsCleared(): void {
        $backChannel = $this->createMock(BackChannelLogoutService::class);
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('isLoggedIn')->willReturn(true);

        $backChannel->expects($this->once())
            ->method('getCurrentClientSessions')
            ->willReturn(['7' => 'sid-7']);
        $frontChannel->expects($this->once())
            ->method('getLogoutUris')
            ->with(['7' => 'sid-7'])
            ->willReturn(['https://rp.example/frontchannel?iss=x&sid=sid-7']);

        $replacement = new DataDisplayResponse('logout');
        $replacement->addHeader('Content-Security-Policy', "default-src 'none'; frame-src https://rp.example");
        $frontChannel->expects($this->once())
            ->method('createBrowserLogoutResponse')
            ->with(['https://rp.example/frontchannel?iss=x&sid=sid-7'], 'https://op.example/login')
            ->willReturn($replacement);
        $sessionManagement->expects($this->never())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');

        $middleware = new LogoutMiddleware($backChannel, $frontChannel, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(\OC\Core\Controller\LoginController::class)
            ->disableOriginalConstructor()
            ->getMock();

        $middleware->beforeController($controller, 'logout');
        $coreResponse = new RedirectResponse('https://op.example/login');
        $coreResponse->addHeader('Clear-Site-Data', '"cache"');
        $coreResponse->addHeader('Content-Security-Policy', "default-src 'self'");
        $result = $middleware->afterController($controller, 'logout', $coreResponse);

        $this->assertSame($replacement, $result);
        $this->assertSame('"cache"', $result->getHeaders()['Clear-Site-Data'] ?? null);
        $this->assertSame("default-src 'none'; frame-src https://rp.example", $result->getHeaders()['Content-Security-Policy'] ?? null);
    }

    public function testAuthenticatedNonLogoutRequestRefreshesSessionManagementState(): void {
        $backChannel = $this->createMock(BackChannelLogoutService::class);
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('isLoggedIn')->willReturn(true);

        $sessionManagement->expects($this->once())->method('refreshBrowserStateValidity');
        $response = new DataDisplayResponse('ok');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie')->with($response);
        $backChannel->expects($this->never())->method('getCurrentClientSessions');
        $frontChannel->expects($this->never())->method('getLogoutUris');

        $middleware = new LogoutMiddleware($backChannel, $frontChannel, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $middleware->beforeController($controller, 'index');

        $this->assertSame($response, $middleware->afterController($controller, 'index', $response));
    }

    public function testAnonymousCoreLogoutDoesNotFanOut(): void {
        $backChannel = $this->createMock(BackChannelLogoutService::class);
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('isLoggedIn')->willReturn(false);

        $backChannel->expects($this->never())->method('getCurrentClientSessions');
        $frontChannel->expects($this->never())->method('getLogoutUris');

        $middleware = new LogoutMiddleware($backChannel, $frontChannel, $sessionManagement, $userSession);
        $controller = $this->getMockBuilder(\OC\Core\Controller\LoginController::class)
            ->disableOriginalConstructor()
            ->getMock();
        $middleware->beforeController($controller, 'logout');

        $response = new RedirectResponse('https://op.example/login');
        $sessionManagement->expects($this->never())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');
        $frontChannel->expects($this->once())
            ->method('createBrowserLogoutResponse')
            ->with([], 'https://op.example/login')
            ->willReturn($response);

        $this->assertSame($response, $middleware->afterController($controller, 'logout', $response));
    }
}
