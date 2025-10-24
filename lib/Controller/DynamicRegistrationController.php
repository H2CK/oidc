<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OC\Security\Bruteforce\Throttler;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCP\Security\ISecureRandom;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use Psr\Log\LoggerInterface;

class DynamicRegistrationController extends ApiController
{
    /** @var ClientMapper */
    private $clientMapper;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var AccessTokenMapper  */
    private $accessTokenMapper;
    /** @var RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var LogoutRedirectUriMapper  */
    private $logoutRedirectUriMapper;
    /** @var ITimeFactory */
    private $time;
    /** @var Throttler */
    private $throttler;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

    public const VALID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    public const NAME_PREFIX = 'DCR-';

    public function __construct(
                    string $appName,
                    IRequest $request,
                    ClientMapper $clientMapper,
                    ISecureRandom $secureRandom,
                    AccessTokenMapper $accessTokenMapper,
                    RedirectUriMapper $redirectUriMapper,
                    LogoutRedirectUriMapper $logoutRedirectUriMapper,
                    ITimeFactory $time,
                    Throttler $throttler,
                    IURLGenerator $urlGenerator,
                    IAppConfig $appConfig,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->secureRandom = $secureRandom;
        $this->clientMapper = $clientMapper;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->logoutRedirectUriMapper = $logoutRedirectUriMapper;
        $this->time = $time;
        $this->throttler = $throttler;
        $this->urlGenerator = $urlGenerator;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_dcr)
     * @AnonRateThrottle(limit=10, period=60)
     *
     * @return JSONResponse
     */
    #[AnonRateLimit(limit: 10, period: 60)]
    #[BruteForceProtection(action: 'oidc_dcr')]
    #[NoCSRFRequired]
    #[PublicPage]
    public function registerClient(
        array|null $redirect_uris = null,
        string|null $client_name = null,
        string $id_token_signed_response_alg = 'RS256',
        array $response_types = ['code'],
        string $application_type = 'web',
        ): JSONResponse
    {
        if ($this->appConfig->getAppValueString('dynamic_client_registration', 'false') != 'true') {
            $this->logger->info('Access to register dynamic client, but functionality disabled.');
            return new JSONResponse([
                'error' => 'dynamic_registration_not_allowed',
                'error_description' => 'Dynamic Client Registration is disabled.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($redirect_uris == null) {
            $this->logger->info('No redirect uris provided during register dynamic client.');
            return new JSONResponse([
                'error' => 'no_redirect_uris_provided',
                'error_description' => 'Dynamic Client Registration requires redirect_uris to be set.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!is_array($redirect_uris)) {
            $this->logger->info('No redirect uris array delivered.');
            return new JSONResponse([
                'error' => 'no_redirect_uris_provided',
                'error_description' => 'Dynamic Client Registration requires redirect_uris to be set.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (empty($redirect_uris)) {
            $this->logger->info('No redirect uris array delivered.');
            return new JSONResponse([
                'error' => 'no_redirect_uris_provided',
                'error_description' => 'Dynamic Client Registration requires at least one redirect_uris to be set.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($application_type == 'native') {
            $application_type = 'native';
        } else {
            $application_type = 'web';
        }

        $this->clientMapper->cleanUp();

        if ($this->clientMapper->getNumDcrClients() > 100) {
            $this->logger->info('Maximum number of dynamic registered clients exceeded.');
            return new JSONResponse([
                'error' => 'max_num_clients_exceeded',
                'error_description' => 'Maximum number of dynamic registered clients exceeded.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $name = self::NAME_PREFIX . $this->getClientIp();
        if ($client_name != null) {
            $name = substr($client_name, 0, 64);
        }

        $client = new Client(
            $name,
            $redirect_uris,
            $id_token_signed_response_alg,
        );

        $client->setDcr(true);

        // Validate and set scope if provided
        if ($scope !== null) {
            $scope = trim($scope);
            $scope = mb_substr($scope, 0, 511);
            // RFC 6749 allows most printable ASCII except space (used as separator), backslash, and double-quote
            // Commonly used characters: letters, numbers, underscore, hyphen, colon, period, forward slash
            if (!preg_match('/^[a-zA-Z0-9 _:\.\/-]*$/u', $scope)) {
                $this->logger->info('Invalid scope characters during dynamic client registration.');
                return new JSONResponse([
                    'error' => 'invalid_scope',
                    'error_description' => 'Scope contains invalid characters. Allowed: alphanumeric, spaces, underscores, hyphens, colons, periods, and forward slashes.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setAllowedScopes($scope);
        }

        // Validate and set access token type if provided
        // The $token_type parameter here is legacy and refers to the OAuth2 token_type (Bearer)
        // not the access token format (jwt vs opaque), so we ignore it
        // Access token format is always the configured default for DCR clients

        $response_types_arr = array();
        array_push($response_types_arr, 'code');
        $grant_types_arr = array();
        array_push($grant_types_arr, 'authorization_code');
        if (in_array('code', $response_types)) {
            $client->setFlowType('code');
        } elseif (in_array('id_token', $response_types)){
            $client->setFlowType('code id_token');
            array_push($response_types_arr, 'id_token');
            array_push($grant_types_arr, 'implicit');
        }

        $client = $this->clientMapper->insert($client);

        $jsonResponse = [
            'client_name' => $client->getName(),
            'client_id' => $client->getClientIdentifier(),
            'client_secret' => $client->getSecret(),
            'redirect_uris' => $redirect_uris,
            'token_endpoint_auth_method' => 'client_secret_post', // Force to use client secret post
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => $application_type,
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + $this->appConfig->getAppValueString('client_expire_time', Application::DEFAULT_CLIENT_EXPIRE_TIME)
        ];

        $response = new JSONResponse($jsonResponse, Http::STATUS_CREATED);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'POST');

        return $response;
    }

    private function getClientIp() {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? $_SERVER['HTTP_CLIENT_IP']
            ?? $this->secureRandom->generate(64, self::VALID_CHARS);
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
     * Authenticate and authorize client for management operations
     *
     * @param string $clientId The client ID from the URL
     * @return Client|JSONResponse Returns Client on success, JSONResponse on error
     */
    private function authenticateAndAuthorizeClientManagement(string $clientId)
    {
        $authenticatedClient = $this->authenticateClient();
        if ($authenticatedClient === null) {
            $this->logger->info('Client management failed: invalid credentials');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.'
            ], Http::STATUS_UNAUTHORIZED);
        }

        if ($authenticatedClient->getClientIdentifier() !== $clientId) {
            $this->logger->info('Client management failed: clientId mismatch');
            return new JSONResponse([
                'error' => 'unauthorized_client',
                'error_description' => 'Client can only manage itself.'
            ], Http::STATUS_FORBIDDEN);
        }

        if (!$authenticatedClient->getDcr()) {
            $this->logger->info('Client management failed: not a DCR client');
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Only dynamically registered clients can be managed.'
            ], Http::STATUS_FORBIDDEN);
        }

        return $authenticatedClient;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $clientId The client identifier
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function getClientConfiguration(string $clientId): JSONResponse
    {
        $client = $this->authenticateAndAuthorizeClientManagement($clientId);
        if ($client instanceof JSONResponse) {
            return $client;
        }

        // Get redirect URIs
        $redirectUris = [];
        foreach ($this->redirectUriMapper->findByClientId($client->getId()) as $redirectUri) {
            $redirectUris[] = $redirectUri->getRedirectUri();
        }

        $response_types_arr = ['code'];
        $grant_types_arr = ['authorization_code'];
        if ($client->getFlowType() === 'code id_token') {
            array_push($response_types_arr, 'id_token');
            array_push($grant_types_arr, 'implicit');
        }

        $jsonResponse = [
            'client_id' => $client->getClientIdentifier(),
            'client_secret' => $client->getSecret(),
            'client_name' => $client->getName(),
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => 'client_secret_post',
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => 'web',
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + $this->appConfig->getAppValueString('client_expire_time', Application::DEFAULT_CLIENT_EXPIRE_TIME),
            'scope' => $client->getAllowedScopes()
        ];

        $response = new JSONResponse($jsonResponse);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');

        return $response;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $clientId The client identifier
     * @param array|null $redirect_uris Updated redirect URIs
     * @param string|null $client_name Updated client name
     * @param string|null $id_token_signed_response_alg Updated signing algorithm
     * @param array|null $response_types Updated response types
     * @param string|null $scope Updated scope
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function updateClientConfiguration(
        string $clientId,
        array|null $redirect_uris = null,
        string|null $client_name = null,
        string|null $id_token_signed_response_alg = null,
        array|null $response_types = null,
        string|null $scope = null
    ): JSONResponse {
        $client = $this->authenticateAndAuthorizeClientManagement($clientId);
        if ($client instanceof JSONResponse) {
            return $client;
        }

        // Update client properties if provided
        if ($client_name !== null) {
            $client->setName(substr($client_name, 0, 64));
        }

        if ($id_token_signed_response_alg !== null) {
            $client->setSigningAlg($id_token_signed_response_alg);
        }

        if ($response_types !== null) {
            if (in_array('code', $response_types)) {
                $client->setFlowType('code');
            } elseif (in_array('id_token', $response_types)) {
                $client->setFlowType('code id_token');
            }
        }

        // Validate and set scope if provided
        if ($scope !== null) {
            $scope = trim($scope);
            $scope = mb_substr($scope, 0, 511);
            if (!preg_match('/^[a-zA-Z0-9 _-]*$/u', $scope)) {
                $this->logger->info('Invalid scope characters during client configuration update.');
                return new JSONResponse([
                    'error' => 'invalid_scope',
                    'error_description' => 'Scope contains invalid characters. Only alphanumeric characters, spaces, underscores, and hyphens are allowed.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setAllowedScopes($scope);
        }

        // Update redirect URIs if provided
        if ($redirect_uris !== null && !empty($redirect_uris)) {
            // Delete existing redirect URIs
            foreach ($this->redirectUriMapper->findByClientId($client->getId()) as $redirectUri) {
                $this->redirectUriMapper->delete($redirectUri);
            }

            // Add new redirect URIs
            foreach ($redirect_uris as $uri) {
                $redirectUri = new \OCA\OIDCIdentityProvider\Db\RedirectUri();
                $redirectUri->setClientId($client->getId());
                $redirectUri->setRedirectUri($uri);
                $this->redirectUriMapper->insert($redirectUri);
            }
        }

        $this->clientMapper->update($client);

        // Get current redirect URIs for response
        $currentRedirectUris = [];
        foreach ($this->redirectUriMapper->findByClientId($client->getId()) as $redirectUri) {
            $currentRedirectUris[] = $redirectUri->getRedirectUri();
        }

        $response_types_arr = ['code'];
        $grant_types_arr = ['authorization_code'];
        if ($client->getFlowType() === 'code id_token') {
            array_push($response_types_arr, 'id_token');
            array_push($grant_types_arr, 'implicit');
        }

        $jsonResponse = [
            'client_id' => $client->getClientIdentifier(),
            'client_secret' => $client->getSecret(),
            'client_name' => $client->getName(),
            'redirect_uris' => $currentRedirectUris,
            'token_endpoint_auth_method' => 'client_secret_post',
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => 'web',
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + $this->appConfig->getAppValueString('client_expire_time', Application::DEFAULT_CLIENT_EXPIRE_TIME),
            'scope' => $client->getAllowedScopes()
        ];

        $response = new JSONResponse($jsonResponse);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'PUT');

        return $response;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     *
     * @param string $clientId The client identifier
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function deleteClientConfiguration(string $clientId): JSONResponse
    {
        $client = $this->authenticateAndAuthorizeClientManagement($clientId);
        if ($client instanceof JSONResponse) {
            return $client;
        }

        // Delete associated access tokens
        $this->accessTokenMapper->deleteByClientId($client->getId());

        // Delete associated redirect URIs
        foreach ($this->redirectUriMapper->findByClientId($client->getId()) as $redirectUri) {
            $this->redirectUriMapper->delete($redirectUri);
        }

        // Delete associated logout redirect URIs
        foreach ($this->logoutRedirectUriMapper->findByClientId($client->getId()) as $logoutUri) {
            $this->logoutRedirectUriMapper->delete($logoutUri);
        }

        // Delete the client
        $this->clientMapper->delete($client);

        $this->logger->info('Deleted DCR client: ' . $clientId);

        $response = new JSONResponse([], Http::STATUS_NO_CONTENT);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'DELETE');

        return $response;
    }

}
