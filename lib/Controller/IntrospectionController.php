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
            if ($client->getSecret() === $clientSecret) {
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
        // Authenticate the client
        $client = $this->authenticateClient();
        if ($client === null) {
            $this->logger->info('Token introspection failed: invalid client credentials');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.'
            ], Http::STATUS_UNAUTHORIZED);
        }

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
            $this->logger->debug('Token not found during introspection');
            return new JSONResponse(['active' => false]);
        }

        // Check if token is expired
        $expireTime = (int)$this->appConfig->getAppValueString('expire_time', Application::DEFAULT_EXPIRE_TIME);
        $tokenExpiryTime = $accessToken->getCreated() + $expireTime;
        $currentTime = $this->time->getTime();

        if ($currentTime > $tokenExpiryTime) {
            $this->logger->debug('Token expired during introspection');
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

        $this->logger->info('Token introspection successful for client: ' . $client->getClientIdentifier());

        $jsonResponse = new JSONResponse($response);
        $jsonResponse->addHeader('Access-Control-Allow-Origin', '*');
        $jsonResponse->addHeader('Access-Control-Allow-Methods', 'POST');

        return $jsonResponse;
    }
}
