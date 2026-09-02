<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
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
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\RegistrationTokenService;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
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
    /** @var RegistrationTokenService */
    private $registrationTokenService;
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
                    RegistrationTokenService $registrationTokenService,
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
        $this->registrationTokenService = $registrationTokenService;
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
        string|null $scope = null,
        string $token_type = 'opaque',
        string|null $resource_url = null,
        string|null $backchannel_logout_uri = null,
        bool $backchannel_logout_session_required = false,
        string|null $frontchannel_logout_uri = null,
        bool $frontchannel_logout_session_required = false,
        array|null $post_logout_redirect_uris = null,
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

        if (!in_array($id_token_signed_response_alg, ['RS256', 'HS256'], true)) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Only RS256 and HS256 are supported for id_token_signed_response_alg.',
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

        // Honor client's requested token type from DCR, fall back to server default if not specified or invalid
        $accessTokenType = $token_type;

        // Validate token_type - only accept internal lowercase values: 'opaque' or 'jwt'
        // Fall back to server default if invalid
        if (!in_array($accessTokenType, ['opaque', 'jwt'], true)) {
            $accessTokenType = $this->appConfig->getAppValueString(
                Application::APP_CONFIG_DEFAULT_TOKEN_TYPE,
                Application::DEFAULT_TOKEN_TYPE
            );
        }

        $client = new Client(
            $name,
            $redirect_uris,
            $id_token_signed_response_alg,
            'confidential',  // type
            'code',          // flowType
            $accessTokenType // Use client's requested token type (or server default if invalid)
        );

        $client->setDcr(true);

        if ($backchannel_logout_uri !== null) {
            $backchannel_logout_uri = trim($backchannel_logout_uri);
            if (!BackChannelLogoutService::isAllowedDynamicBackChannelLogoutUri($backchannel_logout_uri, $client->getType())) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'backchannel_logout_uri must be an absolute HTTPS URI to a publicly routable host without a fragment. HTTP, local, private, link-local, shared-address-space, and cloud-metadata targets are not allowed for dynamically registered clients.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setBackchannelLogoutUri($backchannel_logout_uri);
        }
        if ($backchannel_logout_session_required && $client->getBackchannelLogoutUri() === null) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'backchannel_logout_session_required requires backchannel_logout_uri.',
            ], Http::STATUS_BAD_REQUEST);
        }
        $client->setBackchannelLogoutSessionRequired($backchannel_logout_session_required);

        if ($frontchannel_logout_uri !== null) {
            $frontchannel_logout_uri = trim($frontchannel_logout_uri);
            if (!FrontChannelLogoutService::isValidForClientType($frontchannel_logout_uri, $client->getType())) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'frontchannel_logout_uri must be an absolute HTTPS URI without a fragment (HTTP is allowed only for confidential clients).',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setFrontchannelLogoutUri($frontchannel_logout_uri);
        }
        if ($frontchannel_logout_session_required && $client->getFrontchannelLogoutUri() === null) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'frontchannel_logout_session_required requires frontchannel_logout_uri.',
            ], Http::STATUS_BAD_REQUEST);
        }
        $client->setFrontchannelLogoutSessionRequired($frontchannel_logout_session_required);

        $normalizedPostLogoutRedirectUris = null;
        if ($post_logout_redirect_uris !== null) {
            $normalizedPostLogoutRedirectUris = $this->normalizePostLogoutRedirectUris($post_logout_redirect_uris, $client->getType());
            if ($normalizedPostLogoutRedirectUris instanceof JSONResponse) {
                return $normalizedPostLogoutRedirectUris;
            }
        }

        // Validate and set scope if provided
        if ($scope !== null) {
            $scope = trim($scope);
            $scope = mb_substr($scope, 0, 512);  // Match database column size
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

        // Validate and set resource_url if provided (RFC 9728)
        if ($resource_url !== null) {
            $resource_url = trim($resource_url);
            // Enforce 512 character limit (matching database schema)
            if (mb_strlen($resource_url) > 512) {
                $this->logger->info('Resource URL exceeds 512 character limit during dynamic client registration.');
                return new JSONResponse([
                    'error' => 'invalid_resource_url',
                    'error_description' => 'Resource URL exceeds maximum length of 512 characters.',
                ], Http::STATUS_BAD_REQUEST);
            }
            // Validate it's a proper URL
            if (!filter_var($resource_url, FILTER_VALIDATE_URL)) {
                $this->logger->info('Invalid resource_url format during dynamic client registration: ' . $resource_url);
                return new JSONResponse([
                    'error' => 'invalid_resource_url',
                    'error_description' => 'Resource URL must be a valid URL (RFC 9728).',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setResourceUrl($resource_url);
            $this->logger->info('Client registered with resource_url: ' . $resource_url);
        }

        // Note: token_type parameter controls access token format (JWT vs Bearer/opaque)
        // Client's choice is honored above, with server default as fallback for invalid values

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
        if ($normalizedPostLogoutRedirectUris !== null) {
            $this->replacePostLogoutRedirectUris($client, $normalizedPostLogoutRedirectUris);
        }

        // Generate registration access token (RFC 7592)
        $registrationToken = $this->registrationTokenService->generateToken($client->getId());

        $jsonResponse = [
            'client_name' => $client->getName(),
            'client_id' => $client->getClientIdentifier(),
            'client_secret' => $client->getSecret(),
            'registration_access_token' => $registrationToken->getToken(),
            'registration_client_uri' => $this->urlGenerator->linkToRouteAbsolute(
                'oidc.DynamicRegistration.getClientConfiguration',
                ['clientId' => $client->getClientIdentifier()]
            ),
            'redirect_uris' => $redirect_uris,
            'token_endpoint_auth_method' => 'client_secret_post', // Force to use client secret post
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => $application_type,
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME),
            'scope' => $client->getAllowedScopes(),
            'token_type' => $client->getTokenType(),
            'backchannel_logout_uri' => $client->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $client->getBackchannelLogoutSessionRequired(),
            'post_logout_redirect_uris' => $this->getPostLogoutRedirectUris($client),
        ];

        if ($client->getFrontchannelLogoutUri() !== null) {
            $jsonResponse['frontchannel_logout_uri'] = $client->getFrontchannelLogoutUri();
            $jsonResponse['frontchannel_logout_session_required'] = $client->getFrontchannelLogoutSessionRequired();
        }

        // Include resource_url in response if it was provided
        if ($client->getResourceUrl() !== null) {
            $jsonResponse['resource_url'] = $client->getResourceUrl();
        }

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
     * Validate RP-Initiated Logout registration metadata. These are browser
     * redirect targets, not server-side callbacks, so the Back-Channel SSRF
     * policy does not apply. Matching at logout time is always exact.
     *
     * @return list<string>|JSONResponse
     */
    private function normalizePostLogoutRedirectUris(array $uris, string $clientType): array|JSONResponse {
        $normalized = [];
        foreach ($uris as $uri) {
            if (!is_string($uri)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'post_logout_redirect_uris must contain only URI strings.',
                ], Http::STATUS_BAD_REQUEST);
            }

            $uri = trim($uri);
            if ($uri === '' || strlen($uri) > 2000 || str_contains($uri, '*')) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'Each post_logout_redirect_uris value must be a concrete absolute URI of at most 2000 bytes.',
                ], Http::STATUS_BAD_REQUEST);
            }

            $parts = parse_url($uri);
            $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
            if (!is_string($scheme)
                || !preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', $scheme)
                || isset($parts['fragment'])
                || isset($parts['user'])
                || isset($parts['pass'])) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'Each post_logout_redirect_uris value must be an absolute URI without fragment or embedded credentials.',
                ], Http::STATUS_BAD_REQUEST);
            }

            $schemeLower = strtolower($scheme);
            if (in_array($schemeLower, ['javascript', 'data', 'file', 'vbscript'], true)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'The URI scheme used by post_logout_redirect_uris is not allowed.',
                ], Http::STATUS_BAD_REQUEST);
            }

            if (in_array($schemeLower, ['http', 'https'], true)) {
                if (!isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
                    return new JSONResponse([
                        'error' => 'invalid_client_metadata',
                        'error_description' => 'HTTP(S) post_logout_redirect_uris values require a host.',
                    ], Http::STATUS_BAD_REQUEST);
                }
                if ($schemeLower === 'http' && $clientType !== 'confidential') {
                    return new JSONResponse([
                        'error' => 'invalid_client_metadata',
                        'error_description' => 'HTTP post_logout_redirect_uris are allowed only for confidential clients.',
                    ], Http::STATUS_BAD_REQUEST);
                }
            } elseif ((!isset($parts['host']) || $parts['host'] === '') && (!isset($parts['path']) || $parts['path'] === '')) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'Custom-scheme post_logout_redirect_uris values require a host or path.',
                ], Http::STATUS_BAD_REQUEST);
            }

            if (!in_array($uri, $normalized, true)) {
                $normalized[] = $uri;
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    private function getPostLogoutRedirectUris(Client $client): array {
        $uris = [];
        foreach ($this->logoutRedirectUriMapper->getByClientId($client->getId()) as $redirectUri) {
            $uris[] = $redirectUri->getRedirectUri();
        }
        return $uris;
    }

    /** @param list<string> $uris */
    private function replacePostLogoutRedirectUris(Client $client, array $uris): void {
        $this->logoutRedirectUriMapper->deleteByClientId($client->getId());
        foreach ($uris as $uri) {
            $redirectUri = new LogoutRedirectUri();
            $redirectUri->setClientId($client->getId());
            $redirectUri->setRedirectUri($uri);
            $this->logoutRedirectUriMapper->insert($redirectUri);
        }
    }

    /**
     * Authenticate using registration access token (RFC 7592)
     * Validates Bearer token from Authorization header
     *
     * @return int|null Client ID if token is valid, null otherwise
     */
    private function authenticateWithRegistrationToken(): ?int
    {
        $authHeader = $this->request->getHeader('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            $this->logger->debug('Missing or invalid Authorization header for registration token');
            return null;
        }

        $token = substr($authHeader, 7);  // Remove "Bearer " prefix

        if (empty($token)) {
            $this->logger->debug('Empty Bearer token in Authorization header');
            return null;
        }

        return $this->registrationTokenService->validateToken($token);
    }

    /**
     * Authenticate and authorize client for management operations
     * Uses RFC 7592 registration_access_token (Bearer token)
     *
     * @param string $clientId The client ID from the URL
     * @return Client|JSONResponse Returns Client on success, JSONResponse on error
     */
    private function authenticateAndAuthorizeClientManagement(string $clientId)
    {
        $authenticatedClientId = $this->authenticateWithRegistrationToken();

        if ($authenticatedClientId === null) {
            $this->logSecurityEvent('client_config_auth_failed', $clientId, false);
            return new JSONResponse([
                'error' => 'invalid_token',
                'error_description' => 'Invalid or missing registration access token.'
            ], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $client = $this->clientMapper->getByUid($authenticatedClientId);
        } catch (\Exception $e) {
            $this->logger->error('Client not found for authenticated token', [
                'app' => 'oidc',
                'client_id_from_token' => $authenticatedClientId,
            ]);
            return new JSONResponse([
                'error' => 'invalid_token',
                'error_description' => 'Token does not correspond to a valid client.'
            ], Http::STATUS_UNAUTHORIZED);
        }

        if ($client->getClientIdentifier() !== $clientId) {
            $this->logger->warning('Client management failed: clientId mismatch', [
                'app' => 'oidc',
                'requested_client' => $clientId,
                'authenticated_client' => $client->getClientIdentifier(),
            ]);
            return new JSONResponse([
                'error' => 'unauthorized_client',
                'error_description' => 'Token does not match the requested client.'
            ], Http::STATUS_FORBIDDEN);
        }

        if (!$client->getDcr()) {
            $this->logger->warning('Client management failed: not a DCR client', [
                'app' => 'oidc',
                'client_id' => $clientId,
            ]);
            return new JSONResponse([
                'error' => 'invalid_client',
                'error_description' => 'Only dynamically registered clients can be managed.'
            ], Http::STATUS_FORBIDDEN);
        }

        $this->logSecurityEvent('client_config_access', $clientId, true);
        return $client;
    }

    /**
     * Log security events for audit trail
     *
     * @param string $event The event type
     * @param string $clientId The client identifier
     * @param bool $success Whether the event was successful
     */
    private function logSecurityEvent(string $event, string $clientId, bool $success): void
    {
        $this->logger->info("DCR: $event", [
            'app' => 'oidc',
            'client_id' => $clientId,
            'success' => $success,
            'ip' => $this->request->getRemoteAddress(),
            'user_agent' => $this->request->getHeader('User-Agent'),
        ]);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_client_config)
     *
     * @param string $clientId The client identifier
     * @return JSONResponse
     */
    #[BruteForceProtection(action: 'oidc_client_config')]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getClientConfiguration(string $clientId): JSONResponse
    {
        $client = $this->authenticateAndAuthorizeClientManagement($clientId);
        if ($client instanceof JSONResponse) {
            $client->throttle(['clientId' => $clientId]);
            return $client;
        }

        // Get redirect URIs
        $redirectUris = [];
        foreach ($this->redirectUriMapper->getByClientId($client->getId()) as $redirectUri) {
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
            'registration_client_uri' => $this->urlGenerator->linkToRouteAbsolute(
                'oidc.DynamicRegistration.getClientConfiguration',
                ['clientId' => $client->getClientIdentifier()]
            ),
            'client_name' => $client->getName(),
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => 'client_secret_post',
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => 'web',
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME),
            'scope' => $client->getAllowedScopes(),
            'backchannel_logout_uri' => $client->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $client->getBackchannelLogoutSessionRequired(),
            'post_logout_redirect_uris' => $this->getPostLogoutRedirectUris($client),
        ];

        if ($client->getFrontchannelLogoutUri() !== null) {
            $jsonResponse['frontchannel_logout_uri'] = $client->getFrontchannelLogoutUri();
            $jsonResponse['frontchannel_logout_session_required'] = $client->getFrontchannelLogoutSessionRequired();
        }

        $response = new JSONResponse($jsonResponse);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');

        return $response;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_client_config)
     *
     * @param string $clientId The client identifier
     * @param array|null $redirect_uris Updated redirect URIs
     * @param string|null $client_name Updated client name
     * @param string|null $id_token_signed_response_alg Updated signing algorithm
     * @param array|null $response_types Updated response types
     * @param string|null $scope Updated scope
     * @param string|null $client_id RFC 7592 body client identifier; required and must match the current client
     * @param string|null $client_secret Optional current client secret; when supplied it must match and is never overwritten
     * @return JSONResponse
     */
    #[BruteForceProtection(action: 'oidc_client_config')]
    #[NoCSRFRequired]
    #[PublicPage]
    public function updateClientConfiguration(
        string $clientId,
        array|null $redirect_uris = null,
        string|null $client_name = null,
        string|null $id_token_signed_response_alg = null,
        array|null $response_types = null,
        string|null $scope = null,
        string|null $backchannel_logout_uri = null,
        bool|null $backchannel_logout_session_required = null,
        string|null $frontchannel_logout_uri = null,
        bool|null $frontchannel_logout_session_required = null,
        array|null $post_logout_redirect_uris = null,
        string|null $client_id = null,
        string|null $client_secret = null
    ): JSONResponse {
        $client = $this->authenticateAndAuthorizeClientManagement($clientId);
        if ($client instanceof JSONResponse) {
            $client->throttle(['clientId' => $clientId]);
            return $client;
        }

        // RFC 7592 section 2.2 requires the update payload to contain the
        // currently issued client_id. If client_secret is included, it must
        // match the currently issued secret and must never be used to replace
        // the persisted credential. Validate these fields before changing any
        // client metadata.
        if ($client_id === null || !hash_equals($client->getClientIdentifier(), $client_id)) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'client_id is required and must match the currently issued client identifier.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($client_secret !== null) {
            $currentSecret = $client->getSecret();
            if (!is_string($currentSecret) || $currentSecret === '' || !hash_equals($currentSecret, $client_secret)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'client_secret, when supplied, must match the currently issued client secret.',
                ], Http::STATUS_BAD_REQUEST);
            }
        }

        // Update client properties if provided
        if ($client_name !== null) {
            $client->setName(substr($client_name, 0, 64));
        }

        if ($id_token_signed_response_alg !== null) {
            if (!in_array($id_token_signed_response_alg, ['RS256', 'HS256'], true)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'Only RS256 and HS256 are supported for id_token_signed_response_alg.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setSigningAlg($id_token_signed_response_alg);
        }

        if ($response_types !== null) {
            if (in_array('code', $response_types)) {
                $client->setFlowType('code');
            } elseif (in_array('id_token', $response_types)) {
                $client->setFlowType('code id_token');
            }
        }

        if ($backchannel_logout_uri !== null) {
            $backchannel_logout_uri = trim($backchannel_logout_uri);
            if ($backchannel_logout_uri === '') {
                $client->setBackchannelLogoutUri(null);
                $client->setBackchannelLogoutSessionRequired(false);
            } elseif (!BackChannelLogoutService::isAllowedDynamicBackChannelLogoutUri($backchannel_logout_uri, $client->getType())) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'backchannel_logout_uri must be an absolute HTTPS URI to a publicly routable host without a fragment. HTTP, local, private, link-local, shared-address-space, and cloud-metadata targets are not allowed for dynamically registered clients.',
                ], Http::STATUS_BAD_REQUEST);
            } else {
                $client->setBackchannelLogoutUri($backchannel_logout_uri);
            }
        }
        if ($backchannel_logout_session_required !== null) {
            $client->setBackchannelLogoutSessionRequired($backchannel_logout_session_required);
        }
        if ($client->getBackchannelLogoutSessionRequired() && $client->getBackchannelLogoutUri() === null) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'backchannel_logout_session_required requires backchannel_logout_uri.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($frontchannel_logout_uri !== null) {
            $frontchannel_logout_uri = trim($frontchannel_logout_uri);
            if ($frontchannel_logout_uri === '') {
                $client->setFrontchannelLogoutUri(null);
                $client->setFrontchannelLogoutSessionRequired(false);
            } elseif (!FrontChannelLogoutService::isValidForClientType($frontchannel_logout_uri, $client->getType())) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'frontchannel_logout_uri must be an absolute HTTPS URI without a fragment (HTTP is allowed only for confidential clients).',
                ], Http::STATUS_BAD_REQUEST);
            } else {
                $client->setFrontchannelLogoutUri($frontchannel_logout_uri);
            }
        }
        if ($frontchannel_logout_session_required !== null) {
            $client->setFrontchannelLogoutSessionRequired($frontchannel_logout_session_required);
        }
        if ($client->getFrontchannelLogoutSessionRequired() && $client->getFrontchannelLogoutUri() === null) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'frontchannel_logout_session_required requires frontchannel_logout_uri.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $normalizedPostLogoutRedirectUris = null;
        if ($post_logout_redirect_uris !== null) {
            $normalizedPostLogoutRedirectUris = $this->normalizePostLogoutRedirectUris($post_logout_redirect_uris, $client->getType());
            if ($normalizedPostLogoutRedirectUris instanceof JSONResponse) {
                return $normalizedPostLogoutRedirectUris;
            }
        }

        // Validate and set scope if provided
        if ($scope !== null) {
            $scope = trim($scope);
            $scope = mb_substr($scope, 0, 512);  // Match database column size
            // RFC 6749 allows most printable ASCII except space (used as separator), backslash, and double-quote
            // Commonly used characters: letters, numbers, underscore, hyphen, colon, period, forward slash
            if (!preg_match('/^[a-zA-Z0-9 _:\.\/-]*$/u', $scope)) {
                $this->logger->info('Invalid scope characters during client configuration update.');
                return new JSONResponse([
                    'error' => 'invalid_scope',
                    'error_description' => 'Scope contains invalid characters. Allowed: alphanumeric, spaces, underscores, hyphens, colons, periods, and forward slashes.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setAllowedScopes($scope);
        }

        // Update redirect URIs if provided
        if ($redirect_uris !== null && !empty($redirect_uris)) {
            // Delete existing redirect URIs
            foreach ($this->redirectUriMapper->getByClientId($client->getId()) as $redirectUri) {
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

        if ($normalizedPostLogoutRedirectUris !== null) {
            // RFC 7592 update semantics for this metadata member: an empty
            // array clears RP-specific entries; omission leaves them unchanged.
            $this->replacePostLogoutRedirectUris($client, $normalizedPostLogoutRedirectUris);
        }

        $this->clientMapper->update($client);

        // Rotate registration access token on update (RFC 7592)
        $newToken = $this->registrationTokenService->rotateToken($client->getId());

        // Get current redirect URIs for response
        $currentRedirectUris = [];
        foreach ($this->redirectUriMapper->getByClientId($client->getId()) as $redirectUri) {
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
            'registration_access_token' => $newToken->getToken(),
            'registration_client_uri' => $this->urlGenerator->linkToRouteAbsolute(
                'oidc.DynamicRegistration.getClientConfiguration',
                ['clientId' => $client->getClientIdentifier()]
            ),
            'client_name' => $client->getName(),
            'redirect_uris' => $currentRedirectUris,
            'token_endpoint_auth_method' => 'client_secret_post',
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => 'web',
            'client_id_issued_at' => $client->getIssuedAt(),
            'client_secret_expires_at' => $client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME),
            'scope' => $client->getAllowedScopes(),
            'backchannel_logout_uri' => $client->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $client->getBackchannelLogoutSessionRequired(),
            'post_logout_redirect_uris' => $this->getPostLogoutRedirectUris($client),
        ];

        if ($client->getFrontchannelLogoutUri() !== null) {
            $jsonResponse['frontchannel_logout_uri'] = $client->getFrontchannelLogoutUri();
            $jsonResponse['frontchannel_logout_session_required'] = $client->getFrontchannelLogoutSessionRequired();
        }

        $response = new JSONResponse($jsonResponse);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'PUT');

        return $response;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=oidc_client_config)
     *
     * @param string $clientId The client identifier
     * @return JSONResponse
     */
    #[BruteForceProtection(action: 'oidc_client_config')]
    #[NoCSRFRequired]
    #[PublicPage]
    public function deleteClientConfiguration(string $clientId): JSONResponse
    {
        $client = $this->authenticateAndAuthorizeClientManagement($clientId);
        if ($client instanceof JSONResponse) {
            $client->throttle(['clientId' => $clientId]);
            return $client;
        }

        // Delete associated access tokens
        $this->accessTokenMapper->deleteByClientId($client->getId());

        // Delete associated redirect URIs
        $this->redirectUriMapper->deleteByClientId($client->getId());

        // Delete RP-specific post-logout redirect URIs. Legacy global entries
        // have client_id = NULL and are intentionally preserved.
        $this->logoutRedirectUriMapper->deleteByClientId($client->getId());

        // Delete the client
        $this->clientMapper->delete($client);

        $this->logger->info('Deleted DCR client: ' . $clientId);

        $response = new JSONResponse([], Http::STATUS_NO_CONTENT);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'DELETE');

        return $response;
    }

}
