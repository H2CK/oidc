<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Listener;

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Listener\UserDeletedListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

class UserDeletedListenerTest extends TestCase {
    public function testDeletionRemovesGrantsConsentsAndDeviceCodes(): void {
        $tokens = $this->createMock(AccessTokenMapper::class);
        $consents = $this->createMock(UserConsentMapper::class);
        $devices = $this->createMock(DeviceCodeMapper::class);
        $tokens->expects($this->once())->method('deleteByUserId')->with('alice');
        $consents->expects($this->once())->method('deleteByUserId')->with('alice');
        $devices->expects($this->once())->method('deleteByUserId')->with('alice');
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $listener = new UserDeletedListener($tokens, $consents, $devices);
        $listener->handle(new UserDeletedEvent($user));
    }

    public function testUnrelatedEventDoesNotDeleteAnything(): void {
        $tokens = $this->createMock(AccessTokenMapper::class);
        $consents = $this->createMock(UserConsentMapper::class);
        $devices = $this->createMock(DeviceCodeMapper::class);
        $tokens->expects($this->never())->method('deleteByUserId');
        $consents->expects($this->never())->method('deleteByUserId');
        $devices->expects($this->never())->method('deleteByUserId');
        (new UserDeletedListener($tokens, $consents, $devices))->handle(new Event());
    }
}
