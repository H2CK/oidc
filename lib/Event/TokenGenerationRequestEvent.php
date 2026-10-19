<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Event;

use OCP\EventDispatcher\Event;

/**
 * This event is emitted by other apps that need an access+id token from one of our Oidc clients.
 * The constructor form without an expected audience is deprecated and will be removed in a future release.
 */
class TokenGenerationRequestEvent extends Event {

    private ?string $accessToken = null;
    private ?string $idToken = null;
    private ?string $refreshToken = null;
    private ?int $expiresIn = null;
    private ?int $refreshExpiresIn = null;
    private ?string $scope = null;
    private ?string $audience = null;

    public function __construct(
        private string $clientIdentifier,
        private string $userId,
        private string $extraScopes = "",
        private ?string $resource = "",
        private ?string $expectedAudience = null,
    ) {
        parent::__construct();
        if (func_num_args() < 5) {
            trigger_error(
                'TokenGenerationRequestEvent without an explicit expected audience is deprecated and will be removed in a future release.',
                E_USER_DEPRECATED
            );
        }
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

    public function getResource(): ?string {
        return $this->resource;
    }

    public function setResource(?string $resource): void {
        $this->resource = $resource;
    }

    public function getExpectedAudience(): ?string {
        return $this->expectedAudience;
    }

    public function getScope(): ?string {
        return $this->scope;
    }

    public function setScope(string $scope): void {
        $this->scope = $scope;
    }

    public function getAudience(): ?string {
        return $this->audience;
    }

    public function setAudience(string $audience): void {
        $this->audience = $audience;
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
