<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Listener;

use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Drop a group's scope ceiling when the group is deleted, so a later group
 * re-created under the same gid does not silently inherit it.
 *
 * @implements IEventListener<GroupDeletedEvent|Event>
 */
class GroupDeletedListener implements IEventListener {

    public function __construct(
        private GroupScopeMapper $groupScopeMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof GroupDeletedEvent) {
            return;
        }
        $this->groupScopeMapper->deleteByGroupId($event->getGroup()->getGID());
    }
}
