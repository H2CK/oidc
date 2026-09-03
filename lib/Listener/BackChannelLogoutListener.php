<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;

/** @template-implements IEventListener<BeforeUserLoggedOutEvent> */
class BackChannelLogoutListener implements IEventListener {
    public function __construct(
        private BackChannelLogoutService $backChannelLogoutService,
        private SessionManagementService $sessionManagementService,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeUserLoggedOutEvent) {
            return;
        }

        // Invalidate the OP User Agent state before Nextcloud clears the PHP
        // session. This makes previously issued session_state values change even
        // when logout did not originate at the OIDC end_session_endpoint.
        $this->sessionManagementService->invalidateCurrentBrowserState();

        $user = $event->getUser();
        $this->backChannelLogoutService->logout($user?->getUID());

        // Rotate immediately on the logout lifecycle event. This also covers
        // idle-session expiry and server-driven logouts that do not pass through
        // the OIDC or core browser logout controllers.
        $this->sessionManagementService->rotateBrowserStateForResponse();
        $this->sessionManagementService->applyBrowserStateCookie(new Response());
    }
}
