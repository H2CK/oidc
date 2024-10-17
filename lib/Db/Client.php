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

use JsonSerializable;

/**
 * @method int getId()
 * @method string getClientIdentifier()
 * @method void setClientIdentifier(string $clientIdentifier)
 * @method string getSecret()
 * @method void setSecret(string $secret)
 * @method string getRedirectUri()
 * @method void setRedirectUri(string $redirectUri)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSigningAlg()
 * @method void setSigningAlg(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getFlowType()
 * @method void setFlowType(string $flowType)
 * @method string isDcr()
 * @method void setDcr(boolean $dcr)
 * @method int getIssuedAt()
 * @method void setIssuedAt(int $issuedAt)
 */
class Client extends Entity implements JsonSerializable {
    public $id;
    /** @var string */
    protected $name;
    /** @var string[] */
    protected $redirectUris;
    /** @var string */
    protected $clientIdentifier;
    /** @var string */
    protected $secret;
    /** @var string */
    protected $signingAlg;
    /** @var string */
    protected $type;
    /** @var string */
    protected $flowType;
    /** @var bool */
    protected $dcr;
    /** @var int */
    protected $issuedAt = 0;

    public function __construct(
        $name = '',
        $redirectUris = [],
        $algorithm = 'RSA256',
        $type = 'confidential',
        $flowType = 'code',
        $dcr = false
    ) {
        $this->setName($name);
        $this->redirectUris = $redirectUris;
        $this->setSigningAlg($algorithm == 'RSA256' ? 'RSA256' : 'HS256');
        $this->setType($type == 'public' ? 'public' : 'confidential');
        $this->setFlowType($flowType);
        $this->setDcr($dcr);

        $this->addType('id', 'int');
        $this->addType('name', 'string');
        $this->addType('client_identifier', 'string');
        $this->addType('secret', 'string');
        $this->addType('signing_alg', 'string');
        $this->addType('type', 'string');
        $this->addType('flow_type', 'string');
        $this->addType('dcr', 'boolean');
        $this->addType('issued_at', 'int');
    }

    public function getRedirectUris(): array {
        return $this->redirectUris;
    }

    public function setRedirectUris(array $uris): void {
        $this->redirectUris = $uris;
    }

    /**
     * Implement JsonSerializable interface
     * @return array An associative array representing the Client object
     */
    public function jsonSerialize() {
        return [
            'name' => $this->name,
            'redirect_uris' => $this->getRedirectUris(),
            'jwt_alg' => $this->signingAlg,
            'type' => $this->type,
            'client_id' => $this->clientIdentifier,
            'client_secret' => $this->secret,
            'flow_type' => $this->flowType,
            'dcr' => $this->dcr,
            'issued_at' => $this->issuedAt
        ];
    }
}
