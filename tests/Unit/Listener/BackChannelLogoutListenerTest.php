<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use OCA\OIDCIdentityProvider\Listener\BackChannelLogoutListener;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutContext;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackChannelLogoutListenerTest extends TestCase {
    public function testLogoutWithoutUserCapturesFrontChannelTargetsAndUsesSidOnlyPath(): void {
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->method('isReauthenticationSuppressed')->willReturn(false);
        $service->expects($this->once())
            ->method('getCurrentClientSessions')
            ->willReturn(['7' => 'sid-7']);
        $service->expects($this->once())
            ->method('logout')
            ->with(null);

        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->once())
            ->method('getLogoutUris')
            ->with(['7' => 'sid-7'])
            ->willReturn(['https://rp.example/frontchannel?iss=x&sid=sid-7']);

        $context = $this->createMock(FrontChannelLogoutContext::class);
        $context->expects($this->once())
            ->method('recordLogout')
            ->with(['https://rp.example/frontchannel?iss=x&sid=sid-7']);

        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->once())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->once())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie');

        $listener = new BackChannelLogoutListener($service, $frontChannel, $context, $sessionManagement, $this->createMock(LoggerInterface::class));
        $listener->handle(new BeforeUserLoggedOutEvent(null));
    }

    public function testLogoutWithUserCapturesTargetsBeforeBackChannelStateIsCleared(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        $service = $this->createMock(BackChannelLogoutService::class);
        $service->method('isReauthenticationSuppressed')->willReturn(false);
        $service->expects($this->once())
            ->method('getCurrentClientSessions')
            ->willReturn(['9' => 'sid-9']);
        $service->expects($this->once())
            ->method('logout')
            ->with('test-user');

        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->once())
            ->method('getLogoutUris')
            ->with(['9' => 'sid-9'])
            ->willReturn(['https://rp.example/logout?iss=x&sid=sid-9']);

        $context = $this->createMock(FrontChannelLogoutContext::class);
        $context->expects($this->once())
            ->method('recordLogout')
            ->with(['https://rp.example/logout?iss=x&sid=sid-9']);

        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->once())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->once())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie');

        $listener = new BackChannelLogoutListener($service, $frontChannel, $context, $sessionManagement, $this->createMock(LoggerInterface::class));
        $listener->handle(new BeforeUserLoggedOutEvent($user));
    }

    public function testReauthenticationDoesNotCreateFrontChannelLogoutContext(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        $service = $this->createMock(BackChannelLogoutService::class);
        $service->method('isReauthenticationSuppressed')->willReturn(true);
        $service->expects($this->never())->method('getCurrentClientSessions');
        $service->expects($this->once())->method('logout')->with('test-user');

        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->never())->method('getLogoutUris');
        $context = $this->createMock(FrontChannelLogoutContext::class);
        $context->expects($this->never())->method('recordLogout');

        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->once())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->once())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie');

        (new BackChannelLogoutListener($service, $frontChannel, $context, $sessionManagement, $this->createMock(LoggerInterface::class)))
            ->handle(new BeforeUserLoggedOutEvent($user));
    }

    public function testFrontChannelSnapshotFailureDoesNotPreventLogout(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        $service = $this->createMock(BackChannelLogoutService::class);
        $service->method('isReauthenticationSuppressed')->willReturn(false);
        $service->expects($this->once())
            ->method('getCurrentClientSessions')
            ->willReturn(['7' => 'sid-7']);
        $service->expects($this->once())->method('logout')->with('test-user');

        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->once())
            ->method('getLogoutUris')
            ->willThrowException(new \RuntimeException('snapshot failed'));
        $context = $this->createMock(FrontChannelLogoutContext::class);
        $context->expects($this->once())->method('recordLogout')->with([]);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->once())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->once())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new BackChannelLogoutListener($service, $frontChannel, $context, $sessionManagement, $logger))
            ->handle(new BeforeUserLoggedOutEvent($user));
    }

    public function testUnrelatedEventIsIgnored(): void {
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->never())->method('isReauthenticationSuppressed');
        $service->expects($this->never())->method('getCurrentClientSessions');
        $service->expects($this->never())->method('logout');
        $frontChannel = $this->createMock(FrontChannelLogoutService::class);
        $frontChannel->expects($this->never())->method('getLogoutUris');
        $context = $this->createMock(FrontChannelLogoutContext::class);
        $context->expects($this->never())->method('recordLogout');
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->never())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->never())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');

        (new BackChannelLogoutListener($service, $frontChannel, $context, $sessionManagement, $this->createMock(LoggerInterface::class)))->handle(new Event());
    }
}
