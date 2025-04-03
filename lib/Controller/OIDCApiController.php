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

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_token)
     *
     * @param string $grant_type
     * @param string $code
     * @param string $refresh_token
     * @param string $client_id
     * @param string $client_secret
     * @return JSONResponse
     */
    #[BruteForceProtection(action: 'oidc_token')]
    #[PublicPage]
    #[NoCSRFRequired]
    public function getToken($grant_type, $code, $refresh_token, $client_id, $client_secret): JSONResponse
    {
        $expireTime = (int)$this->appConfig->getAppValue('expire_time', '0');
        $refreshExpireTime = $this->appConfig->getAppValue('refresh_expire_time', Application::DEFAULT_REFRESH_EXPIRE_TIME);
        // We only handle two types
        if ($grant_type !== 'authorization_code' && $grant_type !== 'refresh_token') {
            $this->logger->notice('Invalid grant_type provided. Must be authorization_code or refresh_token for client id ' . $client_id . '.');
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
            $this->logger->notice('Could not find access token for code or refresh_token for client id ' . $client_id . '.');
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

        if (isset($this->request->server['PHP_AUTH_USER'])) {
            $client_id = $this->request->server['PHP_AUTH_USER'];
            $client_secret = $this->request->server['PHP_AUTH_PW'];
        }

        if ($client->getType() === 'public') {
            // Only the client id must match for a public client. Else we don't provide an access token!
            if ($client->getClientIdentifier() !== $client_id) {
                $this->logger->notice('Client not found. Client id was ' . $client_id . '.');
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
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValue('client_expire_time', Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
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
                $this->logger->notice('Access token already expired. Client id was ' . $client_id . '.');
                return new JSONResponse([
                    'error' => 'invalid_grant',
                    'error_description' => 'Access token already expired.',
                ], Http::STATUS_BAD_REQUEST);
            }
        } elseif ($refreshExpireTime !== 'never') {
            // The refresh token must not be expired
            $refreshExpireTime = (int)$refreshExpireTime;
            if ($this->time->getTime() > $accessToken->getRefreshed() + $refreshExpireTime) {
                $this->accessTokenMapper->delete($accessToken);
                $this->logger->notice('Refresh token is expired. Client id: ' . $client_id . '.');
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
            $this->logger->notice('Access token used for allowed for user groups. Client id was ' . $client_id . '.');
            return new JSONResponse([
                'error' => 'invalid_grant',
                'error_description' => 'Access token not allowed for user groups.',
            ], Http::STATUS_BAD_REQUEST);
        }
        $accessToken->setAccessToken($this->jwtGenerator->generateAccessToken($accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost()));
        $this->accessTokenMapper->update($accessToken);

        $jwt = $this->jwtGenerator->generateIdToken($accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost(), false);

        $this->logger->info('Returned token for user ' . $uid);

        $responseData = [
            'access_token' => $accessToken->getAccessToken(),
            'token_type' => 'Bearer',
            'expires_in' => $expireTime,
            'refresh_token' => $newCode,
            'id_token' => $jwt,
        ];
        if ($refreshExpireTime !== 'never') {
            $responseData['refresh_expires_in'] = (int)$refreshExpireTime;
        }
        $response = new JSONResponse($responseData);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET, POST');

        return $response;
    }
}
