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
class TokenValidationRequestEvent extends Event {

	private ?bool $isValid = null;

	public function __construct(
		private string $accessToken,
	) {
		parent::__construct();
	}

	public function getIsValid(): ?bool {
		return $this->isValid;
	}

	public function getAccessToken(): string {
		return $this->accessToken;
	}

	public function setIsValid(bool $isValid): void {
		$this->isValid = $isValid;
	}
}
