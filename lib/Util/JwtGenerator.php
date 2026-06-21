<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Util;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OC\Authentication\Token\IProvider as TokenProvider;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCA\OIDCIdentityProvider\Service\CredentialService;
use OCA\DAV\CardDAV\Converter;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Server;
use OCP\IURLGenerator;
use OCP\Config\IUserConfig;
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
    /** @var IUserConfig */
    private $userConfig;
    /** @var IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var Converter */
    private $converter;
    /** @var CustomClaimService */
    private $customClaimService;
    /** @var CredentialService */
    private $credentialService;

    public const SUB_OUTPUT = ' sub=> ';
    public const AUD_OUTPUT = ' aud=> ';
    public const CLIENT_ID_OUTPUT = ' client_id=> ';
    private const PROFILE_CLAIMS = [
        'updated_at',
        'preferred_username',
        'name',
        'family_name',
        'given_name',
        'middle_name',
        'website',
        'phone_number',
        'address',
        'picture',
        'quota',
    ];
    private const EMAIL_CLAIMS = [
        'email',
        'email_verified',
    ];

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
                    IUserConfig $userConfig,
                    IConfig $config,
                    CustomClaimService $customClaimService,
                    CredentialService $credentialService,
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
        $this->userConfig = $userConfig;
        $this->config = $config;
        $this->customClaimService = $customClaimService;
        $this->credentialService = $credentialService;
        $this->logger = $logger;
        $this->converter = Server::get(Converter::class);
    }

    /**
     * @return array<string, null|array<string, mixed>>
     */
    private function getRequestedClaimRequests(?string $encodedClaims): array
    {
        if ($encodedClaims === null || trim($encodedClaims) === '') {
            return [];
        }

        $decodedClaims = json_decode($encodedClaims, true);
        if (!is_array($decodedClaims)) {
            return [];
        }

        if (array_is_list($decodedClaims)) {
            $claimRequests = [];
            foreach ($decodedClaims as $claimName) {
                if (is_string($claimName) && $claimName !== '') {
                    $claimRequests[$claimName] = null;
                }
            }

            return $claimRequests;
        }

        $claimRequests = [];
        foreach ($decodedClaims as $claimName => $claimRequest) {
            if (is_string($claimName) && $claimName !== '' && ($claimRequest === null || is_array($claimRequest))) {
                $claimRequests[$claimName] = $claimRequest;
            }
        }

        return $claimRequests;
    }

    /**
     * @param array<string, null|array<string, mixed>> $claimRequests
     */
    private function shouldIncludeRequestedClaim(string $claimName, mixed $claimValue, array $claimRequests): bool
    {
        return array_key_exists($claimName, $claimRequests) && $this->claimMatchesRequest($claimName, $claimValue, $claimRequests);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, null|array<string, mixed>> $claimRequests
     * @return array<string, mixed>
     */
    private function filterClaims(array $claims, array $claimRequests, bool $includeScopeClaims): array
    {
        if ($includeScopeClaims) {
            return $claims;
        }

        $filteredClaims = [];
        foreach ($claims as $claimName => $claimValue) {
            if (array_key_exists($claimName, $claimRequests) && $this->claimMatchesRequest($claimName, $claimValue, $claimRequests)) {
                $filteredClaims[$claimName] = $claimValue;
            }
        }

        return $filteredClaims;
    }

    /**
     * @param string[] $claimNames
     * @param array<string, null|array<string, mixed>> $claimRequests
     */
    private function hasRequestedClaim(array $claimNames, array $claimRequests): bool
    {
        return count(array_intersect($claimNames, array_keys($claimRequests))) > 0;
    }

    /**
     * @param array<string, null|array<string, mixed>> $claimRequests
     */
    private function claimMatchesRequest(string $claimName, mixed $claimValue, array $claimRequests): bool
    {
        if (!array_key_exists($claimName, $claimRequests)) {
            return false;
        }

        $claimRequest = $claimRequests[$claimName];
        if (!is_array($claimRequest)) {
            return true;
        }

        if (array_key_exists('value', $claimRequest) && $claimValue !== $claimRequest['value']) {
            return false;
        }

        if (array_key_exists('values', $claimRequest) && is_array($claimRequest['values']) && !in_array($claimValue, $claimRequest['values'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Generate JWT ID Token
     *
     * @param AccessToken $accessToken
     * @param Client $client
     * @param string $issuerProtocol
     * @param string $issuerHost
     * @param bool $atHash
     * @param bool $includeScopeClaims
     * @param string|null $authorizationCode
     * @return string
     * @throws PropertyDoesNotExistException
     */
    public function generateIdToken(AccessToken $accessToken, Client $client, string $issuerProtocol, string $issuerHost, bool $atHash, bool $includeScopeClaims = true, ?string $authorizationCode = null): string {
        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $issuer = $issuerProtocol . '://' . $issuerHost . $this->urlGenerator->getWebroot();
        $nonce = $accessToken->getNonce();
        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);
        $account = $this->accountManager->getAccount($user);
        $quota = $user->getQuota();
        $requestedIdTokenClaims = $this->getRequestedClaimRequests($accessToken->getIdTokenClaims());

        $jwt_payload = $this->filterClaims(
            $this->customClaimService->provideCustomClaims($client->getId(), $accessToken->getScope(), $uid),
            $requestedIdTokenClaims,
            $includeScopeClaims
        );

        $jwt_payload_base = [
            'iss' => $issuer,
            'sub' => $uid,
            'aud' => $client->getClientIdentifier(),
            'exp' => $this->time->getTime() + $expireTime,
            'auth_time' => $accessToken->getCreated(),
            'iat' => $this->time->getTime(),
            'acr' => '0',
            'azp' => $client->getClientIdentifier(),
            'nbf' => $this->time->getTime(),
            'jti' => strval($accessToken->getId()),
        ];

        if ($this->shouldIncludeRequestedClaim('scope', $accessToken->getScope(), $requestedIdTokenClaims)) {
            $jwt_payload_base['scope'] = $accessToken->getScope();
        }

        $jwt_payload = array_merge($jwt_payload, $jwt_payload_base);

        if ($atHash) {
            $athashPayload = [
                'at_hash' => $this->generateIdTokenHash($accessToken->getAccessToken(), $client->getSigningAlg())
            ];
            $jwt_payload = array_merge($jwt_payload, $athashPayload);
        }

        if ($authorizationCode !== null && trim($authorizationCode) !== '') {
            $cHashPayload = [
                'c_hash' => $this->generateIdTokenHash($authorizationCode, $client->getSigningAlg())
            ];
            $jwt_payload = array_merge($jwt_payload, $cHashPayload);
        }

        if (!empty($nonce)) {
            $nonce_payload = [
                'nonce' => $nonce
            ];
            $jwt_payload = array_merge($jwt_payload, $nonce_payload);
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

        // Check for scopes
        // OpenID Connect requests MUST contain the openid scope value. - This implementation does not enforce that openid is specified.
        // OPTIONAL scope values of profile, email, address, phone, and offline_access are also defined.
        $scopeArray = preg_split('/ +/', $accessToken->getScope()) ?: [];
        $includeRolesByScope = $includeScopeClaims && in_array("roles", $scopeArray, true);
        $includeRolesByClaim = array_key_exists('roles', $requestedIdTokenClaims);
        if ($includeRolesByScope || $includeRolesByClaim) {
            if ($rolesClaimType === Application::GROUP_CLAIM_TYPE_DISPLAYNAME) {
                $roles_payload = [
                    'roles' => $rolesDisplayName
                ];
            } else {
                $roles_payload = [
                    'roles' => $roles
                ];
            }
            $jwt_payload = array_merge($jwt_payload, $this->filterClaims($roles_payload, $requestedIdTokenClaims, $includeRolesByScope));
        }
        $includeGroupsByScope = $includeScopeClaims && in_array("groups", $scopeArray, true);
        $includeGroupsByClaim = array_key_exists('groups', $requestedIdTokenClaims);
        if ($includeGroupsByScope || $includeGroupsByClaim) {
            if ($groupClaimType === Application::GROUP_CLAIM_TYPE_DISPLAYNAME) {
                $roles_payload = [
                    'groups' => $rolesDisplayName
                ];
            } else {
                $roles_payload = [
                    'groups' => $roles
                ];
            }
            $jwt_payload = array_merge($jwt_payload, $this->filterClaims($roles_payload, $requestedIdTokenClaims, $includeGroupsByScope));
        }

        $restrictUserInformationArr = explode(' ', strtolower(trim($this->appConfig->getAppValueString(Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION))));
        $restrictUserInformationPersonalArr = [ Application::DEFAULT_ALLOW_USER_SETTINGS ];
        if ($this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, Application::DEFAULT_ALLOW_USER_SETTINGS) != Application::DEFAULT_ALLOW_USER_SETTINGS) {
            $restrictUserInformationPersonalArr = explode(' ', strtolower(trim($this->userConfig->getValueString($uid, Application::APP_ID, Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION))));
        }

        $includeProfileByScope = $includeScopeClaims && in_array("profile", $scopeArray, true);
        $includeProfileByClaim = $this->hasRequestedClaim(self::PROFILE_CLAIMS, $requestedIdTokenClaims);
        if ($includeProfileByScope || $includeProfileByClaim) {
            $profile = [
                'updated_at' => $user->getLastLogin(),
                'preferred_username' => $uid,
            ];
            if ($account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME)->getValue() != '') {
                $displayName = $account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME)->getValue();
                $names = $this->converter->splitFullName($displayName);
                $profile = array_merge($profile, [
                    'name' => $displayName,
                ]);
                foreach (['family_name', 'given_name', 'middle_name'] as $index => $claimName) {
                    if (isset($names[$index]) && trim($names[$index]) !== '') {
                        $profile[$claimName] = $names[$index];
                    }
                }
            } else {
                $profile = array_merge($profile, ['name' => $user->getDisplayName()]);
            }
            if ($account->getProperty(IAccountManager::PROPERTY_WEBSITE)->getValue() != '' && !in_array('website', $restrictUserInformationArr) && !in_array('website', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['website' => $account->getProperty(IAccountManager::PROPERTY_WEBSITE)->getValue()]);
            }
            if ($account->getProperty(IAccountManager::PROPERTY_PHONE)->getValue() != '' && !in_array('phone', $restrictUserInformationArr) && !in_array('phone', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['phone_number' => $account->getProperty(IAccountManager::PROPERTY_PHONE)->getValue()]);
            }
            if ($account->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue() != '' && !in_array('address', $restrictUserInformationArr) && !in_array('address', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['address' =>
                                [ 'formatted' => $account->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue()]]);
            }
            if (!in_array('avatar', $restrictUserInformationArr) && !in_array('avatar', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['picture' => $issuer . '/avatar/' . rawurlencode($uid) . '/64']);
            }

            // Possible further values currently not provided by Nextcloud
            // 'nickname' => ,
            // 'profile' => ,
            // 'gender' => ,
            // 'birthdate' => ,
            // 'zoneinfo' => ,
            // 'locale' => ,
            if ($quota != 'none') {
                $profile = array_merge($profile, ['quota' => $quota]);
            }
            $jwt_payload = array_merge($jwt_payload, $this->filterClaims($profile, $requestedIdTokenClaims, $includeProfileByScope));
        }

        $includeEmailByScope = $includeScopeClaims && in_array("email", $scopeArray, true);
        $includeEmailByClaim = $this->hasRequestedClaim(self::EMAIL_CLAIMS, $requestedIdTokenClaims);
        if (($includeEmailByScope || $includeEmailByClaim) && $user->getEMailAddress() !== null) {
            $emailProperty = $account->getProperty(IAccountManager::PROPERTY_EMAIL);
            $clientEmailRegex = $client->getEmailRegex();
            if ($clientEmailRegex !== '') {
                $this->logger->debug('Found regex for email: ' . $clientEmailRegex);
                $emailCollection = $account->getPropertyCollection(IAccountManager::COLLECTION_EMAIL);
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
                if ($emailProperty->getVerified() === IAccountManager::VERIFIED) {
                    $email = array_merge($email, ['email_verified' => true]);
                } else {
                    $email = array_merge($email, ['email_verified' => false]);
                }
            }
            $jwt_payload = array_merge($jwt_payload, $this->filterClaims($email, $requestedIdTokenClaims, $includeEmailByScope));
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
            openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->credentialService->getPrivateKey(), 'sha256WithRSAEncryption');
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        }

        $jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
        $this->logger->debug('Generated JWT with iss => ' . $issuer . JwtGenerator::SUB_OUTPUT . $uid . ' aud/azp => ' . $client->getClientIdentifier());
        return $jwt;
    }

    private function generateIdTokenHash(string $value, string $signingAlg): string
    {
        $hashAlgorithm = match (substr(strtoupper($signingAlg), -3)) {
            '384' => 'sha384',
            '512' => 'sha512',
            default => 'sha256',
        };

        $hash = hash($hashAlgorithm, $value, true);
        return $this->base64UrlEncode(substr($hash, 0, intdiv(strlen($hash), 2)));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }


    /**
     * Generate JWT Access Token (RFC9068) if client is configured to use JWT access tokens otherwise opaque access token
     * is returned. The passed accessToken object is not modified.
     *
     * @param AccessToken $accessToken
     * @param Client $client
     * @param string $issuerProtocol
     * @param string $issuerHost
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
        $account = $this->accountManager->getAccount($user);
        $quota = $user->getQuota();
        $aud = $accessToken->getResource();
        if (!isset($aud) || trim($aud)==='') {
            $aud = $client->getClientIdentifier();
        }

        $jwt_payload = $this->filterClaims(
            $this->customClaimService->provideCustomClaims($client->getId(), $accessToken->getScope(), $uid),
            [],
            true
        );

        $jwt_payload_base = [
            'iss' => $issuer,
            'sub' => $uid,
            'aud' => $aud,
            'exp' => $this->time->getTime() + $expireTime,
            'auth_time' => $accessToken->getCreated(),
            'iat' => $this->time->getTime(),
            'acr' => '0',
            'client_id' => $client->getClientIdentifier(),
            'azp' => $client->getClientIdentifier(),
            'preferred_username' => $uid,
            'scope' => $accessToken->getScope(),
            'jti' => strval($accessToken->getId()),
        ];

        $jwt_payload = array_merge($jwt_payload, $jwt_payload_base);

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

        // Check for scopes roles, groups and entitlements (not supported)
        $scopeArray = preg_split('/ +/', $accessToken->getScope());
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
            $jwt_payload = array_merge($jwt_payload, $roles_payload);
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
            $jwt_payload = array_merge($jwt_payload, $roles_payload);
        }

        $restrictUserInformationArr = explode(' ', strtolower(trim($this->appConfig->getAppValueString(Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION))));
        $restrictUserInformationPersonalArr = [ Application::DEFAULT_ALLOW_USER_SETTINGS ];
        if ($this->appConfig->getAppValueString(Application::APP_CONFIG_ALLOW_USER_SETTINGS, Application::DEFAULT_ALLOW_USER_SETTINGS) != Application::DEFAULT_ALLOW_USER_SETTINGS) {
            $restrictUserInformationPersonalArr = explode(' ', strtolower(trim($this->userConfig->getValueString($uid, Application::APP_ID, Application::APP_CONFIG_RESTRICT_USER_INFORMATION, Application::DEFAULT_RESTRICT_USER_INFORMATION))));
        }

        if (in_array("profile", $scopeArray)) {
            $profile = [
                'updated_at' => $user->getLastLogin(),
            ];
            if ($account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME)->getValue() != '') {
                $displayName = $account->getProperty(IAccountManager::PROPERTY_DISPLAYNAME)->getValue();
                $names = $this->converter->splitFullName($displayName);
                $profile = array_merge($profile, [
                    'name' => $displayName,
                ]);
                foreach (['family_name', 'given_name', 'middle_name'] as $index => $claimName) {
                    if (isset($names[$index]) && trim($names[$index]) !== '') {
                        $profile[$claimName] = $names[$index];
                    }
                }
            } else {
                $profile = array_merge($profile, ['name' => $user->getDisplayName()]);
            }
            if ($account->getProperty(IAccountManager::PROPERTY_WEBSITE)->getValue() != '' && !in_array('website', $restrictUserInformationArr) && !in_array('website', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['website' => $account->getProperty(IAccountManager::PROPERTY_WEBSITE)->getValue()]);
            }
            if ($account->getProperty(IAccountManager::PROPERTY_PHONE)->getValue() != '' && !in_array('phone', $restrictUserInformationArr) && !in_array('phone', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['phone_number' => $account->getProperty(IAccountManager::PROPERTY_PHONE)->getValue()]);
            }
            if ($account->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue() != '' && !in_array('address', $restrictUserInformationArr) && !in_array('address', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['address' =>
                                [ 'formatted' => $account->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue()]]);
            }
            if (!in_array('avatar', $restrictUserInformationArr) && !in_array('avatar', $restrictUserInformationPersonalArr)) {
                $profile = array_merge($profile,
                        ['picture' => $issuer . '/avatar/' . rawurlencode($uid) . '/64']);
            }
            if ($quota != 'none') {
                $profile = array_merge($profile, ['quota' => $quota]);
            }
            $jwt_payload = array_merge($jwt_payload, $profile);
        }

        if (in_array("email", $scopeArray) && $user->getEMailAddress() !== null) {
            $emailProperty = $account->getProperty(IAccountManager::PROPERTY_EMAIL);
            $clientEmailRegex = $client->getEmailRegex();
            if ($clientEmailRegex !== '') {
                $this->logger->debug('Found regex for email: ' . $clientEmailRegex);
                $emailCollection = $account->getPropertyCollection(IAccountManager::COLLECTION_EMAIL);
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
                if ($emailProperty->getVerified() === IAccountManager::VERIFIED) {
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
            $header = json_encode(['typ' => 'at+JWT', 'alg' => $signing_alg]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $client->getSecret(), true);
            $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        } else {
            $kid = $this->appConfig->getAppValueString('kid');
            $header = json_encode(['typ' => 'at+JWT', 'alg' => 'RS256', 'kid' => $kid]);
            $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            openssl_sign("$base64UrlHeader.$base64UrlPayload", $signature, $this->credentialService->getPrivateKey(), 'sha256WithRSAEncryption');
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
