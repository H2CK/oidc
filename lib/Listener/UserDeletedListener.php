<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
    public function __construct(
        private AccessTokenMapper $accessTokenMapper,
        private UserConsentMapper $userConsentMapper,
        private DeviceCodeMapper $deviceCodeMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }
        $uid = $event->getUser()->getUID();
        // The mapper also removes refresh tokens, codes and exchanged descendants.
        $this->accessTokenMapper->deleteByUserId($uid);
        $this->userConsentMapper->deleteByUserId($uid);
        $this->deviceCodeMapper->deleteByUserId($uid);
    }
}
