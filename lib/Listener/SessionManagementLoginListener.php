<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCA\OIDCIdentityProvider\Service\AuthenticationTimeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserLoggedInEvent;
use OCP\User\Events\UserLoggedInWithCookieEvent;

/** @template-implements IEventListener<Event> */
class SessionManagementLoginListener implements IEventListener {
    public function __construct(
        private SessionManagementService $sessionManagementService,
        private AuthenticationTimeService $authenticationTime,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserLoggedInEvent && !$event instanceof UserLoggedInWithCookieEvent) {
            return;
        }

        // A login (including remembered-cookie login) is a change of OP User
        // Agent state. Rotating here also covers a user switch in the browser.
        $this->sessionManagementService->resetBrowserState();
        if ($event instanceof UserLoggedInEvent && !$event->isTokenLogin()) {
            $this->authenticationTime->recordLogin($event->getUser()->getUID());
        } else {
            // Remember-me and app-token logins do not prove active authentication.
            $this->authenticationTime->clear();
        }
    }
}
