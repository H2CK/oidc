<?php
/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getAccessTokenId()
 * @method void setAccessTokenId(int $accessTokenId)
 * @method string getHashedCode()
 * @method void setHashedCode(string $hashedCode)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUsedAt()
 * @method void setUsedAt(int $usedAt)
 */
class AuthorizationCode extends Entity {
    /** @var int */
    public $id;
    /** @var int */
    protected $accessTokenId;
    /** @var string */
    protected $hashedCode;
    /** @var int */
    protected $created;
    /** @var int */
    protected $usedAt;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('accessTokenId', 'int');
        $this->addType('hashedCode', 'string');
        $this->addType('created', 'int');
        $this->addType('usedAt', 'int');
    }
}
