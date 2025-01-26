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
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
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
        private ClientMapper $clientMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TokenGenerationRequestEvent) {
            return;
        }

        $clientIdentifier = $event->getClientIdentifier();
        $userId = $event->getUserId();
        $this->logger->debug('[TokenGenerationRequestListener] received token request event for user: ' . $userId . ' and client identifier: ' . $clientIdentifier);

        // get client from identifier
        try {
            $client = $this->clientMapper->getByIdentifier($clientIdentifier);
        } catch (ClientNotFoundException) {
            $this->logger->debug('[TokenGenerationRequestListener] Client ' . $clientIdentifier . ' not found');
            return;
        }
        // check client expiration
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValue('client_expire_time', '3600'))) {
            $this->logger->warning('[TokenGenerationRequestListener] Client ' . $client->getId() . ' has expired');
            return;
        }

        // generate a new access token for the client
        $expireTime = (int)$this->appConfig->getAppValue('expire_time', '0');
        $accessTokenString = $this->random->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $code = $this->random->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
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
