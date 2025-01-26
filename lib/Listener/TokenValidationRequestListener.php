<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<TokenValidationRequestEvent|Event>
 */
class TokenValidationRequestListener implements IEventListener {

    public function __construct(
        private LoggerInterface $logger,
        private ITimeFactory $time,
        private IAppConfig $appConfig,
        private AccessTokenMapper $accessTokenMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof TokenValidationRequestEvent) {
            return;
        }

        $accessTokenString = $event->getAccessToken();
        $this->logger->debug('[TokenValidationRequestListener] received an access token validation request event');

        $expireTime = (int)$this->appConfig->getAppValue('expire_time', '0');

        try {
            $accessToken = $this->accessTokenMapper->getByAccessToken($accessTokenString);
            $hasExpired = $this->time->getTime() > $accessToken->getRefreshed() + $expireTime;
            // cleanup expired access token
            if ($hasExpired) {
                $this->accessTokenMapper->delete($accessToken);
                $event->setIsValid(false);
            } else {
                $event->setIsValid(true);
                $event->setUserId($accessToken->getUserId());
            }
        } catch (AccessTokenNotFoundException $e) {
            $event->setIsValid(false);
        }
    }
}
