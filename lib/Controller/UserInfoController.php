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

use OC\Security\Bruteforce\Throttler;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\DAV\CardDAV\Converter;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Server;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use Psr\Log\LoggerInterface;

class UserInfoController extends ApiController
{
    /** @var AccessTokenMapper */
    private $accessTokenMapper;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var ITimeFactory */
    private $time;
    /** @var Throttler */
    private $throttler;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var IAccountManager */
    private $accountManager;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;
    /** @var Converter */
    private $converter;

    public function __construct(
                    string $appName,
                    IRequest $request,
                    AccessTokenMapper $accessTokenMapper,
                    ClientMapper $clientMapper,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IUserManager $userManager,
                    IGroupManager $groupManager,
                    IAccountManager $accountManager,
                    IAppConfig $appConfig,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->accessTokenMapper = $accessTokenMapper;
        $this->clientMapper = $clientMapper;
        $this->time = $time;
        $this->throttler = $throttler;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->accountManager = $accountManager;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
        $this->converter = Server::get(Converter::class);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_userinfo)
     *
     * @return JSONResponse
     */
    #[BruteForceProtection(action: 'oidc_userinfo')]
    #[PublicPage]
    #[NoCSRFRequired]
    public function getInfoPost(): JSONResponse
    {
        return $this->getInfo();
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_userinfo)
     *
     * @return JSONResponse
     */
    #[BruteForceProtection(action: 'oidc_userinfo')]
    #[PublicPage]
    #[NoCSRFRequired]
    public function getInfo(): JSONResponse
    {

        $accessTokenCode = $this->getBearerToken();
        if ($accessTokenCode == null) {
            $this->logger->notice('No bearer token found in request.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'No bearer token found in request.'
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $accessToken = $this->accessTokenMapper->getByAccessToken($accessTokenCode);
        } catch (AccessTokenNotFoundException $e) {
            $this->logger->notice('Could not find provided bearer token.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Could not find provided bearer token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $client = $this->clientMapper->getByUid($accessToken->getClientId());
        } catch (ClientNotFoundException $e) {
            $this->logger->error('Could not find client for access token.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Could not find client for access token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // The client must not be expired
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + $this->appConfig->getAppValue('client_expire_time', Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            $this->logger->warning('Client expired. Client id was ' . $client->getId() . '.');
            return new JSONResponse([
                'error' => 'expired_client',
                'error_description' => 'Client expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // The accessToken must not be expired
        if ($this->time->getTime() > $accessToken->getRefreshed() + $this->appConfig->getAppValue('expire_time', Application::DEFAULT_EXPIRE_TIME) ) {
            $this->accessTokenMapper->delete($accessToken);
            $this->logger->notice('Access token already expired.');
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Access token already expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);
        $account = $this->accountManager->getAccount($user);
        $quota = $user->getQuota();

        $userInfoPayload = [
            'sub' => $uid,
            'preferred_username' => $uid,

        ];

        // Check for scopes
        $scopeArray = preg_split('/ +/', $accessToken->getScope());

        $roles = [];
        foreach ($groups as $group) {
            array_push($roles, $group->getGID());
        }

        if (in_array("roles", $scopeArray)) {
            $rolesPayload = [
                'roles' => $roles
            ];
            $userInfoPayload = array_merge($userInfoPayload, $rolesPayload);
        }

        if (in_array("groups", $scopeArray)) {
            $groupsPayload = [
                'groups' => $roles
            ];
            $userInfoPayload = array_merge($userInfoPayload, $groupsPayload);
        }

        if (in_array("profile", $scopeArray)) {
            $profile = [
                'updated_at' => $user->getLastLogin(),
            ];
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_DISPLAYNAME)->getValue() != '') {
                $displayName = $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_DISPLAYNAME)->getValue();
                $names = $this->converter->splitFullName($displayName);
                $profile = array_merge($profile, [
                    'name' => $displayName,
                    'family_name' => $names[0],
                    'given_name' => $names[1],
                    'middle_name' => $names[2]
                ]);
            } else {
                $profile = array_merge($profile, ['name' => $user->getDisplayName()]);
            }
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_WEBSITE)->getValue() != '') {
                $profile = array_merge($profile,
                        ['website' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_WEBSITE)->getValue()]);
            }
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_PHONE)->getValue() != '') {
                $profile = array_merge($profile,
                        ['phone_number' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_PHONE)->getValue()]);
            }
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_ADDRESS)->getValue() != '') {
                $profile = array_merge($profile,
                        ['address' =>
                                [ 'formatted' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_ADDRESS)->getValue()]]);
            }
            if ($this->appConfig->getAppValue('integrate_avatar') == 'user_info' || $this->appConfig->getAppValue('integrate_avatar') == 'id_token') {
                $avatarImage = $user->getAvatarImage(64);
                if ($avatarImage !== null) {
                    $profile = array_merge($profile,
                            ['picture' => 'data:' . $avatarImage->dataMimeType() . ';base64,' . base64_encode($avatarImage->data())]);
                }
            }
            // Possible further values
            // 'nickname' => ,
            // 'profile' => ,
            // 'picture' => ,
            // 'gender' => ,
            // 'birthdate' => ,
            // 'zoneinfo' => ,
            // 'locale' => ,
            if ($quota != 'none') {
                $profile = array_merge($profile,
                        ['quota' => $quota]);
            }
            $userInfoPayload = array_merge($userInfoPayload, $profile);
        }
        if (in_array("email", $scopeArray) && $user->getEMailAddress() !== null) {
            $email = [
                'email' => $user->getEMailAddress(),
            ];
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_EMAIL)->getVerified()) {
                $email = array_merge($email, ['email_verified' => true]);
            } else {
                $email = array_merge($email, ['email_verified' => false]);
            }
            $userInfoPayload = array_merge($userInfoPayload, $email);
        }
        $this->logger->debug('Returned user info for user ' . $uid);
        $response = new JSONResponse($userInfoPayload);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');

        return $response;
    }

    /**
     * Get header Authorization
     */
    private function getAuthorizationHeader()
    {
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions
            // (a nice side-effect of this fix means we don't care
            // about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    /**
     * get access token from header
     */
    private function getBearerToken()
    {
        $headers = $this->getAuthorizationHeader();
        // HEADER: Get the access token from the header
        if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
        }
        return null;
    }
}
