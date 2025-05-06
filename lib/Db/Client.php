<?php
/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
 * @method boolean isDcr()
 * @method void setDcr(boolean $dcr)
 * @method int getIssuedAt()
 * @method void setIssuedAt(int $issuedAt)
 * @method string getTokenType()
 * @method void setTokenType(string $tokenType)
 */
class Client extends Entity implements JsonSerializable {
    /** @var int */
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
    /** @var string */
    protected $tokenType;

    public function __construct(
        $name = '',
        $redirectUris = [],
        $algorithm = 'RS256',
        $type = 'confidential',
        $flowType = 'code',
        $tokenType = 'opaque',
        $dcr = false
    ) {
        $this->setName($name);
        $this->redirectUris = $redirectUris;
        $this->setSigningAlg($algorithm == 'RS256' ? 'RS256' : 'HS256');
        $this->setType($type == 'public' ? 'public' : 'confidential');
        $this->setFlowType($flowType == 'code' ? 'code' : 'code id_token');
        $this->setTokenType($tokenType);
        $this->setDcr($dcr);
        $this->setIssuedAt(time());

        $this->addType('id', Types::INTEGER);
        $this->addType('name', Types::STRING);
        $this->addType('client_identifier', Types::STRING);
        $this->addType('secret', Types::STRING);
        $this->addType('signing_alg', Types::STRING);
        $this->addType('type', Types::STRING);
        $this->addType('flow_type', Types::STRING);
        $this->addType('dcr', Types::BOOLEAN);
        $this->addType('issued_at', Types::INTEGER);
        $this->addType('token_type', Types::STRING);
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
            'token_type' => $this->getTokenType()
        ];
    }
}
