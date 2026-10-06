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
use OCA\OIDCIdentityProvider\Service\ResourcePolicyService;
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
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;
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
    private RedirectUriService $redirectUriService;

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
                    LoggerInterface $logger,
                    ?RedirectUriService $redirectUriService = null
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
        $this->redirectUriService = $redirectUriService ?? new RedirectUriService($logger);
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
        ?string $token_type = null,
        string|null $resource_url = null,
        string|null $backchannel_logout_uri = null,
        bool $backchannel_logout_session_required = false,
        string|null $frontchannel_logout_uri = null,
        bool $frontchannel_logout_session_required = false,
        array|null $post_logout_redirect_uris = null,
        string|null $token_endpoint_auth_method = null,
        ?array $grant_types = null,
        ): JSONResponse
    {
        if ($this->appConfig->getAppValueString('dynamic_client_registration', 'false') != 'true') {
            $this->logger->info('Access to register dynamic client, but functionality disabled.');
            return new JSONResponse([
                'error' => 'dynamic_registration_not_allowed',
                'error_description' => 'Dynamic Client Registration is disabled.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $metadata = $this->normalizeFlowMetadata($response_types, $grant_types ?? ['authorization_code']);
        if ($metadata instanceof JSONResponse) {
            return $metadata;
        }
        [$response_types_arr, $grant_types_arr] = $metadata;
        if ($response_types_arr !== [] && empty($redirect_uris)) {
            return $this->invalidFlowMetadata('redirect_uris are required for browser authorization flows.');
        }
        $redirect_uris ??= [];

        if (!in_array($id_token_signed_response_alg, ['RS256', 'HS256'], true)) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Only RS256 and HS256 are supported for id_token_signed_response_alg.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!in_array($application_type, ['web', 'native'], true)) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'application_type must be web or native.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $redirectUrisOrError = $this->normalizeDynamicRedirectUris($redirect_uris);
        if ($redirectUrisOrError instanceof JSONResponse) {
            return $redirectUrisOrError;
        }
        $redirect_uris = $redirectUrisOrError;

        $clientSecretBasicDisabled = $this->appConfig->getAppValueBool(
            Application::APP_CONFIG_DISABLE_AUTH_CLIENT_SECRET_BASIC,
            false
        );
        if ($token_endpoint_auth_method === null || trim($token_endpoint_auth_method) === '') {
            $token_endpoint_auth_method = $application_type === 'native'
                ? 'none'
                : ($clientSecretBasicDisabled ? 'client_secret_post' : 'client_secret_basic');
        }
        if (!in_array($token_endpoint_auth_method, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Unsupported token_endpoint_auth_method.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($application_type === 'native' && $token_endpoint_auth_method !== 'none') {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Native clients must use token_endpoint_auth_method none.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($clientSecretBasicDisabled && $token_endpoint_auth_method === 'client_secret_basic') {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'client_secret_basic is disabled by the authorization server.',
            ], Http::STATUS_BAD_REQUEST);
        }
        if ($token_endpoint_auth_method === 'none' && $id_token_signed_response_alg === 'HS256') {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Public clients cannot use HS256 ID tokens because no client secret is issued.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $redirectError = $this->validateDynamicRedirectPolicy($redirect_uris, $application_type,
            $token_endpoint_auth_method === 'none' ? 'public' : 'confidential', $grant_types_arr);
        if ($redirectError !== null) {
            return $redirectError;
        }
        $this->clientMapper->cleanUp();

        if ($this->clientMapper->getNumDcrClients() >= 100) {
            $this->logger->info('Maximum number of dynamic registered clients exceeded.');
            return new JSONResponse([
                'error' => 'max_num_clients_exceeded',
                'error_description' => 'Maximum number of dynamic registered clients exceeded.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $name = substr(self::NAME_PREFIX . $this->getClientIp(), 0, 64);
        if ($client_name != null) {
            if (!mb_check_encoding($client_name, 'UTF-8')) {
                return $this->invalidFlowMetadata('client_name must be valid UTF-8.');
            }
            $name = mb_substr($client_name, 0, 64, 'UTF-8');
        }

        // Honor client's requested token type from DCR, fall back to server default if not specified or invalid
        $accessTokenType = $token_type;

        // Use the configured server default when the client omits token_type.
        // Normalize values before validation because the admin UI may expose
        // the value as "JWT" while the internal value is "jwt".
        if ($token_type === null || trim($token_type) === '') {
            $accessTokenType = $this->appConfig->getAppValueString(
                Application::APP_CONFIG_DEFAULT_TOKEN_TYPE,
                Application::DEFAULT_TOKEN_TYPE
            );
        } else {
            $accessTokenType = $token_type;
        }

        $accessTokenType = strtolower(trim($accessTokenType));

        // Accept only the internal values: 'opaque' or 'jwt'.
        if (!in_array($accessTokenType, ['opaque', 'jwt'], true)) {
            $accessTokenType = Application::DEFAULT_TOKEN_TYPE;
        }

        $clientType = $token_endpoint_auth_method === 'none' ? 'public' : 'confidential';
        $client = new Client(
            $name,
            $redirect_uris,
            $id_token_signed_response_alg,
            $clientType,
            'code',          // flowType
            $accessTokenType // Use client's requested token type (or server default if invalid)
        );

        $client->setDcr(true);
        $client->setApplicationType($application_type);
        $client->setTokenEndpointAuthMethod($token_endpoint_auth_method);

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
            if (!FrontChannelLogoutService::isValidForRedirectUris($frontchannel_logout_uri, $client->getType(), $redirect_uris)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'frontchannel_logout_uri must be an allowed absolute HTTP(S) URI whose scheme, host, and effective port match one of redirect_uris.',
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
            if (strlen($scope) > 512) {
                return new JSONResponse(['error' => 'invalid_scope', 'error_description' => 'Scope exceeds 512 bytes.'], Http::STATUS_BAD_REQUEST);
            }
            // RFC 6749 scope-token: printable ASCII except DQUOTE and
            // backslash, with one SP separating non-empty tokens.
            if (!preg_match('/^(?:[\x21\x23-\x5B\x5D-\x7E]+(?: [\x21\x23-\x5B\x5D-\x7E]+)*)?$/D', $scope)) {
                $this->logger->info('Invalid scope characters during dynamic client registration.');
                return new JSONResponse([
                    'error' => 'invalid_scope',
                    'error_description' => 'Scope must be a space-delimited list of RFC 6749 scope-token values.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setAllowedScopes($scope);
        }

        // Validate and set resource_url if provided (RFC 9728)
        if ($resource_url !== null) {

            // Enforce 512 character limit (matching database schema)
            if (strlen($resource_url) > 512) {
                $this->logger->info('Resource URL exceeds 512 character limit during dynamic client registration.');
                return new JSONResponse([
                    'error' => 'invalid_resource_url',
                    'error_description' => 'Resource URL exceeds maximum length of 512 characters.',
                ], Http::STATUS_BAD_REQUEST);
            }
            // Validate it's a proper URL
            if (!ResourcePolicyService::isValid($resource_url, 512)) {
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

        $client->setRegisteredResponseTypes($response_types_arr);
        $client->setRegisteredGrantTypes($grant_types_arr);
        $client->setFlowType(implode(' ', array_values(array_unique(array_merge(...array_map(
            static fn (string $response): array => explode(' ', $response), $response_types_arr ?: ['']
        ))))));

        try {
            $client = $this->clientMapper->insertDynamicClient($client);
        } catch (\OCA\OIDCIdentityProvider\Exceptions\DynamicClientQuotaException) {
            return new JSONResponse(['error' => 'max_num_clients_exceeded',
                'error_description' => 'Maximum number of dynamically registered clients reached.'], Http::STATUS_BAD_REQUEST);
        }
        if ($normalizedPostLogoutRedirectUris !== null) {
            $this->replacePostLogoutRedirectUris($client, $normalizedPostLogoutRedirectUris);
        }

        // Generate registration access token (RFC 7592)
        $registrationToken = $this->registrationTokenService->generateToken($client->getId());

        $jsonResponse = [
            'client_name' => $client->getName(),
            'client_id' => $client->getClientIdentifier(),
            'registration_access_token' => $registrationToken->getToken(),
            'registration_client_uri' => $this->urlGenerator->linkToRouteAbsolute(
                'oidc.DynamicRegistration.getClientConfiguration',
                ['clientId' => $client->getClientIdentifier()]
            ),
            'redirect_uris' => $redirect_uris,
            'token_endpoint_auth_method' => $token_endpoint_auth_method,
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => $application_type,
            'client_id_issued_at' => $client->getIssuedAt(),
            'scope' => $client->getAllowedScopes(),
            'token_type' => $client->getTokenType(),
            'backchannel_logout_uri' => $client->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $client->getBackchannelLogoutSessionRequired(),
            'post_logout_redirect_uris' => $this->getPostLogoutRedirectUris($client),
        ];

        if ($token_endpoint_auth_method !== 'none') {
            $jsonResponse['client_secret'] = $client->getSecret();
            $jsonResponse['client_secret_expires_at'] = $client->getIssuedAt()
                + (int)$this->appConfig->getAppValueString(
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME,
                    Application::DEFAULT_CLIENT_EXPIRE_TIME
                );
        }

        if ($client->getFrontchannelLogoutUri() !== null) {
            $jsonResponse['frontchannel_logout_uri'] = $client->getFrontchannelLogoutUri();
            $jsonResponse['frontchannel_logout_session_required'] = $client->getFrontchannelLogoutSessionRequired();
        }

        // Include resource_url in response if it was provided
        if ($client->getResourceUrl() !== null) {
            $jsonResponse['resource_url'] = $client->getResourceUrl();
        }

        $response = new JSONResponse($jsonResponse, Http::STATUS_CREATED);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'POST');

        return $response;
    }

    /**
     * Dynamic registration never accepts wildcard redirect URIs. Static/admin
     * registration continues to use RedirectUriService's wildcard-capable
     * policy.
     *
     * @return list<string>|JSONResponse
     */
    private function normalizeDynamicRedirectUris(array $redirectUris): array|JSONResponse {
        // The caller enforces redirect presence for browser response types.

        $normalized = [];
        foreach ($redirectUris as $redirectUri) {
            if (!is_string($redirectUri)
                || $redirectUri === ''
                || trim($redirectUri) !== $redirectUri
                || strlen($redirectUri) > 2000
                || str_contains($redirectUri, '*')) {
                return new JSONResponse([
                    'error' => 'invalid_redirect_uri',
                    'error_description' => 'Dynamically registered redirect_uris must be concrete URI strings without wildcards.',
                ], Http::STATUS_BAD_REQUEST);
            }

            try {
                $this->redirectUriService->isValidRedirectUri($redirectUri, false, false);
            } catch (RedirectUriValidationException $e) {
                return new JSONResponse([
                    'error' => 'invalid_redirect_uri',
                    'error_description' => 'Invalid redirect_uri: ' . $e->getMessage(),
                ], Http::STATUS_BAD_REQUEST);
            }

            if (in_array($redirectUri, $normalized, true)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'redirect_uris must not contain duplicate values.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $normalized[] = $redirectUri;
        }

        return $normalized;
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

        $response_types_arr = $client->getRegisteredResponseTypes();
        $grant_types_arr = $client->getRegisteredGrantTypes();

        $jsonResponse = [
            'client_id' => $client->getClientIdentifier(),
            'registration_client_uri' => $this->urlGenerator->linkToRouteAbsolute(
                'oidc.DynamicRegistration.getClientConfiguration',
                ['clientId' => $client->getClientIdentifier()]
            ),
            'client_name' => $client->getName(),
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => $client->getTokenEndpointAuthMethod()
                ?? $this->defaultTokenEndpointAuthMethod($client),
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => $client->getApplicationType() ?? 'web',
            'client_id_issued_at' => $client->getIssuedAt(),
            'scope' => $client->getAllowedScopes(),
            'backchannel_logout_uri' => $client->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $client->getBackchannelLogoutSessionRequired(),
            'post_logout_redirect_uris' => $this->getPostLogoutRedirectUris($client),
        ];

        if (($jsonResponse['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            $jsonResponse['client_secret'] = $client->getSecret();
            $jsonResponse['client_secret_expires_at'] = $client->getIssuedAt()
                + (int)$this->appConfig->getAppValueString(
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME,
                    Application::DEFAULT_CLIENT_EXPIRE_TIME
                );
        }

        if ($client->getFrontchannelLogoutUri() !== null) {
            $jsonResponse['frontchannel_logout_uri'] = $client->getFrontchannelLogoutUri();
            $jsonResponse['frontchannel_logout_session_required'] = $client->getFrontchannelLogoutSessionRequired();
        }

        $response = new JSONResponse($jsonResponse);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
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
        string|null $client_secret = null,
        string|null $application_type = null,
        string|null $token_endpoint_auth_method = null,
        ?array $grant_types = null,
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

        $effectiveApplicationType = $application_type
            ?? $client->getApplicationType()
            ?? 'web';
        $effectiveAuthMethod = $token_endpoint_auth_method
            ?? $client->getTokenEndpointAuthMethod()
            ?? $this->defaultTokenEndpointAuthMethod($client);
        $clientSecretBasicDisabled = $this->appConfig->getAppValueBool(
            Application::APP_CONFIG_DISABLE_AUTH_CLIENT_SECRET_BASIC,
            false
        );
        if (!in_array($effectiveApplicationType, ['web', 'native'], true)
            || !in_array($effectiveAuthMethod, ['none', 'client_secret_basic', 'client_secret_post'], true)
            || ($effectiveApplicationType === 'native' && $effectiveAuthMethod !== 'none')
            || ($clientSecretBasicDisabled && $effectiveAuthMethod === 'client_secret_basic')) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Invalid application_type or token_endpoint_auth_method.',
            ], Http::STATUS_BAD_REQUEST);
        }

        $effectiveSigningAlg = $id_token_signed_response_alg ?? $client->getSigningAlg();
        if ($effectiveAuthMethod === 'none' && $effectiveSigningAlg === 'HS256') {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'Public clients cannot use HS256 ID tokens.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($redirect_uris !== null) {
            $redirectUrisOrError = $this->normalizeDynamicRedirectUris($redirect_uris);
            if ($redirectUrisOrError instanceof JSONResponse) {
                return $redirectUrisOrError;
            }
            $redirect_uris = $redirectUrisOrError;
        }

        // Update client properties if provided
        if ($client_name !== null) {
            if (!mb_check_encoding($client_name, 'UTF-8')) {
                return $this->invalidFlowMetadata('client_name must be valid UTF-8.');
            }
            $client->setName(mb_substr($client_name, 0, 64, 'UTF-8'));
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

        $client->setApplicationType($effectiveApplicationType);
        $client->setTokenEndpointAuthMethod($effectiveAuthMethod);
        $client->setType($effectiveAuthMethod === 'none' ? 'public' : 'confidential');

        $metadata = $this->normalizeFlowMetadata(
            $response_types ?? $client->getRegisteredResponseTypes(),
            $grant_types ?? $client->getRegisteredGrantTypes()
        );
        if ($metadata instanceof JSONResponse) {
            return $metadata;
        }
        [$effectiveResponses, $effectiveGrants] = $metadata;
        $effectiveRedirectUris = $redirect_uris ?? array_map(
            static fn ($entry): string => $entry->getRedirectUri(),
            $this->redirectUriMapper->getByClientId($client->getId())
        );
        if ($effectiveResponses !== [] && $effectiveRedirectUris === []) {
            return $this->invalidFlowMetadata('redirect_uris are required for browser authorization flows.');
        }
        $redirectError = $this->validateDynamicRedirectPolicy($effectiveRedirectUris, $effectiveApplicationType,
            $effectiveAuthMethod === 'none' ? 'public' : 'confidential', $effectiveGrants);
        if ($redirectError !== null) {
            return $redirectError;
        }
        $client->setRegisteredResponseTypes($effectiveResponses);
        $client->setRegisteredGrantTypes($effectiveGrants);
        $client->setFlowType(implode(' ', array_values(array_unique(array_merge(...array_map(
            static fn (string $response): array => explode(' ', $response), $effectiveResponses ?: ['']
        ))))));

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
            } elseif (!FrontChannelLogoutService::isValidForRedirectUris($frontchannel_logout_uri, $client->getType(), $effectiveRedirectUris)) {
                return new JSONResponse([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'frontchannel_logout_uri must be an allowed absolute HTTP(S) URI whose scheme, host, and effective port match one of redirect_uris.',
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

        if ($client->getFrontchannelLogoutUri() !== null
            && !FrontChannelLogoutService::isValidForRedirectUris($client->getFrontchannelLogoutUri(), $client->getType(), $effectiveRedirectUris)) {
            return new JSONResponse([
                'error' => 'invalid_client_metadata',
                'error_description' => 'The configured frontchannel_logout_uri must keep the same scheme, host, and effective port as at least one redirect_uri.',
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
            if (strlen($scope) > 512) {
                return new JSONResponse(['error' => 'invalid_scope', 'error_description' => 'Scope exceeds 512 bytes.'], Http::STATUS_BAD_REQUEST);
            }
            // Apply the same RFC 6749 scope-token grammar as registration:
            // printable ASCII except DQUOTE and backslash, separated by one SP.
            if (!preg_match('/^(?:[\x21\x23-\x5B\x5D-\x7E]+(?: [\x21\x23-\x5B\x5D-\x7E]+)*)?$/D', $scope)) {
                $this->logger->info('Invalid scope characters during client configuration update.');
                return new JSONResponse([
                    'error' => 'invalid_scope',
                    'error_description' => 'Scope must be a space-delimited list of RFC 6749 scope-token values.',
                ], Http::STATUS_BAD_REQUEST);
            }
            $client->setAllowedScopes($scope);
        }

        // Update redirect URIs if provided
        if ($redirect_uris !== null) {
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

        $response_types_arr = $client->getRegisteredResponseTypes();
        $grant_types_arr = $client->getRegisteredGrantTypes();

        $jsonResponse = [
            'client_id' => $client->getClientIdentifier(),
            'registration_access_token' => $newToken->getToken(),
            'registration_client_uri' => $this->urlGenerator->linkToRouteAbsolute(
                'oidc.DynamicRegistration.getClientConfiguration',
                ['clientId' => $client->getClientIdentifier()]
            ),
            'client_name' => $client->getName(),
            'redirect_uris' => $currentRedirectUris,
            'token_endpoint_auth_method' => $client->getTokenEndpointAuthMethod()
                ?? $this->defaultTokenEndpointAuthMethod($client),
            'response_types' => $response_types_arr,
            'grant_types' => $grant_types_arr,
            'id_token_signed_response_alg' => $client->getSigningAlg(),
            'application_type' => $client->getApplicationType() ?? 'web',
            'client_id_issued_at' => $client->getIssuedAt(),
            'scope' => $client->getAllowedScopes(),
            'backchannel_logout_uri' => $client->getBackchannelLogoutUri(),
            'backchannel_logout_session_required' => $client->getBackchannelLogoutSessionRequired(),
            'post_logout_redirect_uris' => $this->getPostLogoutRedirectUris($client),
        ];

        if (($jsonResponse['token_endpoint_auth_method'] ?? 'none') !== 'none') {
            $jsonResponse['client_secret'] = $client->getSecret();
            $jsonResponse['client_secret_expires_at'] = $client->getIssuedAt()
                + (int)$this->appConfig->getAppValueString(
                    Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME,
                    Application::DEFAULT_CLIENT_EXPIRE_TIME
                );
        }

        if ($client->getFrontchannelLogoutUri() !== null) {
            $jsonResponse['frontchannel_logout_uri'] = $client->getFrontchannelLogoutUri();
            $jsonResponse['frontchannel_logout_session_required'] = $client->getFrontchannelLogoutSessionRequired();
        }

        $response = new JSONResponse($jsonResponse);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'PUT');

        return $response;
    }

    private function defaultTokenEndpointAuthMethod(Client $client): string {
        if ($client->getType() === 'public') {
            return 'none';
        }

        return $this->appConfig->getAppValueBool(
            Application::APP_CONFIG_DISABLE_AUTH_CLIENT_SECRET_BASIC,
            false
        ) ? 'client_secret_post' : 'client_secret_basic';
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

    private function invalidFlowMetadata(string $description): JSONResponse {
        return new JSONResponse([
            'error' => 'invalid_client_metadata', 'error_description' => $description,
        ], Http::STATUS_BAD_REQUEST);
    }

    /** @return array{list<string>,list<string>}|JSONResponse */
    private function normalizeFlowMetadata(array $responses, array $grants): array|JSONResponse {
        $supportedGrants = ['authorization_code', 'implicit', 'refresh_token',
            'urn:ietf:params:oauth:grant-type:device_code',
            'urn:ietf:params:oauth:grant-type:token-exchange'];
        if ($grants === []) {
            return $this->invalidFlowMetadata('grant_types must not be empty.');
        }
        foreach ($grants as $grant) {
            if (!is_string($grant) || !in_array($grant, $supportedGrants, true)) {
                return $this->invalidFlowMetadata('Unsupported grant_types value.');
            }
        }
        $normalizedResponses = [];
        foreach ($responses as $response) {
            if (!is_string($response)) {
                return $this->invalidFlowMetadata('response_types must contain only strings.');
            }
            $entries = preg_split('/ +/', trim($response), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($entries, SORT_STRING);
            $normalized = implode(' ', $entries);
            if (!in_array($normalized, ['code', 'id_token', 'code id_token', 'id_token token', 'code id_token token'], true)) {
                return $this->invalidFlowMetadata('Unsupported response_types value.');
            }
            if ((in_array('code', $entries, true) && !in_array('authorization_code', $grants, true))
                || (in_array('id_token', $entries, true) && !in_array('implicit', $grants, true))) {
                return $this->invalidFlowMetadata('response_types and grant_types are inconsistent.');
            }
            $normalizedResponses[] = $normalized;
        }
        if ((in_array('authorization_code', $grants, true)
                && !array_filter($normalizedResponses, static fn (string $value): bool => str_contains($value, 'code')))
            || (in_array('implicit', $grants, true)
                && !array_filter($normalizedResponses, static fn (string $value): bool => str_contains($value, 'id_token')))) {
            return $this->invalidFlowMetadata('Browser grants require a matching response_types value.');
        }
        return [array_values(array_unique($normalizedResponses)), array_values(array_unique($grants))];
    }

    private function validateDynamicRedirectPolicy(array $uris, string $applicationType, string $clientType, array $grants): ?JSONResponse {
        try {
            foreach ($uris as $uri) {
                $this->redirectUriService->validateDynamicPolicy($uri, $applicationType, $clientType, $grants);
            }
        } catch (\OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException $e) {
            return new JSONResponse(['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }

}
