<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Event;

use OCP\EventDispatcher\Event;

/**
 * This event is emitted by other apps that need an access token from one of our Oidc clients
 */
class TokenGenerationRequestEvent extends Event {

    private ?string $accessToken = null;

    public function __construct(
        private string $clientIdentifier,
        private string $userId,
    ) {
        parent::__construct();
    }

    public function getClientIdentifier(): string {
        return $this->clientIdentifier;
    }

    public function getUserId(): string {
        return $this->userId;
    }

    public function getAccessToken(): ?string {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): void {
        $this->accessToken = $accessToken;
    }
}
