<?php
/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
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
 * @method string getAllowedScopes()
 * @method void setAllowedScopes(string $allowedScopes)
 * @method string getEmailRegex()
 * @method void setEmailRegex(string $emailRegex)
 * @method string|null getResourceUrl()
 * @method void setResourceUrl(string|null $resourceUrl)
 * @method bool getTexEnabled()
 * @method void setTexEnabled(bool $texEnabled)
 * @method string|null getTexAllowedScopes()
 * @method void setTexAllowedScopes(string|null $texAllowedScopes)
 * @method string|null getBackchannelLogoutUri()
 * @method void setBackchannelLogoutUri(string|null $backchannelLogoutUri)
 * @method bool getBackchannelLogoutSessReq()
 * @method void setBackchannelLogoutSessReq(bool $backchannelLogoutSessReq)
 * @method string|null getFrontchannelLogoutUri()
 * @method void setFrontchannelLogoutUri(string|null $frontchannelLogoutUri)
 * @method bool getFrontchannelLogoutSessReq()
 * @method void setFrontchannelLogoutSessReq(bool $frontchannelLogoutSessReq)
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
    /** @var string */
    protected $allowedScopes;
    /** @var string */
    protected $emailRegex;
    /** @var string|null */
    protected $resourceUrl;
    /** @var bool */
    protected $texEnabled = false;
    /** @var string|null */
    protected $texAllowedScopes;
    /** @var string|null */
    protected $backchannelLogoutUri;
    /** @var bool */
    protected $backchannelLogoutSessReq = false;
    /** @var string|null */
    protected $frontchannelLogoutUri;
    /** @var bool */
    protected $frontchannelLogoutSessReq = false;

    public function __construct(
        $name = '',
        $redirectUris = [],
        $algorithm = 'RS256',
        $type = 'confidential',
        $flowType = 'code',
        $tokenType = 'opaque',
        $allowedScopes = '',
        $emailRegex = '',
        $dcr = false,
        $texEnabled = false,
        $texAllowedScopes = null,
        $backchannelLogoutUri = null,
        $backchannelLogoutSessionRequired = false,
        $frontchannelLogoutUri = null,
        $frontchannelLogoutSessionRequired = false
    ) {
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
        $this->addType('allowed_scopes', Types::STRING);
        $this->addType('email_regex', Types::STRING);
        $this->addType('resource_url', Types::STRING);
        $this->addType('tex_enabled', Types::BOOLEAN);
        $this->addType('tex_allowed_scopes', Types::STRING);
        $this->addType('backchannel_logout_uri', Types::STRING);
        $this->addType('backchannel_logout_sess_req', Types::BOOLEAN);
        $this->addType('frontchannel_logout_uri', Types::STRING);
        $this->addType('frontchannel_logout_sess_req', Types::BOOLEAN);

        $this->setName($name);
        $this->redirectUris = $redirectUris;
        $this->setSigningAlg($algorithm == 'RS256' ? 'RS256' : 'HS256');
        $this->setType($type == 'public' ? 'public' : 'confidential');
        $this->setFlowType($flowType == 'code' ? 'code' : 'code id_token');
        $this->setTokenType($tokenType);
        $this->setDcr($dcr);
        $this->setAllowedScopes($allowedScopes);
        $this->setEmailRegex($emailRegex);
        $this->setIssuedAt(time());
        $this->setTexEnabled($texEnabled);
        $this->setTexAllowedScopes($texAllowedScopes);
        $this->setBackchannelLogoutUri($backchannelLogoutUri);
        $this->setBackchannelLogoutSessionRequired($backchannelLogoutSessionRequired);
        $this->setFrontchannelLogoutUri($frontchannelLogoutUri);
        $this->setFrontchannelLogoutSessionRequired($frontchannelLogoutSessionRequired);

    }

    public function getBackchannelLogoutSessionRequired(): bool {
        return $this->getBackchannelLogoutSessReq();
    }

    public function setBackchannelLogoutSessionRequired(bool $backchannelLogoutSessionRequired): void {
        $this->setBackchannelLogoutSessReq($backchannelLogoutSessionRequired);
    }

    public function getFrontchannelLogoutSessionRequired(): bool {
        return $this->getFrontchannelLogoutSessReq();
    }

    public function setFrontchannelLogoutSessionRequired(bool $required): void {
        $this->setFrontchannelLogoutSessReq($required);
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
            'token_type' => $this->getTokenType(),
            'allowed_scopes' => $this->getAllowedScopes(),
            'email_regex' => $this->getEmailRegex(),
            'resource_url' => $this->getResourceUrl(),
            'tex_enabled' => $this->getTexEnabled(),
            'tex_allowed_scopes' => $this->getTexAllowedScopes(),
            'backchannel_logout_uri' => $this->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $this->getBackchannelLogoutSessionRequired(),
            'frontchannel_logout_uri' => $this->getFrontchannelLogoutUri(),
            'frontchannel_logout_session_required' => $this->getFrontchannelLogoutSessionRequired()
        ];
    }
}
