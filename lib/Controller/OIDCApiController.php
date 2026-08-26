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
     * @param TexTargetMapper $texTargetMapper
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
                    TexTargetMapper $texTargetMapper,
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
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->crypto = $crypto;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->authorizationCodeMapper = $authorizationCodeMapper;
        $this->clientMapper = $clientMapper;
        $this->groupMapper = $groupMapper;
        $this->userConsentMapper = $userConsentMapper;
        $this->texTargetMapper = $texTargetMapper;
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
        $tokenExchangeGrantType = 'urn:ietf:params:oauth:grant-type:token-exchange';
        if ($grant_type === $tokenExchangeGrantType) {
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
     * Handle Token Exchange (RFC 8693)
     *
     * @param string|null $client_id
     * @param string|null $client_secret
     * @return JSONResponse
     */
    private function handleTokenExchange(string|null $client_id, string|null $client_secret): JSONResponse
    {
        $this->logger->info('Processing Token Exchange request.');

        // Get required parameters from request body
        $subjectToken = $this->request->getParam('subject_token');
        $subjectTokenType = $this->request->getParam('subject_token_type', 'access_token');
        $resource = $this->request->getParam('resource');
        $scope = $this->request->getParam('scope');

        // Validate subject_token is provided
        if (empty($subjectToken)) {
            $this->logger->info('Missing subject_token in Token Exchange request.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameter: subject_token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Get client authentication from request or headers
        if (!isset($client_id) || empty($client_id)) {
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

        // Get client from database
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

        // Validate client authentication
        if ($client->getType() === 'public') {
            $this->logger->debug('Authenticated public client in Token Exchange. Client id was ' . $client_id . '.');
        } else {
            if ($client->getSecret() !== $client_secret) {
                $this->logger->error('Client authentication failed in Token Exchange. Client id was ' . $client_id . '.');
                return new JSONResponse([
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        // Check if Token Exchange is enabled for this client
        if (!$client->getTexEnabled()) {
            $this->logger->info('Token Exchange is not enabled for client ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Token Exchange is not enabled for this client.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate and parse the subject token
        $subjectTokenAccessToken = null;
        try {
            // Try to find the access token by its code (for opaque tokens) or by the token itself (for JWT)
            try {
                $subjectTokenAccessToken = $this->accessTokenMapper->getByCode($subjectToken);
            } catch (AccessTokenNotFoundException $e) {
                // If not found by code, try to find it by access token value (for JWT tokens)
                try {
                    $subjectTokenAccessToken = $this->accessTokenMapper->getByAccessToken($subjectToken);
                } catch (AccessTokenNotFoundException $e2) {
                    $this->logger->info('Subject token not found or invalid. Client id: ' . $client_id);
                    return new JSONResponse([
                        'error' => 'invalid_grant',
                        'error_description' => 'Subject token is invalid or has expired.',
                    ], Http::STATUS_BAD_REQUEST);
                }
            }
        } catch (AccessTokenNotFoundException $e) {
            $this->logger->info('Subject token not found. Client id: ' . $client_id);
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Subject token is invalid or has expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate that the subject token belongs to this client
        if ($subjectTokenAccessToken->getClientId() !== $client->getId()) {
            $this->logger->info('Subject token does not belong to requesting client. Client id: ' . $client_id);
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Subject token is not valid for this client.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate subject token is not expired
        $expireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, '0');
        if ($this->time->getTime() > $subjectTokenAccessToken->getRefreshed() + $expireTime) {
            $this->logger->info('Subject token has expired. Client id: ' . $client_id);
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Subject token has expired.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Validate resource parameter if provided
        if (!empty($resource)) {
            // Check if the resource matches any of the client's TEX targets
            $texTargets = $this->texTargetMapper->getByClientId($client->getId());
            $resourceValid = false;

            if (empty($texTargets)) {
                $this->logger->info('No TEX targets configured for client, but resource parameter provided. Client id: ' . $client_id);
                return new JSONResponse([
                    'error' => 'invalid_target',
                    'error_description' => 'The specified resource is not allowed for Token Exchange.',
                ], Http::STATUS_BAD_REQUEST);
            }

            foreach ($texTargets as $target) {
                if ($target->getResourceUrl() === $resource) {
                    $resourceValid = true;
                    break;
                }
            }

            if (!$resourceValid) {
                $this->logger->info('Resource parameter does not match any TEX target. Client id: ' . $client_id . ', Resource: ' . $resource);
                return new JSONResponse([
                    'error' => 'invalid_target',
                    'error_description' => 'The specified resource is not allowed for Token Exchange.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        // Validate scope parameter
        $requestedScopes = '';
        if (!empty($scope)) {
            // Parse and validate requested scopes
            $requestedScopes = trim($scope);
            
            // Check if all requested scopes are allowed by the client's tex_allowed_scopes
            $texAllowedScopes = $client->getTexAllowedScopes();
            
            if (!empty($texAllowedScopes)) {
                // Parse allowed scopes
                $allowedScopesArray = preg_split('/ +/', trim($texAllowedScopes), -1, PREG_SPLIT_NO_EMPTY);
                
                // Parse requested scopes
                $requestedScopesArray = preg_split('/ +/', $requestedScopes, -1, PREG_SPLIT_NO_EMPTY);
                
                // Check each requested scope is in allowed scopes
                foreach ($requestedScopesArray as $requestedScope) {
                    if (!in_array($requestedScope, $allowedScopesArray)) {
                        $this->logger->info('Requested scope not allowed for TEX. Client id: ' . $client_id . ', Scope: ' . $requestedScope);
                        return new JSONResponse([
                            'error' => 'invalid_scope',
                            'error_description' => 'The requested scope is not allowed for Token Exchange.',
                        ], Http::STATUS_BAD_REQUEST);
                    }
                }
            }
        }

        // Get the user and groups from the subject token
        $uid = $subjectTokenAccessToken->getUserId();
        $user = $this->userManager->get($uid);
        $groups = $this->groupManager->getUserGroups($user);

        // Check if user is in allowed groups for client
        $clientGroups = $this->groupMapper->getGroupsByClientId($client->getId());
        $groupFound = false;
        if (count($clientGroups) < 1) { 
            $groupFound = true; 
        }
        foreach ($clientGroups as $clientGroup) {
            foreach ($groups as $userGroup) {
                if ($clientGroup->getGroupId() === $userGroup->getGID()) {
                    $groupFound = true;
                    break;
                }
            }
        }
        if (!$groupFound) {
            $this->logger->info('User not in allowed groups for Token Exchange. Client id: ' . $client_id . ', User: ' . $uid);
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Token Exchange not allowed for user groups.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Create a new access token for the exchanged token
        $newAccessToken = new AccessToken();
        $newCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        
        $newAccessToken->setClientId($client->getId());
        $newAccessToken->setUserId($uid);
        $newAccessToken->setScope($requestedScopes !== '' ? $requestedScopes : $subjectTokenAccessToken->getScope());
        $newAccessToken->setHashedCode(hash('sha512', $newCode));
        $newAccessToken->setAccessToken(''); // Will be set below
        $newAccessToken->setRefreshed($this->time->getTime());
        $newAccessToken->setCodeChallenge('');
        $newAccessToken->setCodeChallengeMethod('');

        // Generate the access token (opaque or JWT based on client configuration)
        try {
            $newAccessToken->setAccessToken($this->jwtGenerator->generateAccessToken($newAccessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost()));
        } catch (JwtCreationErrorException $e) {
            $this->logger->error('Failed to generate access token during Token Exchange.');
            return new JSONResponse([
                'error' => 'server_error',
                'error_description' => 'Failed to generate access token.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->accessTokenMapper->insert($newAccessToken);

        // Generate ID token if scope includes openid
        $jwt = null;
        $scopeArray = preg_split('/ +/', trim($newAccessToken->getScope()));
        if (in_array('openid', $scopeArray)) {
            try {
                $jwt = $this->jwtGenerator->generateIdToken($newAccessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost(), false, false);
            } catch (JwtCreationErrorException $e) {
                $this->logger->error('Failed to generate ID token during Token Exchange.');
                // Continue without ID token - not critical
            }
        }

        $this->logger->info('Token Exchange successful. User: ' . $uid . ', Client: ' . $client_id);

        $responseData = [
            'access_token' => $newAccessToken->getAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => $expireTime,
        ];

        if ($jwt !== null) {
            $responseData['id_token'] = $jwt;
        }

        // Mark the TEX target as used if resource was specified
        if (!empty($resource)) {
            $texTargets = $this->texTargetMapper->getByClientId($client->getId());
            foreach ($texTargets as $target) {
                if ($target->getResourceUrl() === $resource) {
                    $this->texTargetMapper->markUsed($target, $this->time->getTime());
                    break;
                }
            }
        }

        $response = new JSONResponse($responseData);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST');

        return $response;
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
