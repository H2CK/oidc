<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TokenGenerationRequestEvent|Event>
 */
class TokenGenerationRequestListener implements IEventListener {

	public function __construct(
		private LoggerInterface $logger,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IAppConfig $appConfig,
		private AccessTokenMapper $accessTokenMapper,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof TokenGenerationRequestEvent) {
			return;
		}

		$clientId = $event->getClientId();
		$userId = $event->getUserId();
		$this->logger->debug('[TokenGenerationRequestListener] received token request event for user: ' . $userId . ' and client ID: ' . $clientId);

		// generate a new access token for the client
		$expireTime = (int)$this->appConfig->getAppValue('expire_time', '0');
		$accessTokenString = $this->random->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$code = $this->random->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
		$accessToken = new AccessToken();
		$accessToken->setClientId($clientId);
		$accessToken->setUserId($userId);
		$accessToken->setAccessToken($accessTokenString);
		$accessToken->setHashedCode(hash('sha512', $code));
		$accessToken->setScope(substr(Application::DEFAULT_SCOPE, 0, 128));
		$accessToken->setCreated($this->time->getTime());
		$accessToken->setRefreshed($this->time->getTime() + $expireTime);
		$accessToken->setNonce('');
		$this->accessTokenMapper->insert($accessToken);

		$event->setAccessToken($accessToken->getAccessToken());
	}
}
