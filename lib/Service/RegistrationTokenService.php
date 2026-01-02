<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\RegistrationToken;
use OCA\OIDCIdentityProvider\Db\RegistrationTokenMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class RegistrationTokenService {
	/** Token length: 64 characters = ~380 bits of entropy */
	private const TOKEN_LENGTH = 64;
	private const TOKEN_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

	/** Grace period in seconds for old token during rotation */
	private const GRACE_PERIOD = 60;

	public function __construct(
		private RegistrationTokenMapper $tokenMapper,
		private ISecureRandom $secureRandom,
		private LoggerInterface $logger
	) {
	}

	/**
	 * Generate a cryptographically secure registration access token
	 *
	 * @param int $clientId The client ID
	 * @param int|null $expiresAt Optional expiration timestamp (null = no expiration)
	 * @return RegistrationToken
	 */
	public function generateToken(int $clientId, ?int $expiresAt = null): RegistrationToken {
		$token = new RegistrationToken();
		$token->setClientId($clientId);
		$token->setToken($this->secureRandom->generate(
			self::TOKEN_LENGTH,
			self::TOKEN_CHARS
		));
		$token->setCreatedAt(time());
		$token->setExpiresAt($expiresAt);

		return $this->tokenMapper->insert($token);
	}

	/**
	 * Rotate token: invalidate old token(s) and generate new one
	 * Implements a grace period to prevent race conditions
	 *
	 * @param int $clientId The client ID
	 * @param int|null $expiresAt Optional expiration timestamp for new token
	 * @return RegistrationToken The new token
	 */
	public function rotateToken(int $clientId, ?int $expiresAt = null): RegistrationToken {
		// Get current token if exists
		$oldToken = $this->tokenMapper->getCurrentToken($clientId);

		if ($oldToken !== null) {
			// Set grace period expiration on old token instead of immediate deletion
			// This prevents issues if client has already started a request with the old token
			$oldToken->setExpiresAt(time() + self::GRACE_PERIOD);
			$this->tokenMapper->update($oldToken);

			$this->logger->debug('Rotated registration token for client', [
				'app' => 'oidc',
				'client_id' => $clientId,
				'old_token_id' => $oldToken->getId(),
				'grace_period' => self::GRACE_PERIOD,
			]);
		}

		// Generate and return new token
		return $this->generateToken($clientId, $expiresAt);
	}

	/**
	 * Validate a registration access token and return associated client ID
	 *
	 * @param string $token The token string to validate
	 * @return int|null Client ID if token is valid, null otherwise
	 */
	public function validateToken(string $token): ?int {
		try {
			$registrationToken = $this->tokenMapper->getByToken($token);

			// Check expiration if set
			if ($registrationToken->getExpiresAt() !== null &&
				$registrationToken->getExpiresAt() < time()) {
				$this->logger->debug('Registration token expired', [
					'app' => 'oidc',
					'token_id' => $registrationToken->getId(),
					'client_id' => $registrationToken->getClientId(),
				]);
				return null;
			}

			return $registrationToken->getClientId();
		} catch (DoesNotExistException $e) {
			// Add random delay to prevent timing attacks on token existence
			usleep(random_int(10000, 50000)); // 10-50ms
			return null;
		}
	}

	/**
	 * Invalidate all registration tokens for a client
	 * Called when client is deleted (also handled by CASCADE) or needs immediate revocation
	 *
	 * @param int $clientId The client ID
	 */
	public function revokeTokens(int $clientId): void {
		$this->tokenMapper->deleteByClientId($clientId);

		$this->logger->info('Revoked all registration tokens for client', [
			'app' => 'oidc',
			'client_id' => $clientId,
		]);
	}
}
