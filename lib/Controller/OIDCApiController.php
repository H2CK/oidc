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
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
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
    /** @var ClientMapper */
    private $clientMapper;
    /** @var GroupMapper */
    private $groupMapper;
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
     * @param ClientMapper $clientMapper
     * @param GroupMapper $groupMapper
     * @param ISecureRandom $random
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
                    ClientMapper $clientMapper,
                    GroupMapper $groupMapper,
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
        $this->clientMapper = $clientMapper;
        $this->groupMapper = $groupMapper;
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

    private function getAuthorizationHeader() {
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }

        // Fallback via $_SERVER
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        return null;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_token)
     *
     * @param string $grant_type
     * @param string|null $code
     * @param string|null $refresh_token
     * @param string|null $refresh_token
     * @param string|null $client_id
     * @param string|null $client_secret
     * @param string|null $code_verifier
     * @return JSONResponse
     */
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
        $refreshExpireTime = (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME);
        // We only handle two types
        if ($grant_type !== 'authorization_code' && $grant_type !== 'refresh_token') {
            $this->logger->info('Invalid grant_type provided. Must be authorization_code or refresh_token for client id ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid grant_type provided. Must be authorization_code or refresh_token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // We handle the initial and refresh tokens the same way
        if ($grant_type === 'refresh_token') {
            $code = $refresh_token;
        }

        try {
            $accessToken = $this->accessTokenMapper->getByCode($code);
        } catch (AccessTokenNotFoundException $e) {
            $this->logger->info('Could not find access token for code or refresh_token for client id ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Could not find access token for code or refresh_token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $client = $this->clientMapper->getByUid($accessToken->getClientId());
        } catch (ClientNotFoundException $e) {
            $this->logger->error('Could not find client for access token. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Could not find client for access token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!isset($client_id)) {
            $this->logger->debug('No client_id in request. Trying to fetch from Authorization Header.');
            if (isset($this->request->server['PHP_AUTH_USER'])) {
                $client_id = $this->request->server['PHP_AUTH_USER'];
                $client_secret = $this->request->server['PHP_AUTH_PW'];
            }
            if (!isset($client_id)) {
                $this->logger->debug('No client_id in PHP_AUTH_USER superglobal. Trying to fetch from Authorization Header directly.');
                $authHeader = $this->getAuthorizationHeader();
                if ($authHeader && stripos($authHeader, 'Basic ') === 0) {
                    $base64 = substr($authHeader, 6);
                    $decoded = base64_decode($base64, true);
                    if ($decoded !== false && strpos($decoded, ':') !== false) {
                        list($client_id, $client_secret) = explode(':', $decoded, 2);
                    }
                } else {
                    $this->logger->debug('No Authorization Header with client_id found.');
                }
            }
        }

        if ($client->getType() === 'public') {
            // Only the client id must match for a public client. Else we don't provide an access token!
            if ($client->getClientIdentifier() !== $client_id) {
                $this->logger->info('Client not found. Client id was ' . $client_id . '.');
                return new JSONResponse([
                    'error' => 'invalid_client',
                    'error_description' => 'Client not found.',
                ], Http::STATUS_BAD_REQUEST);
            }
        } else {
            // The client id and secret must match. Else we don't provide an access token!
            if ($client->getClientIdentifier() !== $client_id || $client->getSecret() !== $client_secret) {
                $this->logger->error('Client authentication failed. Client id was ' . $client_id . '.');
                return new JSONResponse([
                    'error' => 'invalid_client',
                    'error_description' => 'Client authentication failed.',
                ], Http::STATUS_BAD_REQUEST);
            }
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
                return new JSONResponse([
                    'error' => 'invalid_grant',
                    'error_description' => 'Access token already expired.',
                ], Http::STATUS_BAD_REQUEST);
            }

            // PKCE verification (RFC 7636 Section 4.6)
            $storedCodeChallenge = $accessToken->getCodeChallenge();
            if (!empty($storedCodeChallenge)) {
                // PKCE was used in authorization request, code_verifier is required
                if (empty($code_verifier)) {
                    $this->accessTokenMapper->delete($accessToken);
                    $this->logger->info('Missing code_verifier for PKCE-protected token. Client id: ' . $client_id);
                    return new JSONResponse([
                        'error' => 'invalid_grant',
                        'error_description' => 'code_verifier required for PKCE flow.',
                    ], Http::STATUS_BAD_REQUEST);
                }

                $storedCodeChallengeMethod = $accessToken->getCodeChallengeMethod() ?: 'S256';
                if (!$this->verifyPkce($code_verifier, $storedCodeChallenge, $storedCodeChallengeMethod)) {
                    $this->accessTokenMapper->delete($accessToken);
                    $this->logger->info('PKCE verification failed. Client id: ' . $client_id);
                    return new JSONResponse([
                        'error' => 'invalid_grant',
                        'error_description' => 'Invalid code_verifier.',
                    ], Http::STATUS_BAD_REQUEST);
                }

                $this->logger->debug('PKCE verification successful for client ' . $client_id);
            }
        } elseif ($refreshExpireTime !== 'never') {
            // The refresh token must not be expired
            $refreshExpireTime = (int)$refreshExpireTime;
            if ($this->time->getTime() > $accessToken->getRefreshed() + $refreshExpireTime) {
                $this->accessTokenMapper->delete($accessToken);
                $this->logger->info('Refresh token is expired. Client id: ' . $client_id . '.');
                return new JSONResponse([
                    'error' => 'invalid_grant',
                    'error_description' => 'Refresh token is expired.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        $newCode = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken->setHashedCode(hash('sha512', $newCode));
        $accessToken->setRefreshed($this->time->getTime() + $expireTime);

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
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Access token not allowed for user groups.',
            ], Http::STATUS_BAD_REQUEST);
        }
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

        $jwt = $this->jwtGenerator->generateIdToken($accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost(), false);

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
