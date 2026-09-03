<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;

/** Implements the OP-side state calculation from OpenID Connect Session Management 1.0. */
class SessionManagementService {
    public const OP_BROWSER_STATE_COOKIE = 'oidc_opbs';
    private const OP_BROWSER_STATE_KEY = 'oidc_session_management_opbs_v2';
    private const OP_BROWSER_STATE_TOUCH_KEY = 'oidc_session_management_opbs_touch_v1';
    private const SESSION_STATE_VERSION = '2';
    private const DEFAULT_SESSION_LIFETIME = 86400;
    private const MIN_BROWSER_STATE_TTL = 300;
    private const REFRESH_INTERVAL = 300;

    private ?string $pendingBrowserState = null;
    private bool $browserStateCookieRefreshPending = false;
    private ICache $browserStateCache;
    private int $browserStateTtl;

    public function __construct(
        private ISession $session,
        private ISecureRandom $secureRandom,
        private ClientMapper $clientMapper,
        private RedirectUriMapper $redirectUriMapper,
        private IRequest $request,
        private IOutput $output,
        ICacheFactory $cacheFactory,
        IConfig $config,
    ) {
        $this->browserStateCache = $cacheFactory->createDistributed('oidc_session_management');
        $this->browserStateTtl = max(
            self::MIN_BROWSER_STATE_TTL,
            $config->getSystemValueInt('session_lifetime', self::DEFAULT_SESSION_LIFETIME)
        );
    }

    public function isSupported(): bool {
        return strtolower($this->request->getServerProtocol()) === 'https';
    }

    /**
     * Create an opaque session_state. The authenticated RP origin is carried in
     * the state itself and integrity-protected by the hash. This allows the OP
     * iframe to perform the protocol-defined check locally without a network
     * request for every postMessage.
     */
    public function generateSessionState(string $clientIdentifier, string $redirectUri): string {
        $origin = self::getOrigin($redirectUri);
        if ($origin === null) {
            throw new \InvalidArgumentException('Redirect URI has no valid web origin.');
        }

        $salt = $this->secureRandom->generate(
            32,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );
        $opBrowserState = $this->getOrCreateOpBrowserState();
        $hash = $this->calculate($clientIdentifier, $origin, $opBrowserState, $salt);

        return self::SESSION_STATE_VERSION . '.' . self::base64UrlEncode($origin) . '.' . $hash . '.' . $salt;
    }

    /** @return 'unchanged'|'changed'|'error' */
    public function checkSessionState(string $clientIdentifier, string $origin, string $sessionState): string {
        if (!self::isValidOrigin($origin) || $sessionState === '' || str_contains($sessionState, ' ')) {
            return 'error';
        }

        $parsed = self::parseSessionState($sessionState);
        if ($parsed === null || !hash_equals($parsed['origin'], $origin)) {
            return 'error';
        }

        // Keep the public status endpoint strict even though the iframe now
        // performs the normal check locally. This prevents a caller from using
        // a syntactically valid state for an origin that is not registered to
        // the supplied client.
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

        $opBrowserState = $this->getBrowserStateForIframe();
        if ($opBrowserState === null) {
            // When a browser suppresses the OP state in a third-party iframe,
            // returning "changed" can cause an endless prompt=none loop. The
            // Session Management specification explicitly recommends defensive
            // handling for this browser privacy case.
            return 'error';
        }
        if (!$this->isBrowserStateActiveForIframe()) {
            return 'changed';
        }

        $expected = $this->calculate($clientIdentifier, $origin, $opBrowserState, $parsed['salt']);
        return hash_equals($expected, $parsed['hash']) ? 'unchanged' : 'changed';
    }

    /**
     * Return the syntactically valid browser state presented to the OP iframe.
     * Activity is exposed separately so a stale cookie becomes "changed" after
     * logout or session expiry, while a missing third-party cookie becomes
     * "error" and cannot start a reauthentication loop.
     */
    public function getBrowserStateForIframe(): ?string {
        $cookie = $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE);
        return $this->isValidBrowserState($cookie) ? $cookie : null;
    }

    public function isBrowserStateActiveForIframe(): bool {
        $cookie = $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE);
        return $this->isValidBrowserState($cookie) && $this->isBrowserStateActive($cookie);
    }

    /**
     * Rotate the OP User Agent state after a meaningful authentication state
     * change while the Nextcloud session is still writable (login/user change).
     */
    public function resetBrowserState(): void {
        $this->invalidateCurrentBrowserState();
        $this->pendingBrowserState = $this->generateBrowserState();
        $this->session->set(self::OP_BROWSER_STATE_KEY, $this->pendingBrowserState);
        $this->activateBrowserState($this->pendingBrowserState);
    }

    /**
     * Invalidate the state associated with the current browser/session. This is
     * safe to call from BeforeUserLoggedOutEvent before Nextcloud clears the
     * session and makes previously issued session_state values return changed.
     */
    public function invalidateCurrentBrowserState(): void {
        $candidates = [
            $this->pendingBrowserState,
            $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE),
            $this->session->get(self::OP_BROWSER_STATE_KEY),
        ];
        foreach ($candidates as $candidate) {
            if ($this->isValidBrowserState($candidate)) {
                $this->browserStateCache->remove($this->cacheKey($candidate));
            }
        }
        $this->session->remove(self::OP_BROWSER_STATE_KEY);
        $this->session->remove(self::OP_BROWSER_STATE_TOUCH_KEY);
        $this->pendingBrowserState = null;
        $this->browserStateCookieRefreshPending = false;
    }

    /**
     * Create the fresh browser state that must be emitted on the response after
     * a logout. It intentionally does not write the Nextcloud session because
     * the core logout controller has already closed/cleared it at this point.
     */
    public function rotateBrowserStateForResponse(): void {
        $this->invalidateRequestCookieState();
        $this->pendingBrowserState = $this->generateBrowserState();
        $this->activateBrowserState($this->pendingBrowserState);
    }

    /**
     * Refresh active-state expiry while a real authenticated Nextcloud session
     * remains in use. Writes are throttled to avoid a distributed-cache write on
     * every request.
     */
    public function refreshBrowserStateValidity(): void {
        $sessionValue = $this->session->get(self::OP_BROWSER_STATE_KEY);
        if (!$this->isValidBrowserState($sessionValue)) {
            return;
        }

        $now = time();
        $lastTouch = $this->session->get(self::OP_BROWSER_STATE_TOUCH_KEY);
        if (is_int($lastTouch) && $lastTouch > $now - self::REFRESH_INTERVAL) {
            return;
        }

        $this->activateBrowserState($sessionValue);
        $this->session->set(self::OP_BROWSER_STATE_TOUCH_KEY, $now);
        $this->browserStateCookieRefreshPending = true;
    }

    /**
     * Emit the dedicated OP browser-state cookie. Unlike Nextcloud's
     * authentication cookies this value is deliberately JavaScript-readable:
     * OIDC Session Management expects the OP iframe to observe OP browser state
     * locally. The value is random, carries no authentication authority, and is
     * scoped by SOP to the OP origin. SameSite=None is required in an RP iframe.
     */
    public function applyBrowserStateCookie(Response $response): void {
        if (!$this->isSupported()) {
            return;
        }

        $value = $this->pendingBrowserState;
        if (!$this->isValidBrowserState($value)) {
            $sessionValue = $this->session->get(self::OP_BROWSER_STATE_KEY);
            $cookie = $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE);
            if ($this->isValidBrowserState($sessionValue)
                && ($this->browserStateCookieRefreshPending
                    || !is_string($cookie)
                    || !hash_equals($sessionValue, $cookie))) {
                $value = $sessionValue;
            }
        }
        if (!$this->isValidBrowserState($value)) {
            return;
        }

        // Response::addCookie() is intentionally not used here: Nextcloud emits
        // all AppFramework response cookies as HttpOnly. This OIDC state cookie
        // must be visible to the same-origin OP iframe, so use the public IOutput
        // cookie API with httpOnly=false. It is never an authentication cookie.
        $this->output->setCookie(
            self::OP_BROWSER_STATE_COOKIE,
            $value,
            time() + $this->browserStateTtl,
            '/',
            null,
            true,
            false,
            'None'
        );
        $this->browserStateCookieRefreshPending = false;
    }

    private function getOrCreateOpBrowserState(): string {
        if ($this->isValidBrowserState($this->pendingBrowserState)) {
            $this->activateBrowserState($this->pendingBrowserState);
            return $this->pendingBrowserState;
        }

        $cookie = $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE);
        if ($this->isValidBrowserState($cookie) && $this->isBrowserStateActive($cookie)) {
            $this->session->set(self::OP_BROWSER_STATE_KEY, $cookie);
            $this->activateBrowserState($cookie);
            return $cookie;
        }

        $sessionValue = $this->session->get(self::OP_BROWSER_STATE_KEY);
        if ($this->isValidBrowserState($sessionValue) && $this->isBrowserStateActive($sessionValue)) {
            $this->pendingBrowserState = $sessionValue;
            $this->activateBrowserState($sessionValue);
            return $sessionValue;
        }

        $this->pendingBrowserState = $this->generateBrowserState();
        $this->session->set(self::OP_BROWSER_STATE_KEY, $this->pendingBrowserState);
        $this->activateBrowserState($this->pendingBrowserState);
        return $this->pendingBrowserState;
    }

    private function invalidateRequestCookieState(): void {
        $cookie = $this->request->getCookie(self::OP_BROWSER_STATE_COOKIE);
        if ($this->isValidBrowserState($cookie)) {
            $this->browserStateCache->remove($this->cacheKey($cookie));
        }
    }

    private function generateBrowserState(): string {
        return $this->secureRandom->generate(
            64,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );
    }

    private function activateBrowserState(string $value): void {
        $this->browserStateCache->set($this->cacheKey($value), '1', $this->browserStateTtl);
    }

    private function isBrowserStateActive(string $value): bool {
        return $this->browserStateCache->get($this->cacheKey($value)) !== null;
    }

    private function cacheKey(string $value): string {
        return 'opbs:' . hash('sha256', $value);
    }

    private function isValidBrowserState(mixed $value): bool {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{64}$/', $value) === 1;
    }

    private function calculate(string $clientIdentifier, string $origin, string $opBrowserState, string $salt): string {
        return hash('sha256', $clientIdentifier . ' ' . $origin . ' ' . $opBrowserState . ' ' . $salt);
    }

    /** @return array{origin:string,hash:string,salt:string}|null */
    public static function parseSessionState(string $sessionState): ?array {
        $parts = explode('.', $sessionState, 4);
        if (count($parts) !== 4 || $parts[0] !== self::SESSION_STATE_VERSION) {
            return null;
        }
        $origin = self::base64UrlDecode($parts[1]);
        if ($origin === null || !self::isValidOrigin($origin)) {
            return null;
        }
        $hash = strtolower($parts[2]);
        $salt = $parts[3];
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !preg_match('/^[A-Za-z0-9]{16,128}$/', $salt)) {
            return null;
        }
        return ['origin' => $origin, 'hash' => $hash, 'salt' => $salt];
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

        // Browsers serialize IDNs as ASCII in event.origin. Normalize when the
        // intl extension is available, without making it a hard dependency.
        if (function_exists('idn_to_ascii') && !str_starts_with($host, '[')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        // parse_url may return IPv6 hosts either with or without brackets across
        // supported PHP versions. RFC 6454 serialization requires brackets.
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    public static function isValidOrigin(string $origin): bool {
        return self::getOrigin($origin) === $origin;
    }

    private static function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
