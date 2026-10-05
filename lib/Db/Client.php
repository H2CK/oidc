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
 * @method string|null getApplicationType()
 * @method void setApplicationType(string|null $applicationType)
 * @method string|null getTokenEndpointAuthMethod()
 * @method void setTokenEndpointAuthMethod(string|null $tokenEndpointAuthMethod)
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
    /** @var string|null */
    protected $applicationType;
    /** @var string|null */
    protected $tokenEndpointAuthMethod;
    protected $grantTypes = null;
    protected $responseTypes = null;

    public function __construct(
        $name = '',
        $redirectUris = [],
        $signingAlg = 'RS256',
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
        $frontchannelLogoutSessionRequired = false,
        $applicationType = null,
        $tokenEndpointAuthMethod = null
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
        $this->addType('application_type', Types::STRING);
        $this->addType('token_endpoint_auth_method', Types::STRING);
        $this->addType('grantTypes', Types::STRING);
        $this->addType('responseTypes', Types::STRING);

        $this->setName($name);
        $this->redirectUris = $redirectUris;
        $this->setSigningAlg($signingAlg == 'RS256' ? 'RS256' : 'HS256');
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
        $this->setApplicationType($applicationType);
        $this->setTokenEndpointAuthMethod($tokenEndpointAuthMethod);

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

    /** @return list<string> */
    public function getRegisteredGrantTypes(): array {
        if ($this->grantTypes !== null) {
            return json_decode($this->grantTypes, true, 512, JSON_THROW_ON_ERROR);
        }
        // Static/legacy clients retain established flows. Legacy DCR clients
        // must explicitly register device grants before using them.
        $grants = ['authorization_code', 'refresh_token'];
        if (str_contains($this->getFlowType(), 'id_token')) {
            $grants[] = 'implicit';
        }
        if (!$this->isDcr()) {
            $grants[] = 'urn:ietf:params:oauth:grant-type:device_code';
        }
        if ($this->getTexEnabled()) {
            $grants[] = 'urn:ietf:params:oauth:grant-type:token-exchange';
        }
        return $grants;
    }

    public function setRegisteredGrantTypes(array $grants): void {
        $this->setGrantTypes(json_encode(array_values(array_unique($grants)), JSON_THROW_ON_ERROR));
    }

    public function allowsGrantType(string $grant): bool {
        return in_array($grant, $this->getRegisteredGrantTypes(), true);
    }

    /** @return list<string> */
    public function getRegisteredResponseTypes(): array {
        if ($this->responseTypes !== null) {
            return json_decode($this->responseTypes, true, 512, JSON_THROW_ON_ERROR);
        }
        return str_contains($this->getFlowType(), 'id_token')
            ? ['code', 'id_token', 'code id_token', 'id_token token', 'code id_token token'] : ['code'];
    }

    public function setRegisteredResponseTypes(array $responses): void {
        $this->setResponseTypes(json_encode(array_values(array_unique($responses)), JSON_THROW_ON_ERROR));
    }

    public function supportsResponseType(string $response): bool {
        $entries = preg_split('/ +/', trim($response), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($entries, SORT_STRING);
        return in_array(implode(' ', $entries), $this->getRegisteredResponseTypes(), true);
    }

    public function setRedirectUris(array $uris): void {
        $this->redirectUris = $uris;
    }

    public function isNativeApplication(): bool {
        // Static public clients predate application_type and represent native apps.
        return $this->getApplicationType() === 'native'
            || ($this->getApplicationType() === null && $this->getType() === 'public');
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
            'frontchannel_logout_session_required' => $this->getFrontchannelLogoutSessionRequired(),
            'application_type' => $this->getApplicationType(),
            'token_endpoint_auth_method' => $this->getTokenEndpointAuthMethod()
        ];
    }
}
