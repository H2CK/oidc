<?php

declare(strict_types=1);

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
use OCP\Security\ISecureRandom;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller
{
    /** @var ClientMapper */
    private $clientMapper;
    /** @var ISecureRandom */
    private $secureRandom;
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

    public const VALID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ClientMapper $clientMapper,
                    ISecureRandom $secureRandom,
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
        $this->secureRandom = $secureRandom;
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
        if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
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
        $flowTypeLabel = $this->l->t('Code Authorization Flow');
        $responseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
        if (in_array('id_token', $responseTypeEntries)) {
            $flowTypeLabel = $this->l->t('Code & Implicit Authorization Flow');
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
            $result[] = [
                'id' => $client->getId(),
                'name' => $client->getName(),
                'redirectUris' => $resultRedirectUris,
                'clientId' => $client->getClientIdentifier(),
                'clientSecret' => $client->getSecret(),
                'signingAlg' => $client->getSigningAlg(),
                'type' => $client->getType(),
                'flowType' => $client->getFlowType(),
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
            $result[] = [
                'id' => $client->getId(),
                'name' => $client->getName(),
                'redirectUris' => $resultRedirectUris,
                'clientId' => $client->getClientIdentifier(),
                'clientSecret' => $client->getSecret(),
                'signingAlg' => $client->getSigningAlg(),
                'type' => $client->getType(),
                'flowType' => $client->getFlowType(),
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

        $result = [];
        $logoutRedirectUris = $this->logoutRedirectUriMapper->getAll();
        foreach ($logoutRedirectUris as $logoutRedirectUri) {
            $resultLogoutRedirectUris = array(
                'id' => $logoutRedirectUri->getId(),
                'redirectUri' => $logoutRedirectUri->getRedirectUri(),
            );
            array_push($result, $resultLogoutRedirectUris);
        }
        return new JSONResponse($result);
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
        $this->appConfig->setAppValue('expire_time', $finalExpireTime);
        $result = [
            'expire_time' => $expireTime,
        ];
        return new JSONResponse($result);
    }

    public function setRefreshTokenExpireTime(string $refreshExpireTime): JSONResponse {
        if ($refreshExpireTime === 'never') {
            $this->appConfig->setAppValue('refresh_expire_time', 'never');
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
        $this->appConfig->setAppValue('refresh_expire_time', $finalExpireTime);
        $result = [
            'refresh_expire_time' => $refreshExpireTime,
        ];
        return new JSONResponse($result);
    }

    public function setIntegrateAvatar(
                    string $integrateAvatar
                    ): JSONResponse
    {
        if ($integrateAvatar === 'none' || $integrateAvatar === 'user_info' || $integrateAvatar === 'id_token') {
            $this->appConfig->setAppValue('integrate_avatar', $integrateAvatar);
        }
        $result = [
            'integrate_avatar' => $this->appConfig->getAppValue('integrate_avatar'),
        ];
        return new JSONResponse($result);
    }

    public function setOverwriteEmailVerified(
                    string $overwriteEmailVerified
                    ): JSONResponse
    {
        if ($overwriteEmailVerified === 'true' || $overwriteEmailVerified === 'false') {
            $this->appConfig->setAppValue('overwrite_email_verified', $overwriteEmailVerified);
        }
        $result = [
            'overwrite_email_verified' => $this->appConfig->getAppValue('overwrite_email_verified'),
        ];
        return new JSONResponse($result);
    }

    public function setDynamicClientRegistration(
                    string $dynamicClientRegistration
                    ): JSONResponse
    {
        if ($dynamicClientRegistration === 'true' || $dynamicClientRegistration === 'false') {
            $this->appConfig->setAppValue('dynamic_client_registration', $dynamicClientRegistration);
        }
        $result = [
            'dynamic_client_registration' => $this->appConfig->getAppValue('dynamic_client_registration'),
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

        $this->appConfig->setAppValue('private_key', $privateKey);
        $this->appConfig->setAppValue('public_key', $publicKey);
        $uuid = $this->guidv4();
        $this->appConfig->setAppValue('kid', $uuid);
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
        $this->appConfig->setAppValue('public_key_n', $modulus);
        $this->appConfig->setAppValue('public_key_e', $exponent);
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
