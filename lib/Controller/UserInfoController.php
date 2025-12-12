<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OC\Security\Bruteforce\Throttler;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\DAV\CardDAV\Converter;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Server;
use OCP\IConfig;
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
    /** @var IConfig */
    private $config;
    /** @var CustomClaimService */
    private $customClaimService;
    /** @var LoggerInterface */
    private $logger;
    /** @var Converter */
    private $converter;
    /** @var IURLGenerator */
    private $urlGenerator;

    public function __construct(
                    string $appName,
                    IRequest $request,
                    IURLGenerator $urlGenerator,
                    AccessTokenMapper $accessTokenMapper,
                    ClientMapper $clientMapper,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IUserManager $userManager,
                    IGroupManager $groupManager,
                    IAccountManager $accountManager,
                    IAppConfig $appConfig,
                    IConfig $config,
                    CustomClaimService $customClaimService,
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
        $this->config = $config;
        $this->customClaimService = $customClaimService;
        $this->logger = $logger;
        $this->converter = Server::get(Converter::class);
        $this->urlGenerator = $urlGenerator;
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
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            $this->logger->warning('Client expired. Client id was ' . $client->getId() . '.');
            return new JSONResponse([
                'error' => 'expired_client',
                'error_description' => 'Client expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // The accessToken must not be expired
        if ($this->time->getTime() > $accessToken->getRefreshed() + $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME) ) {
            $this->accessTokenMapper->delete($accessToken);
            $this->logger->notice('Access token already expired.');
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Access token already expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $issuer =  $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . $this->urlGenerator->getWebroot();
        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);
        $account = $this->accountManager->getAccount($user);
        $quota = $user->getQuota();

        $userInfoPayload = $this->customClaimService->provideCustomClaims($client->getId(), $accessToken->getScope(), $uid);

        $userInfoPayloadBase = [
            'sub' => $uid,
            'preferred_username' => $uid,

        ];

        $userInfoPayload = array_merge($userInfoPayload, $userInfoPayloadBase);

        // Check for scopes
        $scopeArray = preg_split('/ +/', $accessToken->getScope());

        // Add scope field to userinfo response (RFC 8693 & OpenID Connect Core 1.0 Section 5.3.2)
        // This allows resource servers to validate token scopes without introspection
        if ($accessToken->getScope() !== null && $accessToken->getScope() !== '') {
            $userInfoPayload['scope'] = $accessToken->getScope();
        }

        $roles = [];
        $rolesDisplayName = [];
        foreach ($groups as $group) {
            array_push($roles, $group->getGID());
            $displayName = $group->getDisplayName();
            if ($displayName !== null && $displayName !== '') {
                array_push($rolesDisplayName, $displayName);
            } else {
                array_push($rolesDisplayName, $group->getGID());
            }
        }

        $groupClaimType = $this->appConfig->getAppValueString(Application::APP_CONFIG_GROUP_CLAIM_TYPE, Application::GROUP_CLAIM_TYPE_GID);
        $rolesClaimType = $this->appConfig->getAppValueString(Application::APP_CONFIG_ROLES_CLAIM_TYPE, 'null');
        if ($rolesClaimType !== null && $rolesClaimType === 'null') {
            $rolesClaimType = $groupClaimType;
        }

        if (in_array("roles", $scopeArray)) {
            if ($rolesClaimType === Application::GROUP_CLAIM_TYPE_DISPLAYNAME) {
                $roles_payload = [
                    'roles' => $rolesDisplayName
                ];
            } else {
                $roles_payload = [
                    'roles' => $roles
                ];
            }
            $userInfoPayload = array_merge($userInfoPayload, $roles_payload);
        }
        if (in_array("groups", $scopeArray)) {
            if ($groupClaimType === Application::GROUP_CLAIM_TYPE_DISPLAYNAME) {
                $roles_payload = [
                    'groups' => $rolesDisplayName
                ];
            } else {
                $roles_payload = [
                    'groups' => $roles
                ];
            }
            $userInfoPayload = array_merge($userInfoPayload, $roles_payload);
        }

        $restrictUserInformationArr = explode(' ', strtolower(trim($this->appConfig->getAppValueString(Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION))));
        $restrictUserInformationPersonalArr = [ Application::DEFAULT_ALLOW_USER_SETTINGS ];
        if ($this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, Application::DEFAULT_ALLOW_USER_SETTINGS) != Application::DEFAULT_ALLOW_USER_SETTINGS) {
            $restrictUserInformationPersonalArr = explode(' ', strtolower(trim($this->config->getUserValue($uid, Application::APP_ID, Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION))));
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
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_WEBSITE)->getValue() != '' && !in_array('website', $restrictUserInformationArr) && !in_array('website', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['website' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_WEBSITE)->getValue()]);
            }
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_PHONE)->getValue() != '' && !in_array('phone', $restrictUserInformationArr) && !in_array('phone', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['phone_number' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_PHONE)->getValue()]);
            }
            if ($account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_ADDRESS)->getValue() != '' && !in_array('address', $restrictUserInformationArr) && !in_array('address', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['address' =>
                                [ 'formatted' => $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_ADDRESS)->getValue()]]);
            }
            if (!in_array('avatar', $restrictUserInformationArr) && !in_array('avatar', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['picture' => $issuer . '/avatar/' . $uid . '/64']);
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
            $emailProperty = $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_EMAIL);
            $clientEmailRegex = $client->getEmailRegex();
            if ($clientEmailRegex !== '') {
                $this->logger->debug('Found regex for email: ' . $clientEmailRegex);
                $emailCollection = $account->getPropertyCollection(\OCP\Accounts\IAccountManager::COLLECTION_EMAIL);
                foreach ($emailCollection->getProperties() as $emailPropertyEntry) {
                    $this->logger->debug('Performing check for mail ' . $emailPropertyEntry->getValue());
                    if (preg_match('/'.$clientEmailRegex.'/', $emailPropertyEntry->getValue())) {
                        $this->logger->debug('Regex matches');
                        $emailProperty = $emailPropertyEntry;
                    }
                }
            }

            $email = [
                'email' => $emailProperty->getValue(),
            ];
            if ($this->appConfig->getAppValueString(Application::APP_CONFIG_OVERWRITE_EMAIL_VERIFIED) == 'true') {
                $email = array_merge($email, ['email_verified' => true]);
            } else {
                if ($emailProperty->getVerified() === \OCP\Accounts\IAccountManager::VERIFIED) {
                    $email = array_merge($email, ['email_verified' => true]);
                } else {
                    $email = array_merge($email, ['email_verified' => false]);
                }
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
