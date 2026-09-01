<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use OCA\OIDCIdentityProvider\Listener\BackChannelLogoutListener;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCP\IUser;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use PHPUnit\Framework\TestCase;

class BackChannelLogoutListenerTest extends TestCase {
    public function testLogoutWithoutUserIsHandledUsingSidOnlyPath(): void {
        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->once())
            ->method('logout')
            ->with(null);

        $listener = new BackChannelLogoutListener($service);
        $listener->handle(new BeforeUserLoggedOutEvent(null));
    }

    public function testLogoutWithUserPassesUidToService(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        $service = $this->createMock(BackChannelLogoutService::class);
        $service->expects($this->once())
            ->method('logout')
            ->with('test-user');

        $listener = new BackChannelLogoutListener($service);
        $listener->handle(new BeforeUserLoggedOutEvent($user));
    }
}
