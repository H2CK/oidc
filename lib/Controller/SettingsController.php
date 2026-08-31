<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\TexTargets;
use OCA\OIDCIdentityProvider\Db\TexSubjectClient;
use OCA\OIDCIdentityProvider\Db\TexSubjectClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\CredentialService;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller
{
    /** @var ClientMapper */
    private $clientMapper;
    /** @var AccessTokenMapper  */
    private $accessTokenMapper;
    /** @var RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var LogoutRedirectUriMapper  */
    private $logoutRedirectUriMapper;
    /** @var GroupMapper  */
    private $groupMapper;
    /** @var IGroupManager  */
    private $groupManager;
    /** @var RedirectUriService  */
    private $redirectUriService;
    /** @var IL10N */
    private $l;
    /** @var IUserSession */
    private $userSession;
    /** @var IAppConfig */
    private $appConfig;
    /** @var IUserConfig */
    private $userConfig;
    /** @var IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var CredentialService */
    private $credentialService;
    /** @var TexSubjectClientMapper|null */
    private $texSubjectClientMapper;

    public const CODE_AUTHORIZATION_FLOW= 'Code Authorization Flow';
    public const CODE_IMPLICIT_AUTHORIZATION_FLOW = 'Code & Implicit Authorization Flow';

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ClientMapper $clientMapper,
                    AccessTokenMapper $accessTokenMapper,
                    RedirectUriMapper $redirectUriMapper,
                    LogoutRedirectUriMapper $logoutRedirectUriMapper,
                    GroupMapper $groupMapper,
                    RedirectUriService $redirectUriService,
                    IGroupManager $groupManager,
                    IL10N $l,
                    IUserSession $userSession,
                    IAppConfig $appConfig,
                    IUserConfig $userConfig,
                    IConfig $config,
                    CredentialService $credentialService,
                    LoggerInterface $logger,
                    ?TexSubjectClientMapper $texSubjectClientMapper = null
                    )
    {
        parent::__construct($appName, $request);
        $this->clientMapper = $clientMapper;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->logoutRedirectUriMapper = $logoutRedirectUriMapper;
        $this->groupMapper = $groupMapper;
        $this->redirectUriService = $redirectUriService;
        $this->groupManager = $groupManager;
        $this->l = $l;
        $this->userSession =$userSession;
        $this->appConfig = $appConfig;
        $this->userConfig = $userConfig;
        $this->config =$config;
        $this->credentialService = $credentialService;
        $this->logger = $logger;
        $this->texSubjectClientMapper = $texSubjectClientMapper;
    }

    public function addClient(
                    string $name,
                    string $redirectUri,
                    string $signingAlg,
                    string $type,
                    string $flowType,
                    string $tokenType = '',
                    string|null $clientId = null,
                    string|null $clientSecret = null,
                    ): JSONResponse
    {
        $this->logger->debug("Adding client " . $name. " with Redirect URI " .$redirectUri);

        try {
            if ($this->redirectUriService->isValidRedirectUri($redirectUri, $this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS, Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS) === 'true') === false) {
                return new JSONResponse(['message' => $this->l->t('Your redirect URL needs to be a full URL for example: https://yourdomain.com/path')], Http::STATUS_BAD_REQUEST);
            }
        } catch (RedirectUriValidationException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        // Use configured default if token type is not specified
        if (empty($tokenType)) {
            $tokenType = $this->appConfig->getAppValueString(
                Application::APP_CONFIG_DEFAULT_TOKEN_TYPE,
                Application::DEFAULT_TOKEN_TYPE
            );
        }

        $client = new Client(
            $name,
            [ $redirectUri ],
            $signingAlg,
            $type,
            $flowType,
            $tokenType,
        );

        if (isset($clientId) && trim($clientId) !== '') {
            if (filter_var($clientId, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/^[\x21-\x39\x3B-\x7E]{32,64}$/"))) === false) {
                return new JSONResponse(['message' => $this->l->t('Your client ID must comply with the following rules: printable ASCII except : and length 32-64')], Http::STATUS_BAD_REQUEST);
            }
            $client->setClientIdentifier($clientId);
        }

        if (isset($clientSecret) && trim($clientSecret) !== '') {
            if (filter_var($clientSecret, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/^[\x21-\x39\x3B-\x7E]{32,64}$/"))) === false) {
                return new JSONResponse(['message' => $this->l->t('Your client secret must comply with the following rules: printable ASCII except : and length 32-64')], Http::STATUS_BAD_REQUEST);
            }
            $client->setSecret($clientSecret);
        }

        $client = $this->clientMapper->insert($client);

        $redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
        $resultRedirectUris = [];
        foreach ($redirectUris as $tmpRedirectUri) {
            $resultRedirectUris[] = [
                'id' => $tmpRedirectUri->getId(),
                'client_id' => $tmpRedirectUri->getClientId(),
                'redirect_uri' => $tmpRedirectUri->getRedirectUri(),
            ];
        }
        $groups = $this->groupMapper->getGroupsByClientId($client->getId());
        $resultGroups = [];
        foreach ($groups as $group) {
            array_push($resultGroups, $group->getGroupId());
        }
        $flowTypeLabel = $this->l->t(SettingsController::CODE_AUTHORIZATION_FLOW);
        $responseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
        if (in_array('id_token', $responseTypeEntries)) {
            $flowTypeLabel = $this->l->t(SettingsController::CODE_IMPLICIT_AUTHORIZATION_FLOW);
        }

        return new JSONResponse([
            'id' => $client->getId(),
            'name' => $client->getName(),
            'redirectUris' => $resultRedirectUris,
            'clientId' => $client->getClientIdentifier(),
            'clientSecret' => $client->getSecret(),
            'signingAlg' => $client->getSigningAlg(),
            'type' => $client->getType(),
            'flowType' => $client->getFlowType(),
            'flowTypeLabel' => $flowTypeLabel,
            'groups' => $resultGroups,
            'tokenType' => strtolower($client->getTokenType())==='jwt' ? 'jwt' : 'opaque',
            'allowedScopes' => $client->getAllowedScopes(),
            'emailRegex' => $client->getEmailRegex(),
            'resourceUrl' => $client->getResourceUrl(),
            'backchannelLogoutUri' => $client->getBackchannelLogoutUri(),
            'backchannelLogoutSessionRequired' => $client->getBackchannelLogoutSessionRequired(),
        ]);
    }

    public function updateClient(
                    int $id,
                    array $groups
                    ): JSONResponse
    {
        $this->logger->debug("Updating groups for client " . $id);
        $this->groupMapper->deleteByClientId($id);
        foreach ($groups as $group) {
            if ($this->groupManager->groupExists($group)) {
                $groupObj = new Group();
                $groupObj->setClientId($id);
                $groupObj->setGroupId($group);
                $this->groupMapper->insert($groupObj);
            }
        }
        return new JSONResponse([]);
    }

    public function updateClientConfiguration(int $client_id): JSONResponse
    {
        $params = $this->request->getParams();
        $client = $this->clientMapper->getByUid($client_id);

        if (array_key_exists('name', $params)) {
            $client->setName(trim((string)$params['name']));
        }
        if (array_key_exists('signingAlg', $params)) {
            $client->setSigningAlg($params['signingAlg'] === 'RS256' ? 'RS256' : 'HS256');
        }
        if (array_key_exists('type', $params)) {
            $client->setType($params['type'] === 'public' ? 'public' : 'confidential');
        }
        if ($client->getType() === 'public') {
            $client->setTexEnabled(false);
        }
        if (array_key_exists('flowType', $params)) {
            $client->setFlowType(str_contains((string)$params['flowType'], 'id_token') ? 'code id_token' : 'code');
        }
        if (array_key_exists('tokenType', $params)) {
            $client->setTokenType($params['tokenType'] === 'jwt' ? 'jwt' : 'opaque');
        }
        if (array_key_exists('allowedScopes', $params)) {
            $allowedScopes = trim((string)$params['allowedScopes']);
            if (!preg_match('/^[a-zA-Z0-9 _:\.\/-]{0,512}$/u', $allowedScopes)) {
                return new JSONResponse(['error' => 'Scope contains invalid characters.'], Http::STATUS_BAD_REQUEST);
            }
            $client->setAllowedScopes($allowedScopes);
        }
        if (array_key_exists('emailRegex', $params)) {
            $client->setEmailRegex(mb_substr(trim((string)$params['emailRegex']), 0, 255));
        }
        if (array_key_exists('resourceUrl', $params)) {
            $resourceUrl = trim((string)$params['resourceUrl']);
            if ($resourceUrl !== '' && (mb_strlen($resourceUrl) > 512 || !filter_var($resourceUrl, FILTER_VALIDATE_URL))) {
                return new JSONResponse(['error' => 'Invalid resource URL format.'], Http::STATUS_BAD_REQUEST);
            }
            $client->setResourceUrl($resourceUrl === '' ? null : $resourceUrl);
        }
        if (array_key_exists('backchannelLogoutUri', $params)) {
            $backchannelLogoutUri = trim((string)$params['backchannelLogoutUri']);
            if ($backchannelLogoutUri === '') {
                $client->setBackchannelLogoutUri(null);
                $client->setBackchannelLogoutSessionRequired(false);
            } elseif (!BackChannelLogoutService::isValidBackChannelLogoutUri($backchannelLogoutUri, $client->getType())) {
                return new JSONResponse(['error' => 'Invalid Back-Channel Logout URI. Use an absolute HTTP(S) URI without a fragment; HTTP is only allowed for confidential clients.'], Http::STATUS_BAD_REQUEST);
            } else {
                $client->setBackchannelLogoutUri($backchannelLogoutUri);
            }
        }
        if (array_key_exists('backchannelLogoutSessionRequired', $params)) {
            $client->setBackchannelLogoutSessionRequired((bool)$params['backchannelLogoutSessionRequired']);
        }
        if ($client->getBackchannelLogoutUri() !== null
            && !BackChannelLogoutService::isValidBackChannelLogoutUri($client->getBackchannelLogoutUri(), $client->getType())) {
            return new JSONResponse(['error' => 'The configured Back-Channel Logout URI is not valid for this client type.'], Http::STATUS_BAD_REQUEST);
        }
        if ($client->getBackchannelLogoutSessionRequired() && $client->getBackchannelLogoutUri() === null) {
            return new JSONResponse(['error' => 'Back-Channel Logout session support requires a Back-Channel Logout URI.'], Http::STATUS_BAD_REQUEST);
        }
        if (array_key_exists('redirectUris', $params)) {
            $redirectUris = array_map('trim', (array)$params['redirectUris']);
            foreach ($redirectUris as $redirectUri) {
                try {
                    if (!$this->redirectUriService->isValidRedirectUri(
                        $redirectUri,
                        $this->appConfig->getAppValueString(
                            Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS,
                            Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS
                        ) === 'true'
                    )) {
                        return new JSONResponse(['error' => 'Invalid redirect URI.'], Http::STATUS_BAD_REQUEST);
                    }
                } catch (RedirectUriValidationException $e) {
                    return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
                }
            }
        }
        $texTargetUrls = null;
        if (array_key_exists('texTargets', $params)) {
            $texTargetUrls = [];
            foreach ((array)$params['texTargets'] as $resourceUrl) {
                $resourceUrl = trim((string)$resourceUrl);
                if (mb_strlen($resourceUrl) > 512 || !$this->isValidTokenExchangeResourceUri($resourceUrl)) {
                    return new JSONResponse(['error' => 'Invalid Token Exchange target URI. The value must be an absolute URI without a fragment.'], Http::STATUS_BAD_REQUEST);
                }
                $texTargetUrls[] = $resourceUrl;
            }
        }
        $texAllowedSubjectClientIds = null;
        if (array_key_exists('texAllowedSubjectClients', $params)) {
            $texAllowedSubjectClientIds = [];
            $seenSubjectClientIds = [];
            foreach ((array)$params['texAllowedSubjectClients'] as $subjectClientIdentifier) {
                $subjectClientIdentifier = trim((string)$subjectClientIdentifier);
                if ($subjectClientIdentifier === '') {
                    continue;
                }

                try {
                    $subjectClient = $this->clientMapper->getByIdentifier($subjectClientIdentifier);
                } catch (\Exception $e) {
                    return new JSONResponse([
                        'error' => 'Unknown Token Exchange subject client: ' . $subjectClientIdentifier,
                    ], Http::STATUS_BAD_REQUEST);
                }

                if ($subjectClient === null) {
                    return new JSONResponse([
                        'error' => 'Unknown Token Exchange subject client: ' . $subjectClientIdentifier,
                    ], Http::STATUS_BAD_REQUEST);
                }

                $subjectClientId = $subjectClient->getId();
                if (!isset($seenSubjectClientIds[$subjectClientId])) {
                    $seenSubjectClientIds[$subjectClientId] = true;
                    $texAllowedSubjectClientIds[] = $subjectClientId;
                }
            }
        }
        if (array_key_exists('texEnabled', $params)) {
            if ($client->getType() === 'confidential') {
                $client->setTexEnabled((bool)$params['texEnabled']);
            }
        }
        if (array_key_exists('texAllowedScopes', $params)) {
            $texAllowedScopes = trim((string)$params['texAllowedScopes']);
            if (!preg_match('/^[a-zA-Z0-9 _:\.\/-]{0,512}$/u', $texAllowedScopes)) {
                return new JSONResponse(['error' => 'Token Exchange scope contains invalid characters.'], Http::STATUS_BAD_REQUEST);
            }
            $client->setTexAllowedScopes($texAllowedScopes === '' ? null : $texAllowedScopes);
        }

        // Enabling Token Exchange is only valid with at least one explicit
        // subject-token client. Existing selections may be retained when the
        // caller changes unrelated settings or re-enables TEX.
        if ($client->getTexEnabled()) {
            if (trim((string)($client->getTexAllowedScopes() ?? '')) === '') {
                return new JSONResponse([
                    'error' => 'At least one allowed Token Exchange scope must be configured before Token Exchange can be enabled.',
                ], Http::STATUS_BAD_REQUEST);
            }

            if ($this->texSubjectClientMapper === null) {
                return new JSONResponse(['error' => 'Token Exchange subject-client policy is unavailable.'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $effectiveSubjectClientIds = $texAllowedSubjectClientIds;
            if ($effectiveSubjectClientIds === null) {
                $effectiveSubjectClientIds = array_map(
                    static fn (TexSubjectClient $entry): int => $entry->getSubjectClientId(),
                    $this->texSubjectClientMapper->getByClientId($client_id)
                );
            }

            if ($effectiveSubjectClientIds === []) {
                return new JSONResponse([
                    'error' => 'At least one allowed subject client must be selected before Token Exchange can be enabled.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        $this->clientMapper->update($client);

        if (array_key_exists('redirectUris', $params)) {
            $this->redirectUriMapper->deleteByClientId($client_id);
            foreach ($redirectUris as $redirectUri) {
                $redirectUriEntity = new RedirectUri();
                $redirectUriEntity->setClientId($client_id);
                $redirectUriEntity->setRedirectUri($redirectUri);
                $this->redirectUriMapper->insert($redirectUriEntity);
            }
        }

        if (array_key_exists('groups', $params)) {
            $this->groupMapper->deleteByClientId($client_id);
            foreach ((array)$params['groups'] as $group) {
                if ($this->groupManager->groupExists($group)) {
                    $groupEntity = new Group();
                    $groupEntity->setClientId($client_id);
                    $groupEntity->setGroupId($group);
                    $this->groupMapper->insert($groupEntity);
                }
            }
        }

        if (array_key_exists('texTargets', $params)) {
            $texTargetMapper = \OCP\Server::get(TexTargetMapper::class);
            $texTargetMapper->deleteByClientId($client_id);
            foreach ($texTargetUrls as $resourceUrl) {
                $target = new TexTargets();
                $target->setClientId($client_id);
                $target->setResourceUrl($resourceUrl);
                $target->setCreated(time());
                $target->setUsedAt(0);
                $texTargetMapper->insert($target);
            }
        }

        if ($texAllowedSubjectClientIds !== null || $client->getType() === 'public') {
            if ($this->texSubjectClientMapper === null) {
                return new JSONResponse(['error' => 'Token Exchange subject-client policy is unavailable.'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
            $this->texSubjectClientMapper->deleteByClientId($client_id);
            if ($client->getType() !== 'public') {
                foreach ($texAllowedSubjectClientIds ?? [] as $subjectClientId) {
                    $entry = new TexSubjectClient();
                    $entry->setClientId($client_id);
                    $entry->setSubjectClientId($subjectClientId);
                    $this->texSubjectClientMapper->insert($entry);
                }
            }
        }

        return new JSONResponse(['client' => $client->jsonSerialize()]);
    }

    public function updateClientFlow(
                    int $id,
                    string $flowType
                    ): JSONResponse
    {
        $this->logger->debug("Updating flow_type for client " . $id);
        $client = $this->clientMapper->getByUid($id);
        $allowedResponseTypeEntries = explode(' ', strtolower(trim($flowType)), 3);
        if (in_array('id_token', $allowedResponseTypeEntries)) {
            $client->setFlowType('code id_token');
        } else {
            $client->setFlowType('code');
        }
        $this->clientMapper->update($client);
        return new JSONResponse([]);
    }

    public function updateTokenType(
        int $id,
        string $tokenType
        ): JSONResponse
    {
        $this->logger->debug("Updating tokenType for client " . $id . " with value " .$tokenType);
        $client = $this->clientMapper->getByUid($id);
        $client->setTokenType(($tokenType==='jwt') ? 'jwt' : 'opaque');
        $this->clientMapper->update($client);
        return new JSONResponse([]);
    }

    public function updateAllowedScopes(
        int $id,
        string $allowedScopes
        ): JSONResponse
    {
        $allowedScopes = trim($allowedScopes);
        $allowedScopes = mb_substr($allowedScopes, 0, 512);
        // RFC 6749 allows most printable ASCII except space (used as separator), backslash, and double-quote
        // Commonly used characters: letters, numbers, underscore, hyphen, colon, period, forward slash
        if (!preg_match('/^[a-zA-Z0-9 _:\.\/-]*$/u', $allowedScopes)) {
             return new JSONResponse(['error' => 'Scope contains invalid characters. Allowed: alphanumeric, spaces, underscores, hyphens, colons, periods, and forward slashes.']);
         }

        $this->logger->debug("Updating allowedScopes for client " . $id . " with value " .$allowedScopes);
        $client = $this->clientMapper->getByUid($id);
        $client->setAllowedScopes($allowedScopes);
        $this->clientMapper->update($client);

        // Return updated clients list to refresh UI
        $clients = $this->clientMapper->getClients();
        $result = [];

        foreach ($clients as $client) {
            $redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
            $resultRedirectUris = [];
            foreach ($redirectUris as $redirectUri) {
                $resultRedirectUris[] = [
                    'id' => $redirectUri->getId(),
                    'client_id' => $redirectUri->getClientId(),
                    'redirect_uri' => $redirectUri->getRedirectUri(),
                ];
            }

            $groups = $this->groupMapper->getGroupsByClientId($client->getId());
            $resultGroups = [];
            foreach ($groups as $group) {
                array_push($resultGroups, $group->getGroupId());
            }
            $flowTypeLabel = $this->l->t(SettingsController::CODE_AUTHORIZATION_FLOW);
            $responseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
            if (in_array('id_token', $responseTypeEntries)) {
                $flowTypeLabel = $this->l->t(SettingsController::CODE_IMPLICIT_AUTHORIZATION_FLOW);
            }

            $result[] = [
                'id' => $client->getId(),
                'name' => $client->getName(),
                'redirectUris' => $resultRedirectUris,
                'clientId' => $client->getClientIdentifier(),
                'clientSecret' => $client->getSecret(),
                'signingAlg' => $client->getSigningAlg(),
                'type' => $client->getType(),
                'flowType' => $client->getFlowType(),
                'flowTypeLabel' => $flowTypeLabel,
                'groups' => $resultGroups,
                'tokenType' => strtolower($client->getTokenType())==='jwt' ? 'jwt' : 'opaque',
                'allowedScopes' => $client->getAllowedScopes(),
                'emailRegex' => $client->getEmailRegex(),
                'resourceUrl' => $client->getResourceUrl(),
                'backchannelLogoutUri' => $client->getBackchannelLogoutUri(),
                'backchannelLogoutSessionRequired' => $client->getBackchannelLogoutSessionRequired(),
            ];
        }
        return new JSONResponse($result);
    }

        public function updateEmailRegex(
        int $id,
        string $emailRegex
        ): JSONResponse
    {
        $emailRegex = trim($emailRegex);
        $emailRegex = mb_substr($emailRegex, 0, 255);

        $this->logger->debug("Updating emailRegex for client " . $id . " with value " .$emailRegex);
        $client = $this->clientMapper->getByUid($id);
        $client->setEmailRegex($emailRegex);
        $this->clientMapper->update($client);
        return new JSONResponse([]);
    }

    public function updateResourceUrl(
        int $id,
        string $resourceUrl
        ): JSONResponse
    {
        $resourceUrl = trim($resourceUrl);

        // Allow empty string to clear the resource URL
        if ($resourceUrl === '') {
            $resourceUrl = null;
        } else {
            // Validate URL format
            if (!filter_var($resourceUrl, FILTER_VALIDATE_URL)) {
                return new JSONResponse([
                    'error' => 'Invalid resource URL format. Must be a valid URL.'
                ], Http::STATUS_BAD_REQUEST);
            }
            // Enforce 512 character limit
            $resourceUrl = mb_substr($resourceUrl, 0, 512);
        }

        $this->logger->debug("Updating resourceUrl for client " . $id . " with value " . ($resourceUrl ?? 'null'));
        $client = $this->clientMapper->getByUid($id);
        $client->setResourceUrl($resourceUrl);
        $this->clientMapper->update($client);
        return new JSONResponse([]);
    }

    public function deleteClient(int $id): JSONResponse
    {
        $client = $this->clientMapper->getByUid($id);
        $this->accessTokenMapper->deleteByClientId($id);
        $this->redirectUriMapper->deleteByClientId($id);
        $this->groupMapper->deleteByClientId($id);
        $this->clientMapper->delete($client);
        return new JSONResponse([]);
    }

    public function addRedirectUri(
                    int $id,
                    string $redirectUri
                    ): JSONResponse
    {
        $this->logger->debug("Adding Redirect URI " . $redirectUri . " for client " . $id);
        try {
            if ($this->redirectUriService->isValidRedirectUri($redirectUri, $this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS, Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS) === 'true') === false) {
                return new JSONResponse(['message' => $this->l->t('Your redirect URL needs to be a full URL for example: https://yourdomain.com/path')], Http::STATUS_BAD_REQUEST);
            }
        } catch (RedirectUriValidationException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $redirectUriObj = new RedirectUri();
        $redirectUriObj->setClientId($id);
        $redirectUriObj->setRedirectUri(trim($redirectUri));
        $redirectUriObj = $this->redirectUriMapper->insert($redirectUriObj);
        $clients = $this->clientMapper->getClients();

        $result = [];

        foreach ($clients as $client) {
            $redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
            $resultRedirectUris = [];
            foreach ($redirectUris as $redirectUri) {
                $resultRedirectUris[] = [
                    'id' => $redirectUri->getId(),
                    'client_id' => $redirectUri->getClientId(),
                    'redirect_uri' => $redirectUri->getRedirectUri(),
                ];
            }

            $groups = $this->groupMapper->getGroupsByClientId($client->getId());
            $resultGroups = [];
            foreach ($groups as $group) {
                array_push($resultGroups, $group->getGroupId());
            }
            $flowTypeLabel = $this->l->t(SettingsController::CODE_AUTHORIZATION_FLOW);
            $responseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
            if (in_array('id_token', $responseTypeEntries)) {
                $flowTypeLabel = $this->l->t(SettingsController::CODE_IMPLICIT_AUTHORIZATION_FLOW);
            }

            $result[] = [
                'id' => $client->getId(),
                'name' => $client->getName(),
                'redirectUris' => $resultRedirectUris,
                'clientId' => $client->getClientIdentifier(),
                'clientSecret' => $client->getSecret(),
                'signingAlg' => $client->getSigningAlg(),
                'type' => $client->getType(),
                'flowType' => $client->getFlowType(),
                'flowTypeLabel' => $flowTypeLabel,
                'groups' => $resultGroups,
                'tokenType' => strtolower($client->getTokenType())==='jwt' ? 'jwt' : 'opaque',
                'allowedScopes' => $client->getAllowedScopes(),
                'emailRegex' => $client->getEmailRegex(),
                'resourceUrl' => $client->getResourceUrl(),
                'backchannelLogoutUri' => $client->getBackchannelLogoutUri(),
                'backchannelLogoutSessionRequired' => $client->getBackchannelLogoutSessionRequired(),
            ];
        }
        return new JSONResponse($result);
    }

    public function deleteRedirectUri(
                    int $id
                    ): JSONResponse
    {
        $this->logger->debug("Deleting Redirect URI with id " . $id);

        $this->redirectUriMapper->deleteOneById($id);

        $clients = $this->clientMapper->getClients();
        $result = [];

        foreach ($clients as $client) {
            $redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
            $resultRedirectUris = [];
            foreach ($redirectUris as $redirectUri) {
                $resultRedirectUris[] = [
                    'id' => $redirectUri->getId(),
                    'client_id' => $redirectUri->getClientId(),
                    'redirect_uri' => $redirectUri->getRedirectUri(),
                ];
            }

            $groups = $this->groupMapper->getGroupsByClientId($client->getId());
            $resultGroups = [];
            foreach ($groups as $group) {
                array_push($resultGroups, $group->getGroupId());
            }
            $flowTypeLabel = $this->l->t(SettingsController::CODE_AUTHORIZATION_FLOW);
            $responseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
            if (in_array('id_token', $responseTypeEntries)) {
                $flowTypeLabel = $this->l->t(SettingsController::CODE_IMPLICIT_AUTHORIZATION_FLOW);
            }

            $result[] = [
                'id' => $client->getId(),
                'name' => $client->getName(),
                'redirectUris' => $resultRedirectUris,
                'clientId' => $client->getClientIdentifier(),
                'clientSecret' => $client->getSecret(),
                'signingAlg' => $client->getSigningAlg(),
                'type' => $client->getType(),
                'flowType' => $client->getFlowType(),
                'flowTypeLabel' => $flowTypeLabel,
                'groups' => $resultGroups,
                'tokenType' => strtolower($client->getTokenType())==='jwt' ? 'jwt' : 'opaque',
                'allowedScopes' => $client->getAllowedScopes(),
                'emailRegex' => $client->getEmailRegex(),
                'resourceUrl' => $client->getResourceUrl(),
                'backchannelLogoutUri' => $client->getBackchannelLogoutUri(),
                'backchannelLogoutSessionRequired' => $client->getBackchannelLogoutSessionRequired(),
            ];
        }
        return new JSONResponse($result);
    }

    public function addLogoutRedirectUri(
                    string $redirectUri
                    ): JSONResponse
    {
        $this->logger->debug("Adding Logout Redirect URI " . $redirectUri);

        $logoutRedirectUriObj = new LogoutRedirectUri();
        $logoutRedirectUriObj->setRedirectUri(trim($redirectUri));
        $logoutRedirectUriObj = $this->logoutRedirectUriMapper->insert($logoutRedirectUriObj);

        $logoutRedirectUrisResult = [];
        $logoutRedirectUris = $this->logoutRedirectUriMapper->getAll();
        foreach ($logoutRedirectUris as $logoutRedirectUri) {
            $logoutRedirectUrisResult[] = [
                'id' => $logoutRedirectUri->getId(),
                'redirectUri' => $logoutRedirectUri->getRedirectUri(),
            ];
        }
        return new JSONResponse($logoutRedirectUrisResult);
    }

    public function deleteLogoutRedirectUri(
                    int $id
                    ): JSONResponse
    {
        $this->logger->debug("Deleting Logout Redirect URI with id " . $id);

        $this->logoutRedirectUriMapper->deleteOneById($id);

        $result = [];
        $logoutRedirectUris = $this->logoutRedirectUriMapper->getAll();
        foreach ($logoutRedirectUris as $logoutRedirectUri) {
            $result[] = [
                'id' => $logoutRedirectUri->getId(),
                'redirectUri' => $logoutRedirectUri->getRedirectUri(),
            ];
        }
        return new JSONResponse($result);
    }

    public function setTokenExpireTime(
                    string $expireTime
                    ): JSONResponse
    {
        $options = array(
            'options' => array(
                'default' => 900,
                'min_range' => 60,
                'max_range' => 3600,
            ),
            'flags' => FILTER_FLAG_ALLOW_OCTAL,
        );
        $finalExpireTime = filter_var($expireTime, FILTER_VALIDATE_INT, $options);
        $finalExpireTime = strval($finalExpireTime);
        $this->appConfig->setAppValuestring('expire_time', $finalExpireTime);
        $result = [
            'expire_time' => $expireTime,
        ];
        return new JSONResponse($result);
    }

    public function setRefreshTokenExpireTime(string $refreshExpireTime): JSONResponse {
        if ($refreshExpireTime === 'never') {
            $this->appConfig->setAppValueString('refresh_expire_time', 'never');
            return new JSONResponse([
                'refresh_expire_time' => $refreshExpireTime,
            ]);
        }

        $options = [
            'options' => [
                'default' => 900,
                'min_range' => 60,
                'max_range' => 604800,
            ],
            'flags' => FILTER_FLAG_ALLOW_OCTAL,
        ];
        $finalExpireTime = filter_var($refreshExpireTime, FILTER_VALIDATE_INT, $options);
        $finalExpireTime = strval($finalExpireTime);
        $this->appConfig->setAppValueString('refresh_expire_time', $finalExpireTime);
        $result = [
            'refresh_expire_time' => $refreshExpireTime,
        ];
        return new JSONResponse($result);
    }

    public function setOverwriteEmailVerified(
                    string $overwriteEmailVerified
                    ): JSONResponse
    {
        if ($overwriteEmailVerified === 'true' || $overwriteEmailVerified === 'false') {
            $this->appConfig->setAppValueString(Application::APP_CONFIG_OVERWRITE_EMAIL_VERIFIED, $overwriteEmailVerified);
        }
        $result = [
            'overwrite_email_verified' => $this->appConfig->getAppValueString(Application::APP_CONFIG_OVERWRITE_EMAIL_VERIFIED),
        ];
        return new JSONResponse($result);
    }

    public function setDynamicClientRegistration(
                    string $dynamicClientRegistration
                    ): JSONResponse
    {
        if ($dynamicClientRegistration === 'true' || $dynamicClientRegistration === 'false') {
            $this->appConfig->setAppValueString(Application::APP_CONFIG_DYNAMIC_CLIENT_REGISTRATION, $dynamicClientRegistration);
        }
        $result = [
            'dynamic_client_registration' => $this->appConfig->getAppValueString(Application::APP_CONFIG_DYNAMIC_CLIENT_REGISTRATION),
        ];
        return new JSONResponse($result);
    }

    public function setDefaultTokenType(
                    string $defaultTokenType
                    ): JSONResponse
    {
        $this->logger->debug("Setting default token type to " . $defaultTokenType);
        $normalizedTokenType = ($defaultTokenType === 'jwt') ? 'jwt' : 'opaque';
        $this->appConfig->setAppValueString(Application::APP_CONFIG_DEFAULT_TOKEN_TYPE, $normalizedTokenType);
        $result = [
            'default_token_type' => $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_TOKEN_TYPE, Application::DEFAULT_TOKEN_TYPE),
        ];
        return new JSONResponse($result);
    }

    public function setAllowUserSettings(
                    string $allowUserSettings
                    ): JSONResponse
    {
        if ($allowUserSettings === 'enabled' || $allowUserSettings === 'no') {
            $this->appConfig->setAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, $allowUserSettings);
        }
        $result = [
            'allow_user_settings' => $this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, Application::DEFAULT_ALLOW_USER_SETTINGS),
        ];
        return new JSONResponse($result);
    }

    public function setProvideRefreshTokenAlways(
                    string $provideRefreshTokenAlways
                    ): JSONResponse
    {
        $this->logger->debug("Setting provide_refresh_token_always to " . $provideRefreshTokenAlways);
        $normalizedValue = ($provideRefreshTokenAlways === 'true') ? 'true' : 'false';
        $this->appConfig->setAppValueString(Application::APP_CONFIG_PROVIDE_REFRESH_TOKEN_ALWAYS, $normalizedValue);
        $result = [
            'provide_refresh_token_always' => $this->appConfig->getAppValueString(Application::APP_CONFIG_PROVIDE_REFRESH_TOKEN_ALWAYS, Application::DEFAULT_PROVIDE_REFRESH_TOKEN_ALWAYS),
        ];
        return new JSONResponse($result);
    }

    public function setAlwaysIncludeScopeClaims(
                    string $alwaysIncludeScopeClaims
                    ): JSONResponse
    {
        $this->logger->debug("Setting always_include_scope_claims to " . $alwaysIncludeScopeClaims);
        $normalizedValue = ($alwaysIncludeScopeClaims === 'true') ? true : false;
        $this->appConfig->setAppValueBool(Application::APP_CONFIG_ALWAYS_INCLUDE_SCOPE_CLAIMS, $normalizedValue);
        $stringValue = $this->appConfig->getAppValueBool(Application::APP_CONFIG_ALWAYS_INCLUDE_SCOPE_CLAIMS, Application::DEFAULT_ALWAYS_INCLUDE_SCOPE_CLAIMS) ? 'true' : 'false';
        $result = [
            'always_include_scope_claims' => $stringValue,
        ];
        return new JSONResponse($result);
    }

    public function restrictUserInformation(
                    string $restrictUserInformation
                    ): JSONResponse
    {
        $resultRestrictUserInformation = '';
        $restrictUserInformationArr = explode(' ', strtolower(trim($restrictUserInformation)));
        $allowedValuesArr = ['avatar', 'address', 'phone', 'website'];
        foreach ($restrictUserInformationArr as $entry) {
            if (in_array($entry, $allowedValuesArr)) {
                $resultRestrictUserInformation = $resultRestrictUserInformation . $entry . ' ';
            }
        }
        $resultRestrictUserInformation = trim($resultRestrictUserInformation);
        if ($resultRestrictUserInformation === '') {
            $resultRestrictUserInformation = Application::DEFAULT_RESTRICT_USER_INFORMATION;
        }
        $this->appConfig->setAppValueString(Application::APP_CONFIG_RESTRICT_USER_INFORMATION, $resultRestrictUserInformation);
        $result = [
            'restrict_user_information' => $this->appConfig->getAppValueString(Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION),
        ];
        return new JSONResponse($result);
    }

    #[NoAdminRequired]
    public function restrictUserInformationPersonal(
                    string $restrictUserInformation
                    ): JSONResponse
    {
        $currentUser = $this->userSession->getUser();
        $userId = $currentUser->getUID();

        $resultRestrictUserInformation = '';
        $restrictUserInformationArr = explode(' ', strtolower(trim($restrictUserInformation)));
        $allowedValuesArr = ['avatar', 'address', 'phone', 'website'];
        foreach ($restrictUserInformationArr as $entry) {
            if (in_array($entry, $allowedValuesArr)) {
                $resultRestrictUserInformation = $resultRestrictUserInformation . $entry . ' ';
            }
        }
        $resultRestrictUserInformation = trim($resultRestrictUserInformation);
        if ($resultRestrictUserInformation === '') {
            $resultRestrictUserInformation = Application::DEFAULT_RESTRICT_USER_INFORMATION;
        }
        $this->userConfig->setValueString($userId, Application::APP_ID, Application::APP_CONFIG_RESTRICT_USER_INFORMATION, $resultRestrictUserInformation);
        $result = [
            'restrict_user_information' =>  $this->userConfig->getValueString($userId, Application::APP_ID, Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION),
        ];
        return new JSONResponse($result);
    }

    public function regenerateKeys(): JSONResponse
    {
        $this->credentialService->generateKeys();
        $result = [
            'public_key' => $this->appConfig->getAppValueString('public_key'),
        ];
        return new JSONResponse($result);
    }
    /**
     * RFC 8693 resource values are absolute RFC 3986 URIs and MUST NOT contain
     * a fragment. Query components are allowed.
     */
    private function isValidTokenExchangeResourceUri(string $resource): bool
    {
        if ($resource === '' || preg_match('/[\x00-\x20]/', $resource) === 1) {
            return false;
        }

        $parts = parse_url($resource);
        if ($parts === false || !isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', (string)$parts['scheme']) === 1;
    }

}
