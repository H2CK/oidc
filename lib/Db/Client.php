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
use \OCP\DB\Types;

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
 * @method string isJwtAccessToken()
 * @method void setJwtAccessToken(boolean $jwtAccessToken)
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
	protected $jwtAccessToken;
    /** @var bool */
    protected $dcr;
    /** @var int */
    protected $issuedAt = 0;

    public function __construct(
        $name = '',
        $redirectUris = [],
        $algorithm = 'RS256',
        $type = 'confidential',
        $flowType = 'code',
		$jwtAccessToken = false,
        $dcr = false
    ) {
        $this->setName($name);
        $this->redirectUris = $redirectUris;
        $this->setSigningAlg($algorithm == 'RS256' ? 'RS256' : 'HS256');
        $this->setType($type == 'public' ? 'public' : 'confidential');
        $this->setFlowType($flowType == 'code' ? 'code' : 'code id_token');
		$this->setJwtAccessToken($jwtAccessToken);
        $this->setDcr($dcr);
        $this->setIssuedAt(time());

        $this->addType('id', Types::INTEGER);
        $this->addType('name', Types::STRING);
        $this->addType('client_identifier', Types::STRING);
        $this->addType('secret', Types::STRING);
        $this->addType('signing_alg', Types::STRING);
        $this->addType('type', Types::STRING);
        $this->addType('flow_type', Types::STRING);
		$this->addType('jwt_access_token', Types::BOOLEAN);
        $this->addType('dcr', Types::BOOLEAN);
        $this->addType('issued_at', Types::INTEGER);

    }

    public function getRedirectUris(): array {
        return $this->redirectUris;
    }

    public function setRedirectUris(array $uris): void {
        $this->redirectUris = $uris;
    }

	public function isJwtAccessToken(): bool {
		return $this->jwtAccessToken;
	}

	public function isDcr(): bool {
		return $this->dcr;
	}

    /**
     * Implement JsonSerializable interface
     * @return array An associative array representing the Client object
     */
    public function jsonSerialize(): mixed {
        return [
            'name' => $this->getName(),
            'redirect_uris' => $this->getRedirectUris(),
            'jwt_alg' => $this->getSigningAlg(),
            'type' => $this->getType(),
            'client_id' => $this->getClientIdentifier(),
            'client_secret' => $this->getSecret(),
            'flow_type' => $this->getFlowType(),
            'dcr' => $this->isDcr(),
            'issued_at' => $this->getIssuedAt(),
			'jwt_access_token' => $this->isJwtAccessToken()
        ];
    }
}
