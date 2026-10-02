<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCP\IGroupManager;
use OCP\IUser;

class ClientAuthorizationService {
    public function __construct(
        private GroupMapper $groupMapper,
        private IGroupManager $groupManager,
    ) {
    }

    public function isUserAllowedForClient(IUser $user, Client $client): bool {
        if (!$user->isEnabled()) {
            return false;
        }
        $clientGroups = $this->groupMapper->getGroupsByClientId($client->getId());
        if ($clientGroups === []) {
            return true;
        }

        $userGroupIds = array_map(
            static fn ($group): string => $group->getGID(),
            $this->groupManager->getUserGroups($user),
        );

        foreach ($clientGroups as $clientGroup) {
            if (in_array($clientGroup->getGroupId(), $userGroupIds, true)) {
                return true;
            }
        }

        return false;
    }
    }
