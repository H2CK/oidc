<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
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
    /** @var IL10N */
    private $l;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

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
                    IGroupManager $groupManager,
                    IL10N $l,
                    IAppConfig $appConfig,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->clientMapper = $clientMapper;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->logoutRedirectUriMapper = $logoutRedirectUriMapper;
        $this->groupMapper = $groupMapper;
        $this->groupManager = $groupManager;
        $this->l = $l;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    public function addClient(
                    string $name,
                    string $redirectUri,
                    string $signingAlg,
                    string $type,
                    string $flowType,
                    string $tokenType
                    ): JSONResponse
    {
        $this->logger->debug("Adding client " . $name);

        if (filter_var($redirectUri, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/.*:\/\/.*/"))) === false) {
            return new JSONResponse(['message' => $this->l->t('Your redirect URL needs to be a full URL for example: https://yourdomain.com/path')], Http::STATUS_BAD_REQUEST);
        }

        $client = new Client(
            $name,
            [ $redirectUri ],
            $signingAlg,
            $type,
            $flowType,
            $tokenType
        );

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
            'tokenType' => $client->getTokenType()==='jwt' ? 'jwt' : 'opaque',
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

        if (filter_var($redirectUri, FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/.*:\/\/.*/"))) === false) {
            return new JSONResponse(['message' => $this->l->t('Your redirect URL needs to be a full URL for example: https://yourdomain.com/path')], Http::STATUS_BAD_REQUEST);
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
                'tokenType' => $client->getTokenType()==='jwt' ? 'jwt' : 'opaque',
            'tokenType' => $client->getTokenType()==='jwt' ? 'jwt' : 'opaque',
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
                'tokenType' => $client->getTokenType()==='jwt' ? 'jwt' : 'opaque',
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
            $this->appConfig->setAppValueString('overwrite_email_verified', $overwriteEmailVerified);
        }
        $result = [
            'overwrite_email_verified' => $this->appConfig->getAppValueString('overwrite_email_verified'),
        ];
        return new JSONResponse($result);
    }

    public function setDynamicClientRegistration(
                    string $dynamicClientRegistration
                    ): JSONResponse
    {
        if ($dynamicClientRegistration === 'true' || $dynamicClientRegistration === 'false') {
            $this->appConfig->setAppValueString('dynamic_client_registration', $dynamicClientRegistration);
        }
        $result = [
            'dynamic_client_registration' => $this->appConfig->getAppValueString('dynamic_client_registration'),
        ];
        return new JSONResponse($result);
    }

    public function regenerateKeys(): JSONResponse
    {
        $config = array(
            "digest_alg" => 'sha512',
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA
        );
        $keyPair = openssl_pkey_new($config);
        $privateKey = null;
        openssl_pkey_export($keyPair, $privateKey);
        $keyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $keyDetails['key'];

        $this->appConfig->setAppValueString('private_key', $privateKey);
        $this->appConfig->setAppValueString('public_key', $publicKey);
        $uuid = $this->guidv4();
        $this->appConfig->setAppValueString('kid', $uuid);
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
        $this->appConfig->setAppValueString('public_key_n', $modulus);
        $this->appConfig->setAppValueString('public_key_e', $exponent);
        $result = [
            'public_key' => $publicKey,
        ];
        return new JSONResponse($result);
    }

    private function guidv4($data = null)
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
