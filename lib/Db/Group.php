<?php
/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 */
class Group extends Entity
{
    /** @var int */
    public $id;
    /** @var int */
    protected $clientId;
    /** @var string */
    protected $groupId;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('client_id', 'int');
        $this->addType('group_id', 'string');
    }
}
