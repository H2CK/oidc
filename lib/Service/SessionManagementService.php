<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;

/** Implements the OP-side state calculation from OpenID Connect Session Management 1.0. */
class SessionManagementService {
    public const OP_BROWSER_STATE_COOKIE = 'oidc_opbs';
    private const OP_BROWSER_STATE_KEY = 'oidc_session_management_opbs_v2';

    private ?string $pendingBrowserState = null;

    public function __construct(
        private ISession $session,
        private ISecureRandom $secureRandom,
        private ClientMapper $clientMapper,
        private RedirectUriMapper $redirectUriMapper,
        private IRequest $request,
    ) {
    }

    public function generateSessionState(string $clientIdentifier, string $redirectUri): string {
        $origin = self::getOrigin($redirectUri);
        if ($origin === null) {
            throw new \InvalidArgumentException('Redirect URI has no valid web origin.');
        }

        $salt = $this->secureRandom->generate(
            32,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );

        return $this->calculate($clientIdentifier, $origin, $this->getOrCreateOpBrowserState(), $salt) . '.' . $salt;
    }

    /** @return 'unchanged'|'changed'|'error' */
    public function checkSessionState(string $clientIdentifier, string $origin, string $sessionState): string {
        if (!self::isValidOrigin($origin) || $sessionState === '' || str_contains($sessionState, ' ')) {
            return 'error';
        }

        $separator = strrpos($sessionState, '.');
        if ($separator === false || $separator === 0 || $separator === strlen($sessionState) - 1) {
            return 'error';
        }
        $hash = substr($sessionState, 0, $separator);
        $salt = substr($sessionState, $separator + 1);
        if (!preg_match('/^[A-Fa-f0-9]{64}$/', $hash) || !preg_match('/^[A-Za-z0-9]{16,128}$/', $salt)) {
            return 'error';
        }

        try {
            $client = $this->clientMapper->getByIdentifier($clientIdentifier);
        } catch (\Throwable $e) {
            return 'error';
        }
        if ($client === null) {
            return 'error';
        }

        $registeredOrigin = false;
        foreach ($this->redirectUriMapper->getByClientId($client->getId()) as $redirectUri) {
            if (self::getOrigin($redirectUri->getRedirectUri()) === $origin) {
                $registeredOrigin = true;
                break;
            }
        }
        if (!$registeredOrigin) {
            return 'error';
        }

        $opBrowserState = $this->getCurrentOpBrowserState();
        if ($opBrowserState === null) {
            // The session_state itself is valid, but the browser no longer presents
            // the OP state that was used to create it. Per the specification this is
            // a session change, not a malformed request.
            return 'changed';
        }

        $expected = $this->calculate($clientIdentifier, $origin, $opBrowserState, $salt);
        return hash_equals($expected, strtolower($hash)) ? 'unchanged' : 'changed';
    }

    /**
     * Rotate the OP User Agent state after a meaningful authentication state
     * change (for example logout). The new value is sent on the next response.
     */
    public function resetBrowserState(): void {
        $this->pendingBrowserState = $this->generateBrowserState();
        $this->session->set(self::OP_BROWSER_STATE_KEY, $this->pendingBrowserState);
    }

    /**
     * Add the dedicated OP browser-state cookie to a response when this request
     * generated or rotated it. SameSite=None is required because the OP iframe
     * is intentionally embedded cross-site by an RP. Nextcloud marks response
     * cookies HttpOnly and Secure when the OP is served via HTTPS.
     */
    public function applyBrowserStateCookie(Response $response): void {
        if ($this->pendingBrowserState === null) {
            return;
        }
        $response->addCookie(self::OP_BROWSER_STATE_COOKIE, $this->pendingBrowserState, null, 'None');
    }

    private function getOrCreateOpBrowserState(): string {
        $current = $this->getCurrentOpBrowserState();
        if ($current !== null) {
            return $current;
        }

        $this->pendingBrowserState = $this->generateBrowserState();
        $this->session->set(self::OP_BROWSER_STATE_KEY, $this->pendingBrowserState);
        return $this->pendingBrowserState;
    }

    private function getCurrentOpBrowserState(): ?string {
        if ($this->isValidBrowserState($this->pendingBrowserState)) {
            return $this->pendingBrowserState;
        }

        // The dedicated cookie is deliberately independent from Nextcloud's
        // PHP session cookie. The latter is SameSite=Lax and is therefore not
        // available when check_session_iframe is embedded by a cross-origin RP.
        $cookie = $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE);
        if ($this->isValidBrowserState($cookie)) {
            return $cookie;
        }

        // Keep the server-side copy as a same-request/top-level fallback only.
        // In the cross-site iframe the PHP session may be unavailable, which is
        // exactly why the dedicated SameSite=None cookie exists.
        $sessionValue = $this->session->get(self::OP_BROWSER_STATE_KEY);
        if ($this->isValidBrowserState($sessionValue)) {
            return $sessionValue;
        }

        return null;
    }

    private function generateBrowserState(): string {
        return $this->secureRandom->generate(
            64,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );
    }

    private function isValidBrowserState(mixed $value): bool {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{64}$/', $value) === 1;
    }

    private function calculate(string $clientIdentifier, string $origin, string $opBrowserState, string $salt): string {
        return hash('sha256', $clientIdentifier . ' ' . $origin . ' ' . $opBrowserState . ' ' . $salt);
    }

    public static function getOrigin(string $uri): ?string {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string)$parts['host']);
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    private static function isValidOrigin(string $origin): bool {
        return self::getOrigin($origin) === $origin;
    }
}
