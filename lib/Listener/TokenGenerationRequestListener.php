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
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
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
        private JwtGenerator $jwtGenerator,
        private ClientMapper $clientMapper,
        private IUrlGenerator $urlGenerator,
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
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValue('client_expire_time', Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            $this->logger->warning('[TokenGenerationRequestListener] Client ' . $client->getId() . ' has expired');
            return;
        }

		$instanceUrl = $this->urlGenerator->getBaseUrl();
        $protocol = parse_url($instanceUrl, PHP_URL_SCHEME);
        $host = parse_url($instanceUrl, PHP_URL_HOST);

        // generate a new access token for the client
        $expireTime = (int)$this->appConfig->getAppValue('expire_time', Application::DEFAULT_EXPIRE_TIME);
		$code = $this->random->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($userId);
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr(Application::DEFAULT_SCOPE, 0, 128));
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime() + $expireTime);
        $accessToken->setNonce('');
		$accessToken->setAccessToken($this->jwtGenerator->generateAccessToken($accessToken, $client, $protocol, $host));
        $accessToken = $this->accessTokenMapper->insert($accessToken);

        $idToken = $this->jwtGenerator->generateIdToken($accessToken, $client, $protocol, $host, false);

        $event->setAccessToken($accessToken->getAccessToken());
        $event->setExpiresIn($expireTime);
        $event->setRefreshToken($code);
        $event->setIdToken($idToken);
        $refreshExpireTime = $this->appConfig->getAppValue('refresh_expire_time', Application::DEFAULT_REFRESH_EXPIRE_TIME);
        if ($refreshExpireTime !== 'never') {
            $event->setRefreshExpiresIn((int)$refreshExpireTime);
        }
    }
}
