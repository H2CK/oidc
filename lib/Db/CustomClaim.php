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
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getFunction()
 * @method void setFunction(string $function)
 * @method string|null getParameter()
 * @method void setParameter(?string $parameter)
 */
class CustomClaim extends Entity
{
    /** @var int */
    public $id;
    /** @var int */
    protected $clientId;
    /** @var string */
    protected $scope;
    /** @var string */
    protected $name;
    /** @var string */
    protected $function;
    /** @var string|null */
    protected $parameter;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('clientId', 'int');
        $this->addType('scope', 'string');
        $this->addType('name', 'string');
        $this->addType('function', 'string');
        $this->addType('parameter', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'client_id' => $this->clientId,
            'scope' => $this->scope,
            'name' => $this->name,
            'function' => $this->function,
            'parameter' => $this->parameter
        ];
    }
}
