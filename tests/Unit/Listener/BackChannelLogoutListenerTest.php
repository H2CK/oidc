<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use OCA\OIDCIdentityProvider\Listener\BackChannelLogoutListener;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use PHPUnit\Framework\TestCase;

class BackChannelLogoutListenerTest extends TestCase {
    public function testLogoutWithoutUserInvalidatesSessionManagementStateAndUsesSidOnlyPath(): void {
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->once())
            ->method('logout')
            ->with(null);
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->once())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->once())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie');

        $listener = new BackChannelLogoutListener($service, $sessionManagement);
        $listener->handle(new BeforeUserLoggedOutEvent(null));
    }

    public function testLogoutWithUserInvalidatesSessionManagementStateAndPassesUid(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->once())
            ->method('logout')
            ->with('test-user');
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->once())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->once())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->once())->method('applyBrowserStateCookie');

        $listener = new BackChannelLogoutListener($service, $sessionManagement);
        $listener->handle(new BeforeUserLoggedOutEvent($user));
    }

    public function testUnrelatedEventIsIgnored(): void {
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->never())->method('logout');
        $sessionManagement = $this->createMock(SessionManagementService::class);
        $sessionManagement->expects($this->never())->method('invalidateCurrentBrowserState');
        $sessionManagement->expects($this->never())->method('rotateBrowserStateForResponse');
        $sessionManagement->expects($this->never())->method('applyBrowserStateCookie');

        (new BackChannelLogoutListener($service, $sessionManagement))->handle(new Event());
    }
}
