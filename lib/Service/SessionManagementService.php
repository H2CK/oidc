<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\ISession;
use OCP\Security\ISecureRandom;

/** Implements the OP-side state calculation from OpenID Connect Session Management 1.0. */
class SessionManagementService {
    private const OP_BROWSER_STATE_KEY = 'oidc_session_management_opbs_v1';

    public function __construct(
        private ISession $session,
        private ISecureRandom $secureRandom,
        private ClientMapper $clientMapper,
        private RedirectUriMapper $redirectUriMapper,
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

        return $this->calculate($clientIdentifier, $origin, $this->getOpBrowserState(), $salt) . '.' . $salt;
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

        $expected = $this->calculate($clientIdentifier, $origin, $this->getOpBrowserState(), $salt);
        return hash_equals($expected, strtolower($hash)) ? 'unchanged' : 'changed';
    }

    public function resetBrowserState(): void {
        $this->session->remove(self::OP_BROWSER_STATE_KEY);
    }

    private function getOpBrowserState(): string {
        $value = $this->session->get(self::OP_BROWSER_STATE_KEY);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $this->secureRandom->generate(
            64,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );
        $this->session->set(self::OP_BROWSER_STATE_KEY, $value);
        return $value;
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
