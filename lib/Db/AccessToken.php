<?php
/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
 * @method string getCodeChallenge()
 * @method void setCodeChallenge(string $codeChallenge)
 * @method string getCodeChallengeMethod()
 * @method void setCodeChallengeMethod(string $codeChallengeMethod)
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
    /** @var string */
    protected $codeChallenge;
    /** @var string */
    protected $codeChallengeMethod;

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
        $this->addType('codeChallenge', 'string');
        $this->addType('codeChallengeMethod', 'string');
    }
}
