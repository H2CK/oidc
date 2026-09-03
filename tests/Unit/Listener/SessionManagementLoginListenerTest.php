<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use OCA\OIDCIdentityProvider\Listener\SessionManagementLoginListener;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\EventDispatcher\Event;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;
use PHPUnit\Framework\TestCase;

class SessionManagementLoginListenerTest extends TestCase {
    public function testPasswordLoginRotatesBrowserState(): void {
        $service = $this->createMock(SessionManagementService::class);
        $service->expects($this->once())->method('resetBrowserState');

        $event = $this->getMockBuilder(UserLoggedInEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        (new SessionManagementLoginListener($service))->handle($event);
    }

    public function testCookieLoginRotatesBrowserState(): void {
        $service = $this->createMock(SessionManagementService::class);
        $service->expects($this->once())->method('resetBrowserState');

        $event = $this->getMockBuilder(UserLoggedInWithCookieEvent::class)
            ->disableOriginalConstructor()
            ->getMock();
        (new SessionManagementLoginListener($service))->handle($event);
    }

    public function testUnrelatedEventIsIgnored(): void {
        $service = $this->createMock(SessionManagementService::class);
        $service->expects($this->never())->method('resetBrowserState');

        (new SessionManagementLoginListener($service))->handle(new Event());
    }
}
