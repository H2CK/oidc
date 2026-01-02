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
 * @method void setClientId(int $identifier)
 * @method string getRedirectUri()
 * @method void setRedirectUri(string $redirectUri)
 */
class LogoutRedirectUri extends Entity {
    /** @var int */
    public $id;
    /** @var string */
    protected $redirectUri;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('redirect_uri', 'string');
    }
}
