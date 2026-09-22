<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string|null getScopes()
 * @method void setScopes(string $scopes)
 */
class GroupScope extends Entity implements \JsonSerializable {
    /** @var string */
    protected $groupId;
    /** @var string|null */
    protected $scopes;

    public function __construct() {
        $this->addType('group_id', 'string');
        $this->addType('scopes', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'groupId' => $this->groupId,
            'scopes' => $this->scopes ?? '',
        ];
    }
}
