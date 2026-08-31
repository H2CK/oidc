<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;

/** @template-implements IEventListener<BeforeUserLoggedOutEvent> */
class BackChannelLogoutListener implements IEventListener {
    public function __construct(private BackChannelLogoutService $backChannelLogoutService) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeUserLoggedOutEvent) {
            return;
        }

        $user = $event->getUser();
        $this->backChannelLogoutService->logout($user?->getUID());
    }
}
