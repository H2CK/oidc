<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Event;

use OCP\EventDispatcher\Event;

/**
 * This event is emitted by other apps that want to know if an access token is valid
 */
class TokenValidationRequestEvent extends Event {

	private ?bool $isValid = null;
	private ?string $userId = null;

	public function __construct(
		private string $accessToken,
	) {
		parent::__construct();
	}

	public function getAccessToken(): string {
		return $this->accessToken;
	}

	public function getIsValid(): ?bool {
		return $this->isValid;
	}

	public function setIsValid(bool $isValid): void {
		$this->isValid = $isValid;
	}

	public function getUserId(): ?string {
		return $this->userId;
	}

	public function setUserId(string $userId): void {
		$this->userId = $userId;
	}
}
