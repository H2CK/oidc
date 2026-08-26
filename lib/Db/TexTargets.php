<?php

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string|null getResourceUrl()
 * @method void setResourceUrl(?string $resourceUrl)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUsedAt()
 * @method void setUsedAt(int $usedAt)
 */
class TexTargets extends Entity {
    /** @var int */
    public $id;
    /** @var int */
    protected $clientId;
    /** @var string|null */
    protected $resourceUrl;
    /** @var int */
    protected $created;
    /** @var int */
    protected $usedAt;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('clientId', 'int');
        $this->addType('resourceUrl', 'string');
        $this->addType('created', 'int');
        $this->addType('usedAt', 'int');
    }
}
