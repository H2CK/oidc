<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutContext;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<BeforeUserLoggedOutEvent> */
class BackChannelLogoutListener implements IEventListener {
    public function __construct(
        private BackChannelLogoutService $backChannelLogoutService,
        private FrontChannelLogoutService $frontChannelLogoutService,
        private FrontChannelLogoutContext $frontChannelLogoutContext,
        private SessionManagementService $sessionManagementService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeUserLoggedOutEvent) {
            return;
        }

        // Snapshot Front-Channel targets while the RP => sid correlations are
        // still available. Reauthentication deliberately preserves those RP
        // sessions and therefore must not create a browser logout context.
        if (!$this->backChannelLogoutService->isReauthenticationSuppressed()) {
            $frontChannelUris = [];
            try {
                $frontChannelUris = $this->frontChannelLogoutService->getLogoutUris(
                    $this->backChannelLogoutService->getCurrentClientSessions()
                );
            } catch (\Throwable $e) {
                // Front-Channel Logout is a notification side effect. Failure
                // to prepare it must never prevent the local logout or the
                // independent Back-Channel Logout path.
                $this->logger->warning('Could not prepare Front-Channel Logout targets.', [
                    'exception' => $e,
                ]);
            }
            $this->frontChannelLogoutContext->recordLogout($frontChannelUris);
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
