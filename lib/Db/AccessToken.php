<?php
/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getHashedCode()
 * @method void setHashedCode(string $hashedCode)
 * @method string getAccessToken()
 * @method void setAccessToken(string $accessToken)
 * @method int getCreated()
 * @method void setCreated(int $timestamp)
 * @method int getRefreshed()
 * @method void setRefreshed(int $timestamp)
 * @method string getNonce()
 * @method void setNonce(string $nonce)
 * @method string getResource()
 * @method void setResource(string $resource)
 */
class AccessToken extends Entity
{
    /** @var int */
    public $id;
    /** @var int */
    protected $clientId;
    /** @var string */
    protected $userId;
    /** @var string */
    protected $scope;
    /** @var string */
    protected $hashedCode;
    /** @var string */
    protected $accessToken;
    /** @var int */
    protected $created;
    /** @var int */
    protected $refreshed;
    /** @var string */
    protected $nonce;
    /** @var string */
    protected $resource;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('clientId', 'int');
        $this->addType('userId', 'string');
        $this->addType('scope', 'string');
        $this->addType('hashedCode', 'string');
        $this->addType('accessToken', 'string');
        $this->addType('created', 'int');
        $this->addType('refreshed', 'int');
        $this->addType('nonce', 'string');
        $this->addType('resource', 'string');
    }
}
