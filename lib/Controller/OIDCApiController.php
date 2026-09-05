<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OC\Authentication\Exceptions\ExpiredTokenException;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider as TokenProvider;
use OC\Security\Bruteforce\Throttler;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AuthorizationCode;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCode;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\TexSubjectClientMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\DB\Exception as DatabaseException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountManager;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use Psr\Log\LoggerInterface;

class OIDCApiController extends ApiController {
    private const DEVICE_CODE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';
    private const TOKEN_EXCHANGE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';
    private const TOKEN_TYPE_ACCESS_TOKEN = 'urn:ietf:params:oauth:token-type:access_token';
    /** @var AccessTokenMapper */
    private $accessTokenMapper;
    /** @var AuthorizationCodeMapper */
    private $authorizationCodeMapper;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var GroupMapper */
    private $groupMapper;
    /** @var UserConsentMapper */
    private $userConsentMapper;
    /** @var TexTargetMapper */
    private $texTargetMapper;
    /** @var TexSubjectClientMapper */
    private $texSubjectClientMapper;
    /** @var ICrypto */
    private $crypto;
    /** @var TokenProvider */
    private $tokenProvider;
    /** @var ISecureRandom */
    private $secureRandom;
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
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IAppConfig */
    private $appConfig;
    /** @var JwtGenerator */
    private $jwtGenerator;
    /** @var LoggerInterface */
    private $logger;
    /** @var FormUrlencodedParameterParser */
    private $formUrlencodedParameterParser;
    /** @var DeviceCodeMapper */
    private $deviceCodeMapper;

    /**
     * @param string $appName
     * @param IRequest $request
     * @param ICrypto $crypto
     * @param AccessTokenMapper $accessTokenMapper
     * @param AuthorizationCodeMapper $authorizationCodeMapper
     * @param ClientMapper $clientMapper
     * @param GroupMapper $groupMapper
     * @param UserConsentMapper $userConsentMapper
     * @param TokenProvider $tokenProvider
     * @param ISecureRandom $secureRandom
     * @param ITimeFactory $time
     * @param Throttler $throttler
     * @param IUserManager $userManager
     * @param IGroupManager $groupManager
     * @param IAccountManager $accountManager
     * @param IURLGenerator $urlGenerator
     * @param IAppConfig $appConfig
     * @param JwtGenerator $jwtGenerator
     * @param LoggerInterface $logger
     * @param TexTargetMapper $texTargetMapper
     * @param FormUrlencodedParameterParser|null $formUrlencodedParameterParser
     * @param TexSubjectClientMapper|null $texSubjectClientMapper
     * @param DeviceCodeMapper $deviceCodeMapper
     */
    public function __construct(
                    string $appName,
                    IRequest $request,
                    ICrypto $crypto,
                    AccessTokenMapper $accessTokenMapper,
                    AuthorizationCodeMapper $authorizationCodeMapper,
                    ClientMapper $clientMapper,
                    GroupMapper $groupMapper,
                    UserConsentMapper $userConsentMapper,
                    TokenProvider $tokenProvider,
                    ISecureRandom $secureRandom,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IUserManager $userManager,
                    IGroupManager $groupManager,
                    IAccountManager $accountManager,
                    IURLGenerator $urlGenerator,
                    IAppConfig $appConfig,
                    JwtGenerator $jwtGenerator,
                    LoggerInterface $logger,
                    DeviceCodeMapper $deviceCodeMapper,
                    ?TexTargetMapper $texTargetMapper = null,
                    ?FormUrlencodedParameterParser $formUrlencodedParameterParser = null,
                    ?TexSubjectClientMapper $texSubjectClientMapper = null
                    )
    {
        parent::__construct($appName, $request);
        $this->crypto = $crypto;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->authorizationCodeMapper = $authorizationCodeMapper;
        $this->clientMapper = $clientMapper;
        $this->groupMapper = $groupMapper;
        $this->userConsentMapper = $userConsentMapper;
        $this->tokenProvider = $tokenProvider;
        $this->secureRandom = $secureRandom;
        $this->time = $time;
        $this->throttler = $throttler;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->accountManager = $accountManager;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
        $this->jwtGenerator = $jwtGenerator;
        $this->logger = $logger;
        $this->texTargetMapper = $texTargetMapper;
        $this->formUrlencodedParameterParser = $formUrlencodedParameterParser ?? new FormUrlencodedParameterParser();
        $this->texSubjectClientMapper = $texSubjectClientMapper;
        $this->deviceCodeMapper = $deviceCodeMapper;
    }

    /**
     * Check whether the original request body uses the encoding required by the
     * OAuth token endpoint. Parameters that may occur more than once according
     * to RFC 8693 must be inspected in the raw form body because IRequest's
     * parsed parameter map cannot reliably preserve repeated names.
     */
    private function isFormUrlencodedRequest(): bool
    {
        $contentType = trim($this->request->getHeader('Content-Type'));
        if ($contentType === '') {
            return false;
        }

        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        return $mediaType === 'application/x-www-form-urlencoded';
    }

    private function hasBasicAuthorizationHeader(): bool
    {
        return stripos(trim($this->request->getHeader('Authorization')), 'Basic ') === 0;
    }

    private function invalidClientResponse(string $description, bool $basicAuthenticationAttempted = false): JSONResponse
    {
        $response = new JSONResponse([
            'error' => 'invalid_client',
            'error_description' => $description,
        ], $basicAuthenticationAttempted ? Http::STATUS_UNAUTHORIZED : Http::STATUS_BAD_REQUEST);

        if ($basicAuthenticationAttempted) {
            $response->addHeader('WWW-Authenticate', 'Basic realm="token"');
        }

        return $response;
    }

    private function getBasicClientCredentials(): ?array
    {
        $authorization = $this->request->getHeader('Authorization');

        if ($authorization === '') {
            return null;
        }

        if (stripos($authorization, 'Basic ') !== 0) {
            return null;
        }

        $encoded = trim(substr($authorization, 6));

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return null;
        }

        $separator = strpos($decoded, ':');
        if ($separator === false) {
            return null;
        }

        $clientId = substr($decoded, 0, $separator);
        $clientSecret = substr($decoded, $separator + 1);

        /*
        * RFC 6749 section 2.3.1:
        * client_id and client_secret use application/x-www-form-urlencoded
        * encoding before being put into Basic auth.
        */
        return [
            urldecode($clientId),
            urldecode($clientSecret),
        ];
    }

    /**
     * OAuth 2.0 token-endpoint parameters sent without a value are treated as
     * omitted (RFC 6749 section 3.2). Only an exact decoded empty string is
     * omitted; whitespace and every other non-empty value remain present.
     *
     * @param array<string,list<string>> $parameters
     * @return array<string,list<string>>
     */
    private function omitEmptyFormParameterValues(array $parameters): array
    {
        foreach ($parameters as $name => $values) {
            $parameters[$name] = array_values(array_filter(
                $values,
                static fn (string $value): bool => $value !== ''
            ));
        }

        return $parameters;
    }

    private function rollBackTokenExchangeTransactionSafely(): void {
        try {
            $this->accessTokenMapper->rollBackTokenExchangeTransaction();
        } catch (\Throwable $rollbackError) {
            $this->logger->error('Failed to roll back Token Exchange transaction.', [
                'exception' => $rollbackError,
            ]);
        }
    }

    private function invalidGrantResponse(string $description): JSONResponse {
        return new JSONResponse([
            'error' => 'invalid_grant',
            'error_description' => $description,
        ], Http::STATUS_BAD_REQUEST);
    }

    private function revokeAccessTokenForReusedAuthorizationCode(
        AuthorizationCode $authorizationCode,
        string|null $clientId
    ): JSONResponse {
        try {
            $accessToken = $this->accessTokenMapper->getById($authorizationCode->getAccessTokenId());
            $this->accessTokenMapper->delete($accessToken);
            $this->logger->warning('Revoked access token after authorization code reuse. Client id was ' . $clientId . '.');
        } catch (AccessTokenNotFoundException $e) {
            $this->logger->warning('Authorization code reuse detected, but linked access token was already gone. Client id was ' . $clientId . '.');
        }

        return $this->invalidGrantResponse('Authorization code has already been used.');
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_token)
     * @NoTwoFactorRequired
     *
     * @param string $grant_type
     * @param string|null $code
     * @param string|null $refresh_token
     * @param string|null $client_id
     * @param string|null $client_secret
     * @param string|null $code_verifier
     * @param string|null $device_code
     * @return JSONResponse
     */
    // #[NoTwoFactorRequired] currently not working with NC below 34, so we use the annotation instead
    #[BruteForceProtection(action: 'oidc_token')]
    #[PublicPage]
    #[NoCSRFRequired]
    public function getToken(
        $grant_type,
        string|null $code = null,
        string|null $refresh_token = null,
        string|null $client_id = null,
        string|null $client_secret = null,
        string|null $code_verifier = null,
        string|null $device_code = null): JSONResponse
    {
        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, '0');
        $refreshExpireTime = $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME);
        // Handle token exchange (RFC 8693). If Nextcloud/PHP collapsed a repeated
        // grant_type to a non-token-exchange value, inspect the raw form body before
        // dispatch so a mixed/repeated grant_type cannot bypass Token Exchange
        // duplicate-parameter validation.
        if ($grant_type !== self::TOKEN_EXCHANGE_GRANT_TYPE && $this->isFormUrlencodedRequest()) {
            $rawGrantTypes = $this->formUrlencodedParameterParser->readSelectedParameters(['grant_type']);
            if ($rawGrantTypes !== null) {
                $rawGrantTypes = $this->omitEmptyFormParameterValues($rawGrantTypes);
            }
            if ($rawGrantTypes !== null && in_array(self::TOKEN_EXCHANGE_GRANT_TYPE, $rawGrantTypes['grant_type'], true)) {
                if (count($rawGrantTypes['grant_type']) !== 1) {
                    return new JSONResponse([
                        'error' => 'invalid_request',
                        'error_description' => 'Parameter grant_type must occur exactly once.',
                    ], Http::STATUS_BAD_REQUEST);
                }

                return $this->handleTokenExchange($client_id, $client_secret);
            }
            if ($rawGrantTypes !== null && in_array(self::DEVICE_CODE_GRANT_TYPE, $rawGrantTypes['grant_type'], true)) {
                if (count($rawGrantTypes['grant_type']) !== 1) {
                    return $this->deviceGrantError('invalid_request', 'Parameter grant_type must occur exactly once.');
                }

                return $this->handleDeviceCodeGrant($device_code, $client_id, $client_secret);
            }
        }

        if ($grant_type === self::TOKEN_EXCHANGE_GRANT_TYPE) {
            return $this->handleTokenExchange($client_id, $client_secret);
        }

        if ($grant_type === self::DEVICE_CODE_GRANT_TYPE) {
            return $this->handleDeviceCodeGrant($device_code, $client_id, $client_secret);
        }

        // Handle the authorization-code and refresh-token grants.
        if ($grant_type !== 'authorization_code' && $grant_type !== 'refresh_token') {
            $this->logger->info('Invalid grant_type provided. Must be authorization_code, refresh_token, or token-exchange for client id ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'unsupported_grant_type',
                'error_description' => 'Invalid grant_type provided. Must be authorization_code, refresh_token, or urn:ietf:params:oauth:grant-type:token-exchange.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // We handle the initial and refresh tokens the same way
        if ($grant_type === 'refresh_token') {
            $code = $refresh_token;
        }

        if ($code === null || trim($code) === '') {
            $this->logger->info('Missing code or refresh_token for client id ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Missing code or refresh_token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $authorizationCode = null;
        if ($grant_type === 'authorization_code') {
            $authorizationCode = $this->authorizationCodeMapper->findByCode($code);
            if ($authorizationCode !== null && $authorizationCode->getUsedAt() > 0) {
                return $this->revokeAccessTokenForReusedAuthorizationCode($authorizationCode, $client_id);
            }
        }

        try {
            $accessToken = $this->accessTokenMapper->getByCode($code);
        } catch (AccessTokenNotFoundException $e) {
            if ($authorizationCode !== null) {
                $authorizationCode = $this->authorizationCodeMapper->findByCode($code);
                if ($authorizationCode !== null && $authorizationCode->getUsedAt() > 0) {
                    return $this->revokeAccessTokenForReusedAuthorizationCode($authorizationCode, $client_id);
                }
            }
            $this->logger->info('Could not find access token for code or refresh_token for client id ' . $client_id . '.');
            return $this->invalidGrantResponse('Could not find access token for code or refresh_token.');
        }

        if (!isset($client_id)) {
            $this->logger->debug('No client_id in request. Trying to fetch from Authorization Header.');
            $credentials = $this->getBasicClientCredentials();
            if ($credentials !== null) {
                [$client_id, $client_secret] = $credentials;
            }
        }

        if ($client_id === null || trim($client_id) === '') {
            $this->logger->info('Missing client_id in token request.');
            return $this->invalidClientResponse('Missing client_id.', $this->hasBasicAuthorizationHeader());
        }

        try {
            $client = $this->clientMapper->getByIdentifier($client_id);
        } catch (ClientNotFoundException $e) {
            $this->logger->info('Client not found. Client id was ' . $client_id . '.');
            return $this->invalidClientResponse('Client not found.', $this->hasBasicAuthorizationHeader());
        }
        if ($client === null) {
            $this->logger->info('Client not found. Client id was ' . $client_id . '.');
            return $this->invalidClientResponse('Client not found.', $this->hasBasicAuthorizationHeader());
        }

        if ($client->getType() === 'public') {
            // Only the client id must be present for a public client.
            $this->logger->debug('Authenticated public client. Client id was ' . $client_id . '.');
        } else {
            // The client id and secret must match. Else we don't provide an access token!
            if (!is_string($client_secret) || !hash_equals($client->getSecret(), $client_secret)) {
                $this->logger->error('Client authentication failed. Client id was ' . $client_id . '.');
                return $this->invalidClientResponse('Client authentication failed.', $this->hasBasicAuthorizationHeader());
            }
        }

        if ($accessToken->getClientId() !== $client->getId()) {
            $this->logger->info('Grant is not valid for client id ' . $client_id . '.');
            return $this->invalidGrantResponse('Grant is not valid for this client.');
        }

        if (
            $grant_type === 'authorization_code'
            && $authorizationCode !== null
            && $authorizationCode->getAccessTokenId() !== $accessToken->getId()
        ) {
            $this->logger->warning('Authorization code state does not match access token row for client id ' . $client_id . '.');
            return $this->invalidGrantResponse('Authorization code is not valid.');
        }

        // The client must not be expired
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            $this->logger->warning('Client expired. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'expired_client',
                'error_description' => 'Client expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($grant_type === 'authorization_code') {
            // The accessToken must not be expired
            if ($this->time->getTime() >= $accessToken->getEffectiveExpiresAt($expireTime)) {
                $this->accessTokenMapper->delete($accessToken);
                $this->logger->info('Access token already expired. Client id was ' . $client_id . '.');
                return $this->invalidGrantResponse('Access token already expired.');
            }

            // PKCE verification (RFC 7636 Section 4.6)
            $storedCodeChallenge = $accessToken->getCodeChallenge();
            if (!empty($storedCodeChallenge)) {
                // PKCE was used in authorization request, code_verifier is required
                if (empty($code_verifier)) {
                    $this->accessTokenMapper->delete($accessToken);
                    $this->logger->info('Missing code_verifier for PKCE-protected token. Client id: ' . $client_id);
                    return $this->invalidGrantResponse('code_verifier required for PKCE flow.');
                }

                $storedCodeChallengeMethod = $accessToken->getCodeChallengeMethod() ?: 'S256';
                if (!$this->verifyPkce($code_verifier, $storedCodeChallenge, $storedCodeChallengeMethod)) {
                    $this->accessTokenMapper->delete($accessToken);
                    $this->logger->info('PKCE verification failed. Client id: ' . $client_id);
                    return $this->invalidGrantResponse('Invalid code_verifier.');
                }

                $this->logger->debug('PKCE verification successful for client ' . $client_id);
            }
        } elseif ($refreshExpireTime !== 'never') {
            // The refresh token must not be expired
            $refreshExpireTime = (int)$refreshExpireTime;
            if ($this->time->getTime() >= $accessToken->getRefreshed() + $refreshExpireTime) {
                $this->accessTokenMapper->delete($accessToken);
                $this->logger->info('Refresh token is expired. Client id: ' . $client_id . '.');
                return $this->invalidGrantResponse('Refresh token is expired.');
            }
        }

        $uid = $accessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);
        // No need to read account: $account = $this->accountManager->getAccount($user);

        // Check if user is in allowed groups for client
        $clientGroups = $this->groupMapper->getGroupsByClientId($client->getId());

        $groupFound = false;
        if (count($clientGroups) < 1) { $groupFound = true; }
        foreach ($clientGroups as $clientGroup) {
            foreach ($groups as $userGroup) {
                if ($clientGroup->getGroupId() === $userGroup->getGID()) {
                    $groupFound = true;
                    break;
                }
            }
        }
        if (!$groupFound) {
            $this->accessTokenMapper->delete($accessToken);
            $this->logger->info('Access token used for allowed for user groups. Client id was ' . $client_id . '.');
            return $this->invalidGrantResponse('Access token not allowed for user groups.');
        }

        if ($grant_type === 'refresh_token') {
            $userConsent = $this->userConsentMapper->findByUserAndClient($uid, $client->getId());
            if ($userConsent === null) {
                $this->accessTokenMapper->delete($accessToken);
                $this->logger->info('Consent revoked or missing for refresh token grant. Client id was ' . $client_id . '.');
                return $this->invalidGrantResponse('Consent has been revoked.');
            }
        }

        if ($grant_type === 'authorization_code' && $authorizationCode !== null) {
            if (!$this->authorizationCodeMapper->markUsed($authorizationCode, $this->time->getTime())) {
                return $this->revokeAccessTokenForReusedAuthorizationCode($authorizationCode, $client_id);
            }
        }

        $newCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken->setHashedCode(hash('sha512', $newCode));
        $now = $this->time->getTime();
        $accessToken->setRefreshed($now);
        $accessToken->setExpiresAt($now + $expireTime);

        try {
            $accessToken->setAccessToken($this->jwtGenerator->generateAccessToken($accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost()));
        } catch (JwtCreationErrorException $e) {
            $this->logger->info('An error occured during creation of JWT.');
            return new JSONResponse([
                'error' => 'server_error',
                'error_description' => 'An error occured during creation of JWT.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $this->accessTokenMapper->update($accessToken);

        $jwt = $this->jwtGenerator->generateIdToken($accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost(), false, false);

        $this->logger->info('Returned token for user ' . $uid);

        $responseData = [
            'access_token' => $accessToken->getAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => $expireTime,
            'id_token' => $jwt,
        ];

        // Check if refresh token should be issued (OIDC Core 1.0 Section 11)
        $provideRefreshTokenAlways = $this->appConfig->getAppValueString(
            Application::APP_CONFIG_PROVIDE_REFRESH_TOKEN_ALWAYS,
            Application::DEFAULT_PROVIDE_REFRESH_TOKEN_ALWAYS
        ) === 'true';

        $scopeArray = preg_split('/ +/', trim($accessToken->getScope()));
        $hasOfflineAccess = in_array('offline_access', $scopeArray);

        if ($provideRefreshTokenAlways || $hasOfflineAccess) {
            $responseData['refresh_token'] = $newCode;
            if ($refreshExpireTime !== 'never') {
                $responseData['refresh_expires_in'] = (int)$refreshExpireTime;
            }
            $reason = $provideRefreshTokenAlways ? 'always_provide=true' : 'offline_access granted';
            $this->logger->info('Issued refresh token - User: ' . $uid . ', Client: ' . $client_id . ', Reason: ' . $reason);
        } else {
            $this->logger->info('Denied refresh token - missing offline_access scope - User: ' . $uid . ', Client: ' . $client_id);
        }
        $response = new JSONResponse($responseData);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST');

        return $response;
    }

    /**
     * Complete an RFC 8628 device authorization grant.
     */
    private function handleDeviceCodeGrant(
        ?string $deviceCode,
        ?string $clientId,
        ?string $clientSecret,
    ): JSONResponse {
        if (!$this->isFormUrlencodedRequest()) {
            return $this->deviceGrantError('invalid_request', 'Device token requests must use application/x-www-form-urlencoded.');
        }
        $parameters = $this->formUrlencodedParameterParser->readSelectedParameters([
            'grant_type',
            'device_code',
            'client_id',
            'client_secret',
        ]);
        if ($parameters === null) {
            return $this->deviceGrantError('invalid_request', 'Could not parse the form body.');
        }
        foreach ($parameters as $name => $values) {
            if (count($values) > 1) {
                return $this->deviceGrantError('invalid_request', 'Parameter ' . $name . ' must not occur more than once.');
            }
        }
        if (count($parameters['grant_type']) !== 1 || $parameters['grant_type'][0] !== self::DEVICE_CODE_GRANT_TYPE) {
            return $this->deviceGrantError('invalid_request', 'The device grant_type must occur exactly once.');
        }
        if (count($parameters['device_code']) !== 1) {
            return $this->deviceGrantError('invalid_request', 'Parameter device_code must occur exactly once.');
        }
        if ($deviceCode === null || trim($deviceCode) === '') {
            return $this->deviceGrantError('invalid_request', 'Missing device_code.');
        }
        $basicAuthenticationAttempted = $this->hasBasicAuthorizationHeader();
        if ($basicAuthenticationAttempted && ($clientId !== null || $clientSecret !== null)) {
            return $this->deviceGrantError('invalid_request', 'Use exactly one client authentication method.');
        }
        if ($basicAuthenticationAttempted) {
            $credentials = $this->getBasicClientCredentials();
            if ($credentials === null) {
                return $this->invalidClientResponse('Malformed client credentials.', true);
            }
            [$clientId, $clientSecret] = $credentials;
        }
        if ($clientId === null || trim($clientId) === '') {
            return $this->invalidClientResponse('Missing client_id.', $basicAuthenticationAttempted);
        }

        try {
            $client = $this->clientMapper->getByIdentifier($clientId);
        } catch (ClientNotFoundException $e) {
            return $this->invalidClientResponse('Client not found.', $basicAuthenticationAttempted);
        }
        if ($client === null) {
            return $this->invalidClientResponse('Client not found.', $basicAuthenticationAttempted);
        }
        if ($client->getType() !== 'public' && (!is_string($clientSecret) || !hash_equals($client->getSecret(), $clientSecret))) {
            return $this->invalidClientResponse('Client authentication failed.', $basicAuthenticationAttempted);
        }
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            return $this->deviceGrantError('expired_client', 'Client expired.');
        }

        $authorization = $this->deviceCodeMapper->findByDeviceCode($deviceCode);
        if ($authorization === null || $authorization->getClientId() !== $client->getId()) {
            return $this->deviceGrantError('invalid_grant', 'The device code is invalid.');
        }
        $now = $this->time->getTime();
        if ($now >= $authorization->getExpiresAt()) {
            return $this->deviceGrantError('expired_token', 'The device code has expired.');
        }
        if ($authorization->getStatus() === DeviceCode::STATUS_DENIED) {
            return $this->deviceGrantError('access_denied', 'The user denied the authorization request.');
        }
        if (!in_array($authorization->getStatus(), [DeviceCode::STATUS_PENDING, DeviceCode::STATUS_APPROVED], true)
            || ($authorization->getStatus() === DeviceCode::STATUS_APPROVED && $authorization->getUserId() === null)) {
            return $this->deviceGrantError('invalid_grant', 'The device code has already been used.');
        }
        if (!$this->deviceCodeMapper->recordPoll($authorization, $now)) {
            return $this->deviceGrantError('slow_down', 'Polling is faster than the permitted interval.');
        }
        if ($authorization->getStatus() === DeviceCode::STATUS_PENDING) {
            return $this->deviceGrantError('authorization_pending', 'The user has not completed authorization.');
        }

        $user = $this->userManager->get($authorization->getUserId());
        if ($user === null) {
            return $this->deviceGrantError('access_denied', 'The authorizing user is no longer available.');
        }
        $groups = $this->groupManager->getUserGroups($user);
        $requiredGroups = $this->groupMapper->getGroupsByClientId($client->getId());
        $groupAllowed = $requiredGroups === [];
        foreach ($requiredGroups as $requiredGroup) {
            foreach ($groups as $group) {
                if ($requiredGroup->getGroupId() === $group->getGID()) {
                    $groupAllowed = true;
                    break 2;
                }
            }
        }
        if (!$groupAllowed) {
            return $this->deviceGrantError('access_denied', 'The user is no longer allowed to use this client.');
        }
        if (!$this->deviceCodeMapper->markConsumed($authorization, $now)) {
            return $this->deviceGrantError('invalid_grant', 'The device code has already been used.');
        }

        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $refreshExpireTime = $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME);
        $refreshCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($authorization->getUserId());
        $accessToken->setScope($authorization->getScope());
        $accessToken->setHashedCode(hash('sha512', $refreshCode));
        $accessToken->setCreated($now);
        $accessToken->setRefreshed($now);
        $accessToken->setExpiresAt($now + $expireTime);
        $accessToken->setNonce('');
        $accessToken->setResource(null);
        $accessToken->setCodeChallenge('');
        $accessToken->setCodeChallengeMethod('');
        $accessToken->setIdTokenClaims(null);
        $accessToken->setUserinfoClaims(null);
        $accessToken->setSid(null);

        try {
            $accessToken->setAccessToken($this->jwtGenerator->generateAccessToken(
                $accessToken,
                $client,
                $this->request->getServerProtocol(),
                $this->request->getServerHost()
            ));
            $accessToken = $this->accessTokenMapper->insert($accessToken);
            $idToken = $this->jwtGenerator->generateIdToken(
                $accessToken,
                $client,
                $this->request->getServerProtocol(),
                $this->request->getServerHost(),
                false,
                false
            );
        } catch (JwtCreationErrorException $e) {
            return $this->deviceGrantError('server_error', 'Token creation failed.', Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $responseData = [
            'access_token' => $accessToken->getAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => $expireTime,
            'id_token' => $idToken,
            'scope' => $accessToken->getScope(),
        ];
        $scopeArray = preg_split('/\s+/', trim($accessToken->getScope()), -1, PREG_SPLIT_NO_EMPTY);
        $provideRefreshTokenAlways = $this->appConfig->getAppValueString(
            Application::APP_CONFIG_PROVIDE_REFRESH_TOKEN_ALWAYS,
            Application::DEFAULT_PROVIDE_REFRESH_TOKEN_ALWAYS
        ) === 'true';
        if ($provideRefreshTokenAlways || in_array('offline_access', $scopeArray, true)) {
            $responseData['refresh_token'] = $refreshCode;
            if ($refreshExpireTime !== 'never') {
                $responseData['refresh_expires_in'] = (int)$refreshExpireTime;
            }
        }

        $response = new JSONResponse($responseData);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        return $response;
    }

    private function deviceGrantError(string $error, string $description, int $status = Http::STATUS_BAD_REQUEST): JSONResponse {
        $response = new JSONResponse([
            'error' => $error,
            'error_description' => $description,
        ], $status);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        return $response;
    }

    /**
     * Handle Token Exchange (RFC 8693).
     *
     * This implementation intentionally supports a constrained RFC 8693 profile:
     * same-issuer OAuth access token -> OAuth access token, a single optional
     * resource URI, no actor token, and no logical audience parameter.
     * Unsupported optional RFC 8693 features are rejected explicitly instead of
     * being silently ignored.
     *
     * @param string|null $client_id
     * @param string|null $client_secret
     * @return JSONResponse
     */
    private function handleTokenExchange(string|null $client_id, string|null $client_secret): JSONResponse
    {

        $this->logger->info('Processing Token Exchange request.');

        if (!$this->isFormUrlencodedRequest()) {
            $this->logger->info('Token Exchange request rejected because Content-Type is not application/x-www-form-urlencoded.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Token Exchange requests must use application/x-www-form-urlencoded.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $parameterNames = [
            'grant_type',
            'subject_token',
            'subject_token_type',
            'resource',
            'audience',
            'scope',
            'requested_token_type',
            'actor_token',
            'actor_token_type',
            'client_id',
            'client_secret',
        ];
        $parameters = $this->formUrlencodedParameterParser->readSelectedParameters($parameterNames);
        if ($parameters === null) {
            $this->logger->error('Unable to read form-encoded Token Exchange request body.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Unable to read the Token Exchange request body.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $parameters = $this->omitEmptyFormParameterValues($parameters);

        $requiredSingletons = ['grant_type', 'subject_token', 'subject_token_type'];
        foreach ($requiredSingletons as $parameterName) {
            if (count($parameters[$parameterName]) !== 1) {
                return new JSONResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Parameter ' . $parameterName . ' must occur exactly once.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        $optionalSingletons = [
            'scope',
            'requested_token_type',
            'actor_token',
            'actor_token_type',
            'client_id',
            'client_secret',
        ];
        foreach ($optionalSingletons as $parameterName) {
            if (count($parameters[$parameterName]) > 1) {
                return new JSONResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Parameter ' . $parameterName . ' must not occur more than once.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        if ($parameters['grant_type'][0] !== self::TOKEN_EXCHANGE_GRANT_TYPE) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'The form body grant_type does not match Token Exchange.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (count($parameters['resource']) > 1) {
            $this->logger->info('Multiple resource parameters are not supported for Token Exchange.', [
                'resource_count' => count($parameters['resource']),
            ]);
            return new JSONResponse([
                'error' => 'invalid_target',
                'error_description' => 'Multiple resource parameters are not supported. Use a single configured resource URI.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (count($parameters['audience']) > 0) {
            $this->logger->info('audience parameter is not supported for Token Exchange.', [
                'audience_count' => count($parameters['audience']),
            ]);
            return new JSONResponse([
                'error' => 'invalid_target',
                'error_description' => 'The audience parameter is not supported. Use a configured resource URI.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $subjectToken = $parameters['subject_token'][0];
        $subjectTokenType = $parameters['subject_token_type'][0];
        $resource = $parameters['resource'][0] ?? null;
        $scope = $parameters['scope'][0] ?? null;
        $requestedTokenType = $parameters['requested_token_type'][0] ?? null;
        $actorToken = $parameters['actor_token'][0] ?? null;
        $actorTokenType = $parameters['actor_token_type'][0] ?? null;
        $bodyClientId = $parameters['client_id'][0] ?? null;
        $bodyClientSecret = $parameters['client_secret'][0] ?? null;

        if (trim($subjectToken) === '') {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Missing or invalid required parameter: subject_token.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if (trim($subjectTokenType) === '') {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameter: subject_token_type.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($subjectTokenType !== self::TOKEN_TYPE_ACCESS_TOKEN) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Unsupported subject_token_type. Only urn:ietf:params:oauth:token-type:access_token is supported.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($requestedTokenType !== null && $requestedTokenType !== self::TOKEN_TYPE_ACCESS_TOKEN) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Unsupported requested_token_type. Only urn:ietf:params:oauth:token-type:access_token is supported.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($actorToken !== null || $actorTokenType !== null) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'actor_token and actor_token_type are not supported.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($resource !== null && !$this->isValidTokenExchangeResourceUri($resource)) {
            return new JSONResponse([
                'error' => 'invalid_target',
                'error_description' => 'The resource must be a single absolute URI without a fragment.',
            ], Http::STATUS_BAD_REQUEST);
        }
        $basicAuthenticationAttempted = $this->hasBasicAuthorizationHeader();
        if ($basicAuthenticationAttempted && ($bodyClientId !== null || $bodyClientSecret !== null)) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Use exactly one client authentication method.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // The form body is authoritative for client_secret_post. Otherwise use Basic.
        $client_id = $bodyClientId;
        $client_secret = $bodyClientSecret;
        // Get client authentication from request or HTTP Basic credentials.
        if (!isset($client_id) || trim((string)$client_id) === '') {
            $this->logger->debug('No client_id in request. Trying to fetch from Authorization Header.');
            $credentials = $this->getBasicClientCredentials();
            if ($credentials !== null) {
                [$client_id, $client_secret] = $credentials;
            }
        }

        if ($client_id === null || trim($client_id) === '') {
            $this->logger->info('Missing client_id in Token Exchange request.');
            return $this->invalidClientResponse('Missing client_id.', $basicAuthenticationAttempted);
        }

        try {
            $client = $this->clientMapper->getByIdentifier($client_id);
        } catch (ClientNotFoundException $e) {
            $this->logger->info('Client not found in Token Exchange. Client id was ' . $client_id . '.');
            return $this->invalidClientResponse('Client not found.', $basicAuthenticationAttempted);
        }

        if ($client === null) {
            $this->logger->info('Client not found in Token Exchange. Client id was ' . $client_id . '.');
            return $this->invalidClientResponse('Client not found.', $basicAuthenticationAttempted);
        }

        if ($client->getType() === 'public') {
            $this->logger->info('Token Exchange is not allowed for public client ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'unauthorized_client',
                'error_description' => 'Token Exchange is not allowed for public clients.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!is_string($client_secret) || !hash_equals($client->getSecret(), $client_secret)) {
            $this->logger->error('Client authentication failed in Token Exchange. Client id was ' . $client_id . '.');
            return $this->invalidClientResponse('Client authentication failed.', $basicAuthenticationAttempted);
        }

        if (!$client->getTexEnabled()) {
            $this->logger->info('Token Exchange is not enabled for client ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'unauthorized_client',
                'error_description' => 'Token Exchange is not enabled for this client.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // subject_token_type=access_token is validated only against the access-token
        // store. Authorization codes and refresh tokens must never be accepted here.
        try {
            $subjectTokenAccessToken = $this->accessTokenMapper->getByAccessToken($subjectToken);
        } catch (AccessTokenNotFoundException $e) {
            $this->logger->info('Subject token not found or invalid. Client id: ' . $client_id);
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Subject token is invalid or has expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Token Exchange is an administrative authorization for impersonation-style exchange. RFC 8693
        // permits cross-client exchange, but this implementation requires an
        // explicit allow-list relationship for every subject-token client,
        // including same-client exchange. This keeps TEX fail-closed and avoids
        // interpreting tex_enabled as permission to exchange arbitrary tokens.
        if ($this->texSubjectClientMapper === null) {
            $this->logger->error('TEX subject-client policy mapper is unavailable.');
            return new JSONResponse([
                'error' => 'server_error',
                'error_description' => 'Token Exchange subject-client policy is unavailable.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if (!$this->texSubjectClientMapper->isAllowed($client->getId(), $subjectTokenAccessToken->getClientId())) {
            $this->logger->info('Subject-token client is not authorized for Token Exchange.', [
                'requesting_client' => $client_id,
                'subject_token_client_id' => $subjectTokenAccessToken->getClientId(),
            ]);
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'The subject token is not authorized for Token Exchange by this client.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($subjectTokenAccessToken->getClientId() !== $client->getId()) {
            $this->logger->info('Processing administratively authorized cross-client Token Exchange.', [
                'requesting_client' => $client_id,
                'subject_token_client_id' => $subjectTokenAccessToken->getClientId(),
            ]);
        }

        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $now = $this->time->getTime();
        $subjectExpiresAt = $subjectTokenAccessToken->getEffectiveExpiresAt($expireTime);
        if ($now >= $subjectExpiresAt) {
            $this->logger->info('Subject token has expired. Client id: ' . $client_id);
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Subject token has expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // The exchanged token must never outlive its subject token. Cap its lifetime
        // to the configured access-token lifetime and the subject token's remaining
        // lifetime, whichever is shorter.
        $exchangeExpireTime = min($expireTime, $subjectExpiresAt - $now);

        // If resource is omitted, RFC 8693 permits the AS to apply policy for the target.
        // This implementation inherits the subject token resource, but only after the
        // effective value has been re-validated against the requesting client's TEX
        // target whitelist. This constrained profile requires an effective resource;
        // an exchange without an explicit or inherited target is rejected.
        $effectiveResource = $resource !== null
            ? $resource
            : trim((string)($subjectTokenAccessToken->getResource() ?? ''));

        if ($effectiveResource === '') {
            $this->logger->info('Token Exchange has no effective resource target.', ['client_id' => $client_id]);
            return new JSONResponse([
                'error' => 'invalid_target',
                'error_description' => 'Token Exchange requires an explicit or inherited allow-listed resource.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $texTargets = [];
        if ($effectiveResource !== '') {
            if ($this->texTargetMapper === null) {
                $this->logger->error('TEX target mapper is unavailable while an effective resource is present.');
                return new JSONResponse([
                    'error' => 'server_error',
                    'error_description' => 'Token Exchange target configuration is unavailable.',
                ], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $texTargets = $this->texTargetMapper->getByClientId($client->getId());
            $resourceValid = false;
            foreach ($texTargets as $target) {
                if ($target->getResourceUrl() === $effectiveResource) {
                    $resourceValid = true;
                    break;
                }
            }

            if (!$resourceValid) {
                $this->logger->info('Effective resource does not match any TEX target.', [
                    'client_id' => $client_id,
                    'resource' => $effectiveResource,
                    'resource_in_request' => $resource !== null,
                ]);
                return new JSONResponse([
                    'error' => 'invalid_target',
                    'error_description' => 'The effective resource is not allowed for Token Exchange.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        // Scope downscoping: an exchanged token can never gain scopes that were not
        // present in the subject token. tex_allowed_scopes is an additional ceiling.
        $subjectScopes = preg_split('/ +/', trim((string)$subjectTokenAccessToken->getScope()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $subjectScopes = array_values(array_unique($subjectScopes));

        $texAllowedScopesValue = $client->getTexAllowedScopes();
        $texAllowedScopes = [];
        if (is_string($texAllowedScopesValue) && trim($texAllowedScopesValue) !== '') {
            $texAllowedScopes = preg_split('/ +/', trim($texAllowedScopesValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $texAllowedScopes = array_values(array_unique($texAllowedScopes));
        }
        if ($texAllowedScopes === []) {
            $this->logger->warning('Token Exchange denied because no allowed scopes are configured for client ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_scope',
                'error_description' => 'No Token Exchange scopes are configured for this client.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($scope !== null) {
            $scope = trim($scope);
            if ($scope === '') {
                return new JSONResponse([
                    'error' => 'invalid_scope',
                    'error_description' => 'The requested scope must not be empty.',
                ], Http::STATUS_BAD_REQUEST);
            }

            $effectiveScopes = preg_split('/ +/', $scope, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $effectiveScopes = array_values(array_unique($effectiveScopes));
            foreach ($effectiveScopes as $requestedScope) {
                if (!in_array($requestedScope, $subjectScopes, true)) {
                    $this->logger->info('Requested scope exceeds subject token scope. Client id: ' . $client_id . ', Scope: ' . $requestedScope);
                    return new JSONResponse([
                        'error' => 'invalid_scope',
                        'error_description' => 'The requested scope exceeds the subject token scope.',
                    ], Http::STATUS_BAD_REQUEST);
                }
                if (!in_array($requestedScope, $texAllowedScopes, true)) {
                    $this->logger->info('Requested scope not allowed for TEX. Client id: ' . $client_id . ', Scope: ' . $requestedScope);
                    return new JSONResponse([
                        'error' => 'invalid_scope',
                        'error_description' => 'The requested scope is not allowed for Token Exchange.',
                    ], Http::STATUS_BAD_REQUEST);
                }
            }
        } else {
            $effectiveScopes = array_values(array_filter(
                $subjectScopes,
                static fn (string $subjectScope): bool => in_array($subjectScope, $texAllowedScopes, true)
            ));
        }
        if ($effectiveScopes === []) {
            return new JSONResponse([
                'error' => 'invalid_scope',
                'error_description' => 'No permitted Token Exchange scopes remain for this subject token.',
            ], Http::STATUS_BAD_REQUEST);
        }
        $effectiveScope = implode(' ', $effectiveScopes);

        $uid = $subjectTokenAccessToken->getUserId();
        $user = $this->userManager->get($uid);
        if ($user === null) {
            $this->logger->info('Subject token references a user that no longer exists. Client id: ' . $client_id);
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Subject token is not acceptable.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $groups = $this->groupManager->getUserGroups($user);
        $clientGroups = $this->groupMapper->getGroupsByClientId($client->getId());
        $groupFound = count($clientGroups) < 1;
        foreach ($clientGroups as $clientGroup) {
            foreach ($groups as $userGroup) {
                if ($clientGroup->getGroupId() === $userGroup->getGID()) {
                    $groupFound = true;
                    break 2;
                }
            }
        }
        if (!$groupFound) {
            $this->logger->info('User not in allowed groups for Token Exchange. Client id: ' . $client_id . ', User: ' . $uid);
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Token Exchange not allowed for user groups.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $subjectTokenId = (int)$subjectTokenAccessToken->getId();
        if ($subjectTokenId <= 0) {
            $this->logger->error('Subject token has no persistent database id during Token Exchange.');
            return new JSONResponse([
                'error' => 'server_error',
                'error_description' => 'Subject token cannot be locked for Token Exchange.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Keep a snapshot of every subject-token property used to authorize this
        // exchange. After acquiring the database write lock below, the row is
        // re-read and compared with this snapshot so a concurrent refresh/update
        // cannot make the already-computed scope/resource policy stale.
        $subjectClientId = $subjectTokenAccessToken->getClientId();
        $subjectUserId = $subjectTokenAccessToken->getUserId();
        $subjectScopeSnapshot = (string)$subjectTokenAccessToken->getScope();
        $subjectResourceSnapshot = trim((string)($subjectTokenAccessToken->getResource() ?? ''));

        $newCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);
        $transactionActive = false;
        $inserted = false;
        try {
            // Serialize issuance with every DELETE of the subject row. The mapper
            // acquires a database-appropriate write lock and keeps it until commit.
            // A revocation that wins first makes the lock/re-read fail; an
            // exchange that wins first commits the complete child token before the
            // revocation can proceed and ON DELETE CASCADE then removes the child.
            $this->accessTokenMapper->beginTokenExchangeTransaction();
            $transactionActive = true;
            $lockedSubjectToken = $this->accessTokenMapper->lockTokenExchangeSubject($subjectTokenId);

            if (!hash_equals($subjectToken, (string)$lockedSubjectToken->getAccessToken())
                || $lockedSubjectToken->getClientId() !== $subjectClientId
                || $lockedSubjectToken->getUserId() !== $subjectUserId
                || (string)$lockedSubjectToken->getScope() !== $subjectScopeSnapshot
                || trim((string)($lockedSubjectToken->getResource() ?? '')) !== $subjectResourceSnapshot) {
                $this->rollBackTokenExchangeTransactionSafely();
                $transactionActive = false;
                $this->logger->info('Subject token changed concurrently during Token Exchange.', [
                    'client_id' => $client_id,
                    'subject_token_id' => $subjectTokenId,
                ]);
                return new JSONResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Subject token changed or was revoked during Token Exchange.',
                ], Http::STATUS_BAD_REQUEST);
            }

            // Re-evaluate time while holding the lock. This is authoritative for the
            // issued child's timestamps and guarantees that even a request delayed
            // while waiting for a concurrent writer cannot outlive its subject.
            $now = $this->time->getTime();
            $subjectExpiresAt = $lockedSubjectToken->getEffectiveExpiresAt($expireTime);
            if ($now >= $subjectExpiresAt) {
                $this->rollBackTokenExchangeTransactionSafely();
                $transactionActive = false;
                $this->logger->info('Subject token expired while waiting for Token Exchange lock. Client id: ' . $client_id);
                return new JSONResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Subject token has expired.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $exchangeExpireTime = min($expireTime, $subjectExpiresAt - $now);

            // A token exchange creates a new, independently stored token. Insert first
            // so JWT generation sees a stable database ID (jti) and creation time.
            $newAccessToken = new AccessToken();
            $newAccessToken->setClientId($client->getId());
            $newAccessToken->setParentTokenId($subjectTokenId);
            $newAccessToken->setUserId($uid);
            $newAccessToken->setScope($effectiveScope);
            $newAccessToken->setHashedCode(hash('sha512', $newCode));
            $newAccessToken->setAccessToken('');
            $newAccessToken->setCreated($now);
            $newAccessToken->setRefreshed($now);
            $newAccessToken->setExpiresAt($now + $exchangeExpireTime);
            $newAccessToken->setNonce('');
            $newAccessToken->setResource($effectiveResource);
            $newAccessToken->setCodeChallenge('');
            $newAccessToken->setCodeChallengeMethod('');

            $newAccessToken = $this->accessTokenMapper->insert($newAccessToken);
            $inserted = true;
            $newAccessToken->setAccessToken($this->jwtGenerator->generateAccessToken(
                $newAccessToken,
                $client,
                $this->request->getServerProtocol(),
                $this->request->getServerHost(),
                $exchangeExpireTime,
                false
            ));
            $this->accessTokenMapper->update($newAccessToken);

            // Commit before constructing the successful response. Once this commit
            // returns, a waiting revocation may proceed; that ordering is a normal
            // post-issuance revocation rather than an issuance race.
            $this->accessTokenMapper->commitTokenExchangeTransaction();
            $transactionActive = false;
        } catch (AccessTokenNotFoundException $e) {
            if ($transactionActive) {
                $this->rollBackTokenExchangeTransactionSafely();
                $transactionActive = false;
            }
            $this->logger->info('Subject token was revoked before Token Exchange acquired its lock.', [
                'client_id' => $client_id,
                'subject_token_id' => $subjectTokenId,
            ]);
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Subject token is invalid or has been revoked.',
            ], Http::STATUS_BAD_REQUEST);
        } catch (DatabaseException $e) {
            if ($transactionActive) {
                $this->rollBackTokenExchangeTransactionSafely();
                $transactionActive = false;
            }
            if (!$inserted && $e->getReason() === DatabaseException::REASON_FOREIGN_KEY_VIOLATION) {
                $this->logger->info('Subject token was revoked concurrently during Token Exchange.', [
                    'client_id' => $client_id,
                    'subject_token_id' => $subjectTokenId,
                ]);
                return new JSONResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Subject token is invalid or has been revoked.',
                ], Http::STATUS_BAD_REQUEST);
            }
            throw $e;
        } catch (JwtCreationErrorException $e) {
            if ($transactionActive) {
                $this->rollBackTokenExchangeTransactionSafely();
                $transactionActive = false;
            }
            $this->logger->error('Failed to generate access token during Token Exchange.');
            return new JSONResponse([
                'error' => 'server_error',
                'error_description' => 'Failed to generate access token.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            if ($transactionActive) {
                $this->rollBackTokenExchangeTransactionSafely();
            }
            throw $e;
        }

        $this->logger->info('Token Exchange successful. User: ' . $uid . ', Client: ' . $client_id);

        $responseData = [
            'access_token' => $newAccessToken->getAccessToken(),
            'issued_token_type' => self::TOKEN_TYPE_ACCESS_TOKEN,
            'token_type' => 'Bearer',
            'expires_in' => $exchangeExpireTime,
            'scope' => $newAccessToken->getScope(),
        ];

        if ($effectiveResource !== '' && $this->texTargetMapper !== null) {
            foreach ($texTargets as $target) {
                if ($target->getResourceUrl() === $effectiveResource) {
                    $this->texTargetMapper->markUsed($target, $now);
                    break;
                }
            }
        }

        $response = new JSONResponse($responseData);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST');

        return $response;
    }

    /**
     * Validate an RFC 8693 resource value as one absolute RFC 3986 URI without
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

    /**
     * Base64URL encode (RFC 7636 Section 4.2)
     *
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Verify PKCE code_verifier against code_challenge (RFC 7636 Section 4.6)
     *
     * @param string $codeVerifier
     * @param string $codeChallenge
     * @param string $codeChallengeMethod
     * @return bool
     */
    private function verifyPkce(string $codeVerifier, string $codeChallenge, string $codeChallengeMethod): bool
    {
        // Validate code_verifier format: 43-128 characters, unreserved chars only
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier)) {
            return false;
        }

        // Compute the challenge based on the method
        if ($codeChallengeMethod === 'S256') {
            $computedChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        } elseif ($codeChallengeMethod === 'plain') {
            $computedChallenge = $codeVerifier;
        } else {
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($codeChallenge, $computedChallenge);
    }
}
