<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUserSession;

/** Session-local evidence of active authentication, never authorization or cookie restoration. */
class AuthenticationTimeService {
    public const TIME_KEY = 'oidc_active_auth_time';
    public const USER_KEY = 'oidc_active_auth_user';

    public function __construct(
        private ISession $session,
        private ITimeFactory $time,
        private IUserSession $userSession,
    ) {
    }

    public function recordLogin(string $userId): void {
        $this->session->set(self::TIME_KEY, $this->time->getTime());
        $this->session->set(self::USER_KEY, $userId);
    }

    public function clear(): void {
        $this->session->remove(self::TIME_KEY);
        $this->session->remove(self::USER_KEY);
    }

    public function getAuthenticationTime(): ?int {
        $user = $this->userSession->getUser();
        $timestamp = $this->session->get(self::TIME_KEY);
        if ($user === null || $this->session->get(self::USER_KEY) !== $user->getUID()
            || !is_int($timestamp) || $timestamp <= 0 || $timestamp > $this->time->getTime()) {
            return null;
        }
        return $timestamp;
    }

    public function authenticatedSince(int $timestamp): bool {
        $authenticatedAt = $this->getAuthenticationTime();
        return $authenticatedAt !== null && $authenticatedAt >= $timestamp;
    }
}
