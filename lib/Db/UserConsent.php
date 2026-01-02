<?php
/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string getScopesGranted()
 * @method void setScopesGranted(string $scopesGranted)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $timestamp)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $timestamp)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $timestamp)
 */
class UserConsent extends Entity
{
    /** @var int */
    public $id;
    /** @var string */
    protected $userId;
    /** @var int */
    protected $clientId;
    /** @var string */
    protected $scopesGranted;
    /** @var int */
    protected $createdAt;
    /** @var int */
    protected $updatedAt;
    /** @var int|null */
    protected $expiresAt;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('userId', 'string');
        $this->addType('clientId', 'int');
        $this->addType('scopesGranted', 'string');
        $this->addType('createdAt', 'int');
        $this->addType('updatedAt', 'int');
        $this->addType('expiresAt', 'int');
    }
}
