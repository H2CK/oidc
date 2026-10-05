<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use OCA\OIDCIdentityProvider\Listener\SessionManagementLoginListener;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCA\OIDCIdentityProvider\Service\AuthenticationTimeService;
use OCP\IUser;
use OCP\EventDispatcher\Event;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use PHPUnit\Framework\TestCase;

class SessionManagementLoginListenerTest extends TestCase {
    public function testPasswordLoginRotatesBrowserState(): void {
        $service = $this->createMock(SessionManagementService::class);
        $service->expects($this->once())->method('resetBrowserState');
        $authenticationTime = $this->createMock(AuthenticationTimeService::class);

        $event = $this->getMockBuilder(UserLoggedInEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $event->method('getUser')->willReturn($user);
        $event->method('isTokenLogin')->willReturn(false);
        $authenticationTime->expects($this->once())->method('recordLogin')->with('alice');
        (new SessionManagementLoginListener($service, $authenticationTime))->handle($event);
    }

    public function testCookieLoginRotatesBrowserState(): void {
        $service = $this->createMock(SessionManagementService::class);
        $service->expects($this->once())->method('resetBrowserState');
        $authenticationTime = $this->createMock(AuthenticationTimeService::class);

        $event = $this->getMockBuilder(UserLoggedInWithCookieEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        $authenticationTime->expects($this->never())->method('recordLogin');
        $authenticationTime->expects($this->once())->method('clear');
        (new SessionManagementLoginListener($service, $authenticationTime))->handle($event);
    }

    public function testUnrelatedEventIsIgnored(): void {
        $service = $this->createMock(SessionManagementService::class);
        $service->expects($this->never())->method('resetBrowserState');
        $authenticationTime = $this->createMock(AuthenticationTimeService::class);
        $authenticationTime->expects($this->never())->method('recordLogin');

        (new SessionManagementLoginListener($service, $authenticationTime))->handle(new Event());
    }
}
