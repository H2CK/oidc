<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Middleware;

use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutContext;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IUserSession;

/**
 * Completes browser Front-Channel Logout after any real Nextcloud logout.
 *
 * Logout detection is deliberately event-driven via FrontChannelLogoutContext;
 * this middleware has no dependency on internal OC\\Core controller classes.
 * Front-channel notifications must run in the user agent because server-side
 * HTTP requests would not carry the RP's browser cookies.
 */
class LogoutMiddleware extends Middleware {
    public function __construct(
        private FrontChannelLogoutService $frontChannelLogoutService,
        private FrontChannelLogoutContext $frontChannelLogoutContext,
        private SessionManagementService $sessionManagementService,
        private IUserSession $userSession,
    ) {
    }

    public function afterController(Controller $controller, string $methodName, Response $response): Response {
        $frontChannelUris = $this->frontChannelLogoutContext->consumeLogoutUris();
        if ($frontChannelUris === null) {
            if ($this->userSession->isLoggedIn()) {
                $this->sessionManagementService->refreshBrowserStateValidity();
                $this->sessionManagementService->applyBrowserStateCookie($response);
            }
            return $response;
        }

        // A real logout occurred, but no participating RP needs browser fan-out.
        // Preserve the original controller response exactly.
        if ($frontChannelUris === []) {
            return $response;
        }

        $headers = $response->getHeaders();
        $redirectUrl = $headers['Location'] ?? $headers['location'] ?? null;
        if (!is_string($redirectUrl) || $redirectUrl === '') {
            // OIDC end_session may already have rendered the Front-Channel HTML
            // response itself. Other logout endpoints may also intentionally
            // return a non-redirect response. Never manufacture a destination.
            return $response;
        }

        $replacement = $this->frontChannelLogoutService->createBrowserLogoutResponse(
            $frontChannelUris,
            $redirectUrl
        );

        // Preserve the explicit compatibility/security headers emitted by the
        // original logout controller. Do not copy its CSP: the Front-Channel
        // response deliberately allows only the registered RP frame origins.
        foreach (['Clear-Site-Data', 'X-User-Id'] as $headerName) {
            $value = $headers[$headerName] ?? $headers[strtolower($headerName)] ?? null;
            if (is_string($value) && $value !== '') {
                $replacement->addHeader($headerName, $value);
            }
        }
        return $replacement;
    }
}
