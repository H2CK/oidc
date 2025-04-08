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
    /** @var LoggerInterface */
    private $logger;
    /** @var Converter */
    private $converter;

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
        $expireTime = (int)$this->appConfig->getAppValue('expire_time', Application::DEFAULT_EXPIRE_TIME);
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
        // Add roles
        foreach ($groups as $group) {
            array_push($roles, $group->getGID());
        }

        // Check for scopes
        // OpenID Connect requests MUST contain the openid scope value. - This implementation does not enforce that openid is specified.
        // OPTIONAL scope values of profile, email, address, phone, and offline_access are also defined. See Section 2.4 for more about the scope values defined by this document.
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
            if ($this->appConfig->getAppValue('integrate_avatar') == 'id_token') {
                $avatarImage = $user->getAvatarImage(64);
                if ($avatarImage !== null) {
                    $profile = array_merge($profile,
                            ['picture' => 'data:' . $avatarImage->dataMimeType() . ';base64,' . base64_encode($avatarImage->data())]);
                }
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
            // Only primary email address is used. Additional emails are not considered.
            $mail_property = $account->getProperty(\OCP\Accounts\IAccountManager::PROPERTY_EMAIL);

            $email = [
                'email' => $mail_property->getValue(),
            ];
            if ($this->appConfig->getAppValue('overwrite_email_verified') == 'true') {
                $email = array_merge($email, ['email_verified' => true]);
            } else {
                if ($mail_property->getVerified() === \OCP\Accounts\IAccountManager::VERIFIED) {
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
            $kid = $this->appConfig->getAppValue('kid');
            $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $kid]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->appConfig->getAppValue('private_key'), 'sha256WithRSAEncryption');
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        }

        $jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
        $this->logger->debug('Generated JWT with iss => ' . $issuer . ' sub => ' . $uid . ' aud/azp => ' . $client->getClientIdentifier() . ' preferred_username => ' . $uid);
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
        if ($client->getTokenType()!=='jwt') {
            $this->logger->debug('Generated opaque access token for client ' . $client->getClientIdentifier());
            return $this->secureRandom->generate(72, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        }

        $expireTime = (int)$this->appConfig->getAppValue('expire_time', Application::DEFAULT_EXPIRE_TIME);
        $issuer = $issuerProtocol . '://' . $issuerHost . $this->urlGenerator->getWebroot();
        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);

        $jwt_payload = [
            'iss' => $issuer,
            'sub' => $uid,
            'aud' => $accessToken->getResource(),
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
            $kid = $this->appConfig->getAppValue('kid');
            $header = json_encode(['typ' => 'at+JWT', 'alg' => 'RS256', 'kid' => $kid]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->appConfig->getAppValue('private_key'), 'sha256WithRSAEncryption');
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        }

        $jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";

        // Check length - should not exceed 65535 - DB Limit - not expected to be reached with a JWT access token
        if (strlen($jwt) > 65535) {
            $this->logger->error('Too big JWT Access token with iss => ' . $issuer . ' sub => ' . $uid . ' aud => ' . $accessToken->getResource() . ' client_id => ' . $client->getClientIdentifier());
            throw new JwtCreationErrorException('Created JWT exceeds limits of 65535 characters. JWT can not be stored in database.', 0, null);
        }

        $this->logger->debug('Generated JWT Access token with iss => ' . $issuer . ' sub => ' . $uid . ' aud => ' . $accessToken->getResource() . ' client_id => ' . $client->getClientIdentifier());
        return $jwt;
    }
}
