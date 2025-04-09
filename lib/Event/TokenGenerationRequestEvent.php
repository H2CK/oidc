<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Event;

use OCP\EventDispatcher\Event;

/**
 * This event is emitted by other apps that need an access+id token from one of our Oidc clients
 */
class TokenGenerationRequestEvent extends Event {

    private ?string $accessToken = null;
    private ?string $idToken = null;
    private ?string $refreshToken = null;
    private ?int $expiresIn = null;
    private ?int $refreshExpiresIn = null;

    public function __construct(
        private string $clientIdentifier,
        private string $userId,
        private string $extraScopes = "",
    ) {
        parent::__construct();
    }

    public function getClientIdentifier(): string {
        return $this->clientIdentifier;
    }

    public function getUserId(): string {
        return $this->userId;
    }

    public function getExtraScopes(): string {
        return $this->extraScopes;
    }

    public function getAccessToken(): ?string {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): void {
        $this->accessToken = $accessToken;
    }

    public function getIdToken(): ?string {
        return $this->idToken;
    }

    public function setIdToken(?string $idToken): void {
        $this->idToken = $idToken;
    }

    public function getRefreshToken(): ?string {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): void {
        $this->refreshToken = $refreshToken;
    }

    public function getExpiresIn(): ?int {
        return $this->expiresIn;
    }

    public function setExpiresIn(?int $expiresIn): void {
        $this->expiresIn = $expiresIn;
    }

    public function getRefreshExpiresIn(): ?int {
        return $this->refreshExpiresIn;
    }

    public function setRefreshExpiresIn(?int $refreshExpiresIn): void {
        $this->refreshExpiresIn = $refreshExpiresIn;
    }
}
