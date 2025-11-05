<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Util;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OC\Authentication\Token\IProvider as TokenProvider;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\DAV\CardDAV\Converter;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Server;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountManager;
use Psr\Log\LoggerInterface;

class JwtGenerator
{
    /** @var ICrypto */
    private $crypto;
    /** @var TokenProvider */
    private $tokenProvider;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var ITimeFactory */
    private $time;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var IAccountManager */
    private $accountManager;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IAppConfig */
    private $appConfig;
    /** @var IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var Converter */
    private $converter;

    public const SUB_OUTPUT = ' sub=> ';
    public const AUD_OUTPUT = ' aud=> ';
    public const CLIENT_ID_OUTPUT = ' client_id=> ';

    public function __construct(
                    ICrypto $crypto,
                    TokenProvider $tokenProvider,
                    ISecureRandom $secureRandom,
                    ITimeFactory $time,
                    IUserManager $userManager,
                    IGroupManager $groupManager,
                    IAccountManager $accountManager,
                    IURLGenerator $urlGenerator,
                    IAppConfig $appConfig,
                    IConfig $config,
                    LoggerInterface $logger
    ) {
        $this->crypto = $crypto;
        $this->tokenProvider = $tokenProvider;
        $this->secureRandom = $secureRandom;
        $this->time = $time;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->accountManager = $accountManager;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
        $this->config = $config;
        $this->logger = $logger;
        $this->converter = Server::get(Converter::class);
    }

    /**
     * Generate JWT ID Token
     *
     * @param AccessToken $accessToken
     * @param Client $client
     * @param string $issuerProtocol
     * @param string $issuerHost
     * @param bool $atHash
     * @return string
     * @throws PropertyDoesNotExistException
     */
    public function generateIdToken(AccessToken $accessToken, Client $client, string $issuerProtocol, string $issuerHost, bool $atHash): string {
        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $issuer = $issuerProtocol . '://' . $issuerHost . $this->urlGenerator->getWebroot();
        $nonce = $accessToken->getNonce();
        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);
        $account = $this->accountManager->getAccount($user);
        $quota = $user->getQuota();

        $jwt_payload = [
            'iss' => $issuer,
            'sub' => $uid,
            'aud' => $client->getClientIdentifier(),
            'exp' => $this->time->getTime() + $expireTime,
            'auth_time' => $accessToken->getCreated(),
            'iat' => $this->time->getTime(),
            'acr' => '0',
            'azp' => $client->getClientIdentifier(),
            'preferred_username' => $uid,
            'scope' => $accessToken->getScope(),
            'nbf' => $this->time->getTime(),
            'jti' => strval($accessToken->getId()),
        ];

        if ($atHash) {
            $atHashData = str_replace(
                ['+', '/', '='],
                ['-', '_', ''],
                base64_encode(substr(hash('sha256', $accessToken->getAccessToken()), 0, 128))
            );
            $athashPayload = [
                'at_hash' => $atHashData
            ];
            $jwt_payload = array_merge($jwt_payload, $athashPayload);
        }

        if (!empty($nonce)) {
            $nonce_payload = [
                'nonce' => $nonce
            ];
            $jwt_payload = array_merge($jwt_payload, $nonce_payload);
        }

        $roles = [];
        $groupClaimType = $this->appConfig->getAppValueString(Application::APP_CONFIG_GROUP_CLAIM_TYPE, Application::GROUP_CLAIM_TYPE_GID);
        foreach ($groups as $group) {
            if ($groupClaimType === Application::GROUP_CLAIM_TYPE_DISPLAYNAME) {
                $displayName = $group->getDisplayName();
                if ($displayName !== null && $displayName !== '') {
                    array_push($roles, $displayName);
                } else {
                    array_push($roles, $group->getGID());
                }
            } else {
                array_push($roles, $group->getGID());
            }
        }

        // Check for scopes
        // OpenID Connect requests MUST contain the openid scope value. - This implementation does not enforce that openid is specified.
        // OPTIONAL scope values of profile, email, address, phone, and offline_access are also defined.
        $scopeArray = preg_split('/ +/', $accessToken->getScope());
        if (in_array("roles", $scopeArray)) {
            $roles_payload = [
                'roles' => $roles
            ];
            $jwt_payload = array_merge($jwt_payload, $roles_payload);
        }
        if (in_array("groups", $scopeArray)) {
            $roles_payload = [
                'groups' => $roles
            ];
            $jwt_payload = array_merge($jwt_payload, $roles_payload);
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

            // Possible further values currently not provided by Nextcloud
            // 'nickname' => ,
            // 'profile' => ,
            // 'picture' => , usually contains a URL linking to picture for download
            // 'gender' => ,
            // 'birthdate' => ,
            // 'zoneinfo' => ,
            // 'locale' => ,
            if ($quota != 'none') {
                $profile = array_merge($profile,
                        ['quota' => $quota]);
            }
            $jwt_payload = array_merge($jwt_payload, $profile);
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
            $jwt_payload = array_merge($jwt_payload, $email);
        }

        $payload = json_encode($jwt_payload);
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $base64UrlHeader = '';
        $base64UrlSignature = '';

        $signing_alg = $client->getSigningAlg(); // HS256 or RS256
        if ($signing_alg === 'HS256') {
            $header = json_encode(['typ' => 'JWT', 'alg' => $signing_alg]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $client->getSecret(), true);
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        } else {
            $kid = $this->appConfig->getAppValueString('kid');
            $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $kid]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->appConfig->getAppValueString('private_key'), 'sha256WithRSAEncryption');
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        }

        $jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
        $this->logger->debug('Generated JWT with iss => ' . $issuer . JwtGenerator::SUB_OUTPUT . $uid . ' aud/azp => ' . $client->getClientIdentifier() . ' preferred_username => ' . $uid);
        return $jwt;
    }


    /**
     * Generate JWT Access Token (RFC9068) if client is configured to use JWT access tokens otherwise opaque access token
     * is returned. The passed accessToken object is not modified.
     *
     * @param AccessToken $accessToken
     * @param Client $client
     * @param string $issuerProtocol
     * @param string $issuerHost
     * @param bool $atHash
     * @return string
     * @throws PropertyDoesNotExistException
     * @throws JwtCreationErrorException
     */
    public function generateAccessToken(AccessToken $accessToken, Client $client, string $issuerProtocol, string $issuerHost): string {
        if (strtolower($client->getTokenType())!=='jwt') {
            $this->logger->debug('Generated opaque access token for client ' . $client->getClientIdentifier());
            return $this->secureRandom->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        }

        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $issuer = $issuerProtocol . '://' . $issuerHost . $this->urlGenerator->getWebroot();
        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);
        $aud = $accessToken->getResource();
        if (!isset($aud) || trim($aud)==='') {
			$aud = $client->getClientIdentifier();
        }

        $jwt_payload = [
            'iss' => $issuer,
            'sub' => $uid,
            'aud' => $aud,
            'exp' => $this->time->getTime() + $expireTime,
            'auth_time' => $accessToken->getCreated(),
            'iat' => $this->time->getTime(),
            'acr' => '0',
            'client_id' => $client->getClientIdentifier(),
            'scope' => $accessToken->getScope(),
            'jti' => strval($accessToken->getId()),
        ];

        $roles = [];
        // Fetch roles
        foreach ($groups as $group) {
            array_push($roles, $group->getGID());
        }

        // Check for scopes roles, groups and entitlements (not supported)
        $scopeArray = preg_split('/ +/', $accessToken->getScope());
        if (in_array("roles", $scopeArray)) {
            $roles_payload = [
                'roles' => $roles
            ];
            $jwt_payload = array_merge($jwt_payload, $roles_payload);
        }
        if (in_array("groups", $scopeArray)) {
            $roles_payload = [
                'groups' => $roles
            ];
            $jwt_payload = array_merge($jwt_payload, $roles_payload);
        }

        $payload = json_encode($jwt_payload);
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $base64UrlHeader = '';
        $base64UrlSignature = '';

        $signing_alg = $client->getSigningAlg(); // HS256 or RS256
        if ($signing_alg === 'HS256') {
            $header = json_encode(['typ' => 'at+JWT', 'alg' => $signing_alg]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $client->getSecret(), true);
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        } else {
            $kid = $this->appConfig->getAppValueString('kid');
            $header = json_encode(['typ' => 'at+JWT', 'alg' => 'RS256', 'kid' => $kid]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->appConfig->getAppValueString('private_key'), 'sha256WithRSAEncryption');
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        }

        $jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";

        // Check length - should not exceed 65535 - DB Limit - not expected to be reached with a JWT access token
        if (strlen($jwt) > 65535) {
            $this->logger->error('Too big JWT Access token with iss => ' . $issuer . JwtGenerator::SUB_OUTPUT . $uid . JwtGenerator::AUD_OUTPUT . $aud . JwtGenerator::CLIENT_ID_OUTPUT . $client->getClientIdentifier());
            throw new JwtCreationErrorException('Created JWT exceeds limits of 65535 characters. JWT can not be stored in database.', 0, null);
        }

        $this->logger->debug('Generated JWT Access token with iss => ' . $issuer . JwtGenerator::SUB_OUTPUT . $uid . JwtGenerator::AUD_OUTPUT . $aud . JwtGenerator::CLIENT_ID_OUTPUT . $client->getClientIdentifier());
        return $jwt;
    }
}
