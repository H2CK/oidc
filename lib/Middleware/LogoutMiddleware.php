<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Middleware;

use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IUserSession;

/**
 * Extends Front-Channel Logout to Nextcloud's normal browser logout endpoint.
 * Front-channel notifications must run in the user agent; server-side HTTP
 * requests would not carry the RP's browser cookies and are not equivalent.
 */
class LogoutMiddleware extends Middleware {
    /** @var list<string> */
    private array $frontChannelUris = [];
    private bool $coreLogout = false;

    public function __construct(
        private BackChannelLogoutService $backChannelLogoutService,
        private FrontChannelLogoutService $frontChannelLogoutService,
        private SessionManagementService $sessionManagementService,
        private IUserSession $userSession,
    ) {
    }

    public function beforeController(Controller $controller, string $methodName): void {
        $this->coreLogout = is_a($controller, 'OC\\Core\\Controller\\LoginController')
            && $methodName === 'logout';
        $this->frontChannelUris = [];

        if (!$this->coreLogout || !$this->userSession->isLoggedIn()) {
            return;
        }

        // Capture RP sessions before IUserSession::logout() dispatches the
        // back-channel listener that clears them.
        $this->frontChannelUris = $this->frontChannelLogoutService->getLogoutUris(
            $this->backChannelLogoutService->getCurrentClientSessions()
        );
    }

    public function afterController(Controller $controller, string $methodName, Response $response): Response {
        if (!$this->coreLogout) {
            if ($this->userSession->isLoggedIn()) {
                $this->sessionManagementService->refreshBrowserStateValidity();
                $this->sessionManagementService->applyBrowserStateCookie($response);
            }
            return $response;
        }

        $headers = $response->getHeaders();
        $redirectUrl = $headers['Location'] ?? $headers['location'] ?? null;
        if (!is_string($redirectUrl) || $redirectUrl === '') {
            // Core logout normally returns a RedirectResponse. If that contract
            // ever changes, do not manufacture an unsafe redirect destination.
            return $response;
        }

        $replacement = $this->frontChannelLogoutService->createBrowserLogoutResponse(
            $this->frontChannelUris,
            $redirectUrl
        );

        // Preserve the explicit compatibility/security headers emitted by
        // Nextcloud's core logout controller. Do not copy the core CSP: the
        // Front-Channel response deliberately has a stricter CSP with the
        // registered RP origins in frame-src, and overwriting it would block
        // the logout iframes.
        foreach (['Clear-Site-Data', 'X-User-Id'] as $headerName) {
            $value = $headers[$headerName] ?? $headers[strtolower($headerName)] ?? null;
            if (is_string($value) && $value !== '') {
                $replacement->addHeader($headerName, $value);
            }
        }
        return $replacement;
    }
}
