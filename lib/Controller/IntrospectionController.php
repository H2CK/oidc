<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Chris Coutinho <chrisbcoutinho@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\AppFramework\Services\IAppConfig;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use Psr\Log\LoggerInterface;

class IntrospectionController extends ApiController
{
    /** @var ClientMapper */
    private $clientMapper;
    /** @var AccessTokenMapper */
    private $accessTokenMapper;
    /** @var IUserManager */
    private $userManager;
    /** @var ITimeFactory */
    private $time;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ClientMapper $clientMapper,
        AccessTokenMapper $accessTokenMapper,
        IUserManager $userManager,
        ITimeFactory $time,
        IAppConfig $appConfig,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->clientMapper = $clientMapper;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->userManager = $userManager;
        $this->time = $time;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * Authenticate client using either Authorization header (Basic) or POST body
     *
     * @return Client|null
     */
    private function authenticateClient(): ?Client
    {
        $clientId = null;
        $clientSecret = null;

        // Check for Authorization: Basic header
        $authHeader = $this->request->getHeader('Authorization');
        if ($authHeader && stripos($authHeader, 'Basic ') === 0) {
            $base64 = substr($authHeader, 6);
            $decoded = base64_decode($base64, true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                list($clientId, $clientSecret) = explode(':', $decoded, 2);
            }
        }

        // Fallback to POST body parameters
        if ($clientId === null) {
            $clientId = $this->request->getParam('client_id');
            $clientSecret = $this->request->getParam('client_secret');
        }

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        try {
            $client = $this->clientMapper->getByIdentifier($clientId);
            // Use constant-time comparison to prevent timing attacks
            if (hash_equals($client->getSecret(), $clientSecret)) {
                return $client;
            }
        } catch (\Exception $e) {
            $this->logger->debug('Client not found: ' . $clientId);
        }

        return null;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $token The token to introspect
     * @param string|null $token_type_hint Optional hint about the token type
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function introspectToken(
        string $token = '',
        string|null $token_type_hint = null
    ): JSONResponse {
        // Log introspection attempt
        $this->logger->info('Token introspection attempt received', [
            'token_hint' => $token_type_hint,
            'has_token' => !empty($token),
            'remote_addr' => $this->request->getRemoteAddress(),
            'user_agent' => $this->request->getHeader('User-Agent')
        ]);

        // Authenticate the client
        $client = $this->authenticateClient();
        if ($client === null) {
            $this->logger->warning('Token introspection failed: invalid client credentials', [
                'remote_addr' => $this->request->getRemoteAddress(),
                'attempted_client_id' => $this->request->getParam('client_id') ?? 'unknown'
            ]);
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.'
            ], Http::STATUS_UNAUTHORIZED);
        }

        $this->logger->info('Client authenticated for introspection', [
            'client_id' => $client->getClientIdentifier(),
            'client_name' => $client->getName()
        ]);

        // Validate token parameter
        if (empty($token)) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Token parameter is required.'
            ], Http::STATUS_BAD_REQUEST);
        }

        // Try to find the token
        try {
            $accessToken = $this->accessTokenMapper->getByAccessToken($token);
        } catch (\Exception $e) {
            // Token not found - return inactive
            $this->logger->info('Token not found during introspection', [
                'client_id' => $client->getClientIdentifier(),
                'token_prefix' => substr($token, 0, 8) . '...'
            ]);
            return new JSONResponse(['active' => false]);
        }

        // Check if token is expired
        $expireTime = (int)$this->appConfig->getAppValueString('expire_time', Application::DEFAULT_EXPIRE_TIME);
        $tokenExpiryTime = $accessToken->getCreated() + $expireTime;
        $currentTime = $this->time->getTime();

        if ($currentTime > $tokenExpiryTime) {
            $this->logger->info('Token expired during introspection', [
                'client_id' => $client->getClientIdentifier(),
                'token_created' => $accessToken->getCreated(),
                'token_expired_at' => $tokenExpiryTime,
                'current_time' => $currentTime
            ]);
            return new JSONResponse(['active' => false]);
        }

        // Get user information
        $user = $this->userManager->get($accessToken->getUserId());
        if ($user === null) {
            $this->logger->debug('User not found for token during introspection');
            return new JSONResponse(['active' => false]);
        }

        // Get client information
        try {
            $tokenClient = $this->clientMapper->getByUid($accessToken->getClientId());
        } catch (\Exception $e) {
            $this->logger->debug('Client not found for token during introspection');
            return new JSONResponse(['active' => false]);
        }

        // Authorization check: Only allow introspection if the requesting client
        // is the intended audience (resource server) for this token
        $tokenResource = $accessToken->getResource();
        $requestingClientId = $client->getClientIdentifier();

        // Allow introspection if:
        // 1. The requesting client matches the token's resource (intended audience)
        // 2. The requesting client owns the token (issued to them)
        $isAuthorized = false;

        if (!empty($tokenResource) && $tokenResource === $requestingClientId) {
            // Client is the intended resource server
            $isAuthorized = true;
            $this->logger->info(
                'Token introspection authorized: requesting client is token audience',
                [
                    'requesting_client' => $requestingClientId,
                    'token_resource' => $tokenResource,
                    'token_owner_client' => $tokenClient->getClientIdentifier()
                ]
            );
        } elseif ($tokenClient->getClientIdentifier() === $requestingClientId) {
            // Client owns the token
            $isAuthorized = true;
            $this->logger->info(
                'Token introspection authorized: requesting client owns the token',
                [
                    'requesting_client' => $requestingClientId,
                    'token_resource' => $tokenResource
                ]
            );
        }

        if (!$isAuthorized) {
            $this->logger->warning(
                'Token introspection denied: requesting client not authorized',
                [
                    'requesting_client' => $requestingClientId,
                    'token_resource' => $tokenResource,
                    'token_owner_client' => $tokenClient->getClientIdentifier(),
                    'user_id' => $accessToken->getUserId()
                ]
            );
            // Return inactive per RFC 7662 Section 2.2 - don't reveal token exists
            return new JSONResponse(['active' => false]);
        }

        // Build successful response
        $response = [
            'active' => true,
            'scope' => $accessToken->getScope(),
            'client_id' => $tokenClient->getClientIdentifier(),
            'username' => $user->getUID(),
            'token_type' => 'Bearer',
            'exp' => $tokenExpiryTime,
            'iat' => $accessToken->getCreated(),
            'sub' => $accessToken->getUserId(),
            'aud' => $tokenClient->getClientIdentifier()
        ];

        $this->logger->info('Token introspection successful', [
            'requesting_client' => $client->getClientIdentifier(),
            'token_owner_client' => $tokenClient->getClientIdentifier(),
            'user_id' => $accessToken->getUserId(),
            'scopes' => $accessToken->getScope(),
            'token_resource' => $tokenResource
        ]);

        $jsonResponse = new JSONResponse($response);
        $jsonResponse->addHeader('Access-Control-Allow-Origin', '*');
        $jsonResponse->addHeader('Access-Control-Allow-Methods', 'POST');

        return $jsonResponse;
    }
}
