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
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
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
                    ?TexTargetMapper $texTargetMapper = null
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
            rawurldecode($clientId),
            rawurldecode($clientSecret),
        ];
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
        string|null $code_verifier = null): JSONResponse
    {
        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, '0');
        $refreshExpireTime = $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME);
        // Handle token exchange (RFC 8693)
        if ($grant_type === self::TOKEN_EXCHANGE_GRANT_TYPE) {
            return $this->handleTokenExchange($client_id, $client_secret);
        }

        // We only handle two types
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
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Missing client_id.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $client = $this->clientMapper->getByIdentifier($client_id);
        } catch (ClientNotFoundException $e) {
            $this->logger->info('Client not found. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client not found.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($client === null) {
            $this->logger->info('Client not found. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client not found.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($client->getType() === 'public') {
            // Only the client id must be present for a public client.
            $this->logger->debug('Authenticated public client. Client id was ' . $client_id . '.');
        } else {
            // The client id and secret must match. Else we don't provide an access token!
            if ($client->getSecret() !== $client_secret) {
                $this->logger->error('Client authentication failed. Client id was ' . $client_id . '.');
                return new JSONResponse([
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed.',
                ], Http::STATUS_BAD_REQUEST);
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
            if ($this->time->getTime() > $accessToken->getRefreshed() + $expireTime) {
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
            if ($this->time->getTime() > $accessToken->getRefreshed() + $refreshExpireTime) {
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
        $accessToken->setRefreshed($this->time->getTime() + $expireTime);

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

        $subjectToken = $this->request->getParam('subject_token');
        $subjectTokenType = $this->request->getParam('subject_token_type');
        $resource = $this->request->getParam('resource');
        $audience = $this->request->getParam('audience');
        $scope = $this->request->getParam('scope');
        $requestedTokenType = $this->request->getParam('requested_token_type');
        $actorToken = $this->request->getParam('actor_token');
        $actorTokenType = $this->request->getParam('actor_token_type');

        // RFC 8693 Section 2.1: subject_token and subject_token_type are REQUIRED.
        if (!is_string($subjectToken) || trim($subjectToken) === '') {
            $this->logger->info('Missing or invalid subject_token in Token Exchange request.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Missing or invalid required parameter: subject_token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!is_string($subjectTokenType) || trim($subjectTokenType) === '') {
            $this->logger->info('Missing subject_token_type in Token Exchange request.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameter: subject_token_type.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // This profile supports OAuth access tokens issued by this authorization server.
        if ($subjectTokenType !== self::TOKEN_TYPE_ACCESS_TOKEN) {
            $this->logger->info('Unsupported subject_token_type in Token Exchange request.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Unsupported subject_token_type. Only urn:ietf:params:oauth:token-type:access_token is supported.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // requested_token_type is optional. If present, this implementation only
        // supports issuing another OAuth access token.
        if ($requestedTokenType !== null
            && (!is_string($requestedTokenType) || $requestedTokenType !== self::TOKEN_TYPE_ACCESS_TOKEN)) {
            $this->logger->info('Unsupported requested_token_type in Token Exchange request.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Unsupported requested_token_type. Only urn:ietf:params:oauth:token-type:access_token is supported.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Actor/delegation semantics are not part of the supported profile. RFC 8693
        // requires actor_token_type when actor_token is present and forbids it otherwise;
        // reject either parameter so unsupported delegation is never silently ignored.
        if ($actorToken !== null || $actorTokenType !== null) {
            $this->logger->info('actor_token/actor_token_type is not supported for Token Exchange.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'actor_token and actor_token_type are not supported.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Logical audiences are not configured by the current TEX target model. Reject
        // them explicitly; clients can use a configured resource URI instead.
        if ($audience !== null) {
            $this->logger->info('audience parameter is not supported for Token Exchange.');
            return new JSONResponse([
                'error' => 'invalid_target',
                'error_description' => 'The audience parameter is not supported. Use a configured resource URI.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // The current target model supports exactly one resource URI. RFC 8693 permits
        // multiple resources, but an AS may reject a target request it cannot fulfill.
        if ($resource !== null) {
            if (!is_string($resource) || !$this->isValidTokenExchangeResourceUri($resource)) {
                $this->logger->info('Invalid resource URI in Token Exchange request.');
                return new JSONResponse([
                    'error' => 'invalid_target',
                    'error_description' => 'The resource must be a single absolute URI without a fragment.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        if ($scope !== null && !is_string($scope)) {
            return new JSONResponse([
                'error' => 'invalid_scope',
                'error_description' => 'The scope parameter must be a space-delimited string.',
            ], Http::STATUS_BAD_REQUEST);
        }

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
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Missing client_id.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $client = $this->clientMapper->getByIdentifier($client_id);
        } catch (ClientNotFoundException $e) {
            $this->logger->info('Client not found in Token Exchange. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client not found.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($client === null) {
            $this->logger->info('Client not found in Token Exchange. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client not found.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($client->getType() === 'public') {
            $this->logger->info('Token Exchange is not allowed for public client ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Token Exchange is not allowed for public clients.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($client->getSecret() !== $client_secret) {
            $this->logger->error('Client authentication failed in Token Exchange. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!$client->getTexEnabled()) {
            $this->logger->info('Token Exchange is not enabled for client ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_request',
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

        // RFC 8693 does not require the requesting client to be the client to which
        // the subject token was originally issued. Cross-client exchanges are therefore
        // allowed, while the authenticated requesting client's TEX target, scope and
        // group policies remain authoritative for the issued token.
        if ($subjectTokenAccessToken->getClientId() !== $client->getId()) {
            $this->logger->info('Processing cross-client Token Exchange.', [
                'requesting_client' => $client_id,
                'subject_token_client_id' => $subjectTokenAccessToken->getClientId(),
            ]);
        }

        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);
        $now = $this->time->getTime();
        $subjectExpiresAt = $subjectTokenAccessToken->getRefreshed() + $expireTime;
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
        // target whitelist. An empty effective resource remains unset and falls back to
        // the requesting client as audience in JwtGenerator.
        $effectiveResource = $resource !== null
            ? $resource
            : trim((string)($subjectTokenAccessToken->getResource() ?? ''));

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
                if ($texAllowedScopes !== [] && !in_array($requestedScope, $texAllowedScopes, true)) {
                    $this->logger->info('Requested scope not allowed for TEX. Client id: ' . $client_id . ', Scope: ' . $requestedScope);
                    return new JSONResponse([
                        'error' => 'invalid_scope',
                        'error_description' => 'The requested scope is not allowed for Token Exchange.',
                    ], Http::STATUS_BAD_REQUEST);
                }
            }
        } else {
            $effectiveScopes = $subjectScopes;
            if ($texAllowedScopes !== []) {
                $effectiveScopes = array_values(array_filter(
                    $effectiveScopes,
                    static fn (string $subjectScope): bool => in_array($subjectScope, $texAllowedScopes, true)
                ));
            }
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

        // A token exchange creates a new, independently stored token. Insert first so
        // JWT generation sees a stable database ID (jti) and correct creation time.
        $newAccessToken = new AccessToken();
        $newCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

        $newAccessToken->setClientId($client->getId());
        $newAccessToken->setUserId($uid);
        $newAccessToken->setScope($effectiveScope);
        $newAccessToken->setHashedCode(hash('sha512', $newCode));
        $newAccessToken->setAccessToken('');
        $newAccessToken->setCreated($now);
        // Existing token validation derives expiry as refreshed + configured lifetime.
        // Shift the refresh anchor so opaque-token validation and JWT exp enforce the
        // same shortened Token Exchange lifetime without changing the database schema.
        $newAccessToken->setRefreshed($now + $exchangeExpireTime - $expireTime);
        $newAccessToken->setNonce('');
        $newAccessToken->setResource($effectiveResource);
        $newAccessToken->setCodeChallenge('');
        $newAccessToken->setCodeChallengeMethod('');

        $inserted = false;
        try {
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
        } catch (JwtCreationErrorException $e) {
            if ($inserted) {
                $this->accessTokenMapper->delete($newAccessToken);
            }
            $this->logger->error('Failed to generate access token during Token Exchange.');
            return new JSONResponse([
                'error' => 'server_error',
                'error_description' => 'Failed to generate access token.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
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
