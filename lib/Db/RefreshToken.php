<?php

declare(strict_types=1);

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
 * @method string getHashedToken()
 * @method void setHashedToken(string $hashedToken)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getUsedAt()
 * @method void setUsedAt(int $usedAt)
 */
class RefreshToken extends Entity {
    /** @var int */
    public $id;
    /** @var int */
    protected $accessTokenId;
    /** @var string */
    protected $hashedToken;
    /** @var int */
    protected $created;
    /** @var int */
    protected $usedAt;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('accessTokenId', 'int');
        $this->addType('hashedToken', 'string');
        $this->addType('created', 'int');
        $this->addType('usedAt', 'int');
    }
}
