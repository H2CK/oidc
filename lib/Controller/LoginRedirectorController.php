<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\Files\Command\Object\Info;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Security\ISecureRandom;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use Psr\Log\LoggerInterface;

class LoginRedirectorController extends ApiController
{
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var GroupMapper */
    private $groupMapper;
    /** @var AccessTokenMapper */
    private $accessTokenMapper;
    /** @var AuthorizationCodeMapper */
    private $authorizationCodeMapper;
    /** @var RedirectUriMapper */
    private $redirectUriMapper;
    /** @var UserConsentMapper */
    private $userConsentMapper;
    /** @var ISecureRandom */
    private $random;
    /** @var ISession */
    private $session;
    /** @var IL10N */
    private $l;
    /** @var ITimeFactory */
    private $time;
    /** @var IUserSession */
    private $userSession;
    /** @var IGroupManager */
    private $groupManager;
    /** @var IAppConfig */
    private $appConfig;
    /** @var JwtGenerator */
    private $jwtGenerator;
    /** @var RedirectUriService */
    private $redirectUriService;
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param string $appName
     * @param IRequest $request
     * @param IURLGenerator $urlGenerator
     * @param ClientMapper $clientMapper
     * @param GroupMapper $groupMapper
     * @param ISecureRandom $random
     * @param ISession $session
     * @param IL10N $l
     * @param ITimeFactory $time
     * @param IUserSession $userSession
     * @param IGroupManager $groupManager
     * @param AccessTokenMapper $accessTokenMapper
     * @param AuthorizationCodeMapper $authorizationCodeMapper
     * @param RedirectUriMapper $redirectUriMapper
     * @param UserConsentMapper $userConsentMapper
     * @param IAppConfig $appConfig
     * @param JwtGenerator $jwtGenerator
     * @param RedirectUriService $redirectUriService
     * @param LoggerInterface $loggerInterface
     */
    public function __construct(
                    string $appName,
                    IRequest $request,
                    IURLGenerator $urlGenerator,
                    ClientMapper $clientMapper,
                    GroupMapper $groupMapper,
                    ISecureRandom $random,
                    ISession $session,
                    IL10N $l,
                    ITimeFactory $time,
                    IUserSession $userSession,
                    IGroupManager $groupManager,
                    AccessTokenMapper $accessTokenMapper,
                    AuthorizationCodeMapper $authorizationCodeMapper,
                    RedirectUriMapper $redirectUriMapper,
                    UserConsentMapper $userConsentMapper,
                    IAppConfig $appConfig,
                    JwtGenerator $jwtGenerator,
                    RedirectUriService $redirectUriService,
                    LoggerInterface $logger
                    )
        {
        parent::__construct(
                        $appName,
                        $request);
        $this->urlGenerator = $urlGenerator;
        $this->clientMapper = $clientMapper;
        $this->groupMapper = $groupMapper;
        $this->random = $random;
        $this->session = $session;
        $this->l = $l;
        $this->time = $time;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->authorizationCodeMapper = $authorizationCodeMapper;
        $this->redirectUriMapper = $redirectUriMapper;
        $this->userConsentMapper = $userConsentMapper;
        $this->appConfig = $appConfig;
        $this->jwtGenerator = $jwtGenerator;
        $this->redirectUriService = $redirectUriService;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @UseSession
     * @BruteForceProtection(action=oidc_login)
     *
     * @param string $client_id
     * @param string $state
     * @param string $response_type
     * @param string $redirect_uri
     * @param string $scope
     * @param string $nonce
     * @param string $resource
     * @param string $code_challenge
     * @param string $code_challenge_method
     * @param string $prompt
     * @param string $max_age
     * @return Response
     */
    #[BruteForceProtection(action: 'oidc_login')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function authorizePost(
                    $client_id,
                    $state,
                    $response_type,
                    $redirect_uri,
                    $scope,
                    $nonce,
                    $resource = null,
                    $code_challenge = null,
                    $code_challenge_method = null,
                    $prompt = null,
                    $max_age = null
                    ): Response
        {
            return $this->authorize(
                $client_id,
                $state,
                $response_type,
                $redirect_uri,
                $scope,
                $nonce,
                $resource,
                $code_challenge,
                $code_challenge_method,
                $prompt,
                $max_age);
        }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @UseSession
     * @BruteForceProtection(action=oidc_login)
     *
     * @param string $client_id
     * @param string $state
     * @param string $response_type
     * @param string $redirect_uri
     * @param string $scope
     * @param string $nonce
     * @param string $resource
     * @param string $code_challenge
     * @param string $code_challenge_method
     * @param string $prompt
     * @param string $max_age
     * @return Response
     */
    #[BruteForceProtection(action: 'oidc_login')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function authorize(
                    $client_id,
                    $state,
                    $response_type,
                    $redirect_uri,
                    $scope,
                    $nonce,
                    $resource = null,
                    $code_challenge = null,
                    $code_challenge_method = null,
                    $prompt = null,
                    $max_age = null
                    ): Response
        {
        $prompt = $prompt ?? $this->request->getParam('prompt');
        $max_age = $max_age ?? $this->request->getParam('max_age');
        $claims = $this->request->getParam('claims');
        $response_mode = $this->request->getParam('response_mode');

        $unsupportedRequestParameterResponse = $this->rejectUnsupportedRequestParameters(
            $client_id,
            $redirect_uri,
            $state,
            $response_type,
            $response_mode
        );
        if ($unsupportedRequestParameterResponse !== null) {
            return $unsupportedRequestParameterResponse;
        }

        if (!$this->userSession->isLoggedIn()) {
            if ($this->promptContains($prompt, 'none')) {
                $clientOrResponse = $this->loadAuthorizationClient($client_id);
                if ($clientOrResponse instanceof Response) {
                    return $clientOrResponse;
                }

                $redirectUriErrorResponse = $this->validateAuthorizationRedirectUri($clientOrResponse, $client_id, $redirect_uri);
                if ($redirectUriErrorResponse !== null) {
                    return $redirectUriErrorResponse;
                }

                $this->logger->debug('prompt=none requested without authenticated user for client ' . $client_id . '. Returning login_required.');
                return $this->createAuthorizationErrorRedirect(
                    (string)$redirect_uri,
                    'login_required',
                    'User is not logged in.',
                    $state,
                    $response_type,
                    $response_mode
                );
            }

            return $this->redirectToLoginAfterOidcAuthentication(
                $client_id,
                $state,
                $response_type,
                $redirect_uri,
                $scope,
                $nonce,
                $resource,
                $code_challenge,
                $code_challenge_method,
                $prompt,
                $max_age,
                $response_mode,
                $claims,
                'Not authenticated yet for client ' . $client_id . '. Redirect to login.'
            );
        }

        // Debug: Log client id before and after fallback
        $clientFromParam = $client_id ?? 'null';
        $this->logger->debug('[CLIENT DEBUG] Client ID from URL parameter: ' . $clientFromParam);
        // Debug: Log scope before and after fallback
        $scopeFromParam = $scope ?? 'null';
        $this->logger->debug('[SCOPE DEBUG] Scope from URL parameter: ' . $scopeFromParam);

        if (empty($client_id)) {
            $client_id = $this->session->get('oidc_client_id');
            $this->logger->debug('[CLIENT DEBUG] Client ID from session fallback: ' . ($client_id ?? 'null'));
            $state = $this->session->get('oidc_state');
            $response_type = $this->session->get('oidc_response_type');
            $redirect_uri = $this->session->get('oidc_redirect_uri');
            $scope = $this->session->get('oidc_scope');
            $this->logger->debug('[SCOPE DEBUG] Scope from session fallback: ' . ($scope ?? 'null'));
            $nonce = $this->session->get('oidc_nonce');
            $resource = $this->session->get('oidc_resource');
            $code_challenge = $this->session->get('oidc_code_challenge');
            $code_challenge_method = $this->session->get('oidc_code_challenge_method');
            $prompt = $this->session->get('oidc_prompt');
            $max_age = $this->session->get('oidc_max_age');
            $response_mode = $this->session->get('oidc_response_mode');
            $claims = $this->session->get('oidc_claims');
        }

        $oidcLoginPending = $this->session->get('oidc_login_pending') === true;
        $authTime = $this->getOidcAuthenticationTime();

		// Guard: if critical OAuth params are still missing after session fallback,
        // return a meaningful error instead of letting downstream code crash with a 500
        // (e.g. matchRedirectUri(null, ...) or trim(null) in PHP 8.4).
		// Note: state is not critical for processing the request and might also not be passed by the client, e.g Guacamole.
        if (empty($redirect_uri)) {
            $this->logger->error('Missing critical OAuth params after session fallback: '
                . 'response_type=' . var_export($response_type, true) . ', '
                . 'redirect_uri=' . var_export($redirect_uri, true));
            return new TemplateResponse('core', '400', [
                'message' => $this->l->t('Authorization session expired. Please try again.'),
            ], 'error');
        }

        // Set default scope if scope is not set at all
        if (!isset($scope)) {
            $scope = Application::DEFAULT_SCOPE;
        }

        $clientOrResponse = $this->loadAuthorizationClient($client_id);
        if ($clientOrResponse instanceof Response) {
            return $clientOrResponse;
        }
        $client = $clientOrResponse;

        // Set default resource if resource is not set at all
        if (!isset($resource) || trim($resource)==='') {
            // Try client-specific resource_url first (RFC 9728)
            $clientResourceUrl = $client->getResourceUrl();
            if (isset($clientResourceUrl) && trim($clientResourceUrl) !== '') {
                $resource = $clientResourceUrl;
            } else {
                // Fall back to client identifier
                $resource = null;
            }
        }

        // Adapt scopes to configured values
        $allowedScopes = $client->getAllowedScopes();
        $this->logger->debug('[SCOPE DEBUG] Client allowed scopes: ' . ($allowedScopes ?: 'empty/not configured'));
        $this->logger->debug('[SCOPE DEBUG] Requested scope before filtering: ' . $scope);

        $newScope = '';
        $allowedScopesArr = array_values(array_unique(array_filter(array_map('trim', explode(' ', strtolower(trim($allowedScopes)))))));
        $scopesArr = array_values(array_unique(array_filter(array_map('trim', explode(' ', strtolower(trim($scope)))))));
        foreach ($scopesArr as $scopeEntry) {
            if (in_array($scopeEntry, $allowedScopesArr) || empty($allowedScopesArr)) {
                $newScope = $newScope . $scopeEntry . ' ';
            }
        }
        $newScope = trim($newScope);
        if ($newScope === '') {
            $newScope = Application::DEFAULT_SCOPE;
        }
        $scope = $newScope;
        $this->logger->debug('[SCOPE DEBUG] Scope after filtering: ' . $scope);

        $redirectUriErrorResponse = $this->validateAuthorizationRedirectUri($client, $client_id, $redirect_uri);
        if ($redirectUriErrorResponse !== null) {
            return $redirectUriErrorResponse;
        }

        try {
            $requestedIdTokenClaims = $this->getRequestedClaims($claims, 'id_token');
            $requestedUserinfoClaims = $this->getRequestedClaims($claims, 'userinfo');
        } catch (\InvalidArgumentException $e) {
            $this->logger->notice('Invalid claims parameter for client ' . $client_id . ': ' . $e->getMessage());
            return $this->createAuthorizationErrorRedirect(
                (string)$redirect_uri,
                'invalid_request',
                'Invalid claims parameter.',
                $state,
                $response_type,
                $response_mode
            );
        }

        if (empty($response_type)) {
            $this->logger->notice('Missing response_type in request for client ' . $client_id . '.');
            return $this->createAuthorizationErrorRedirect(
                (string)$redirect_uri,
                'unsupported_response_type',
                'Missing response_type',
                $state
            );
        }

        // Check response type
        $responseTypeEntries = $this->parseResponseTypeEntries($response_type);
        $responseMode = $this->normalizeAuthorizationResponseMode($response_mode);
        $codeFlow = false;
        $implicitFlow = false;
        if (in_array('code', $responseTypeEntries)) {
            $codeFlow = true;
        }
        if (in_array('token', $responseTypeEntries) || in_array('id_token', $responseTypeEntries)) {
            $implicitFlow = true;
        }
        if (!$this->isSupportedAuthorizationResponseMode($responseMode, $responseTypeEntries)) {
            $this->logger->notice('Unsupported response_mode in request for client ' . $client_id . ': ' . var_export($response_mode, true));
            return $this->createAuthorizationErrorRedirect(
                (string)$redirect_uri,
                'unsupported_response_mode',
                'Unsupported response_mode',
                $state,
                $response_type
            );
        }
        if (in_array('id_token', $responseTypeEntries) && empty($nonce)) {
            $this->logger->notice('Missing nonce in request for client ' . $client_id . '.');
            return $this->createAuthorizationErrorRedirect(
                (string)$redirect_uri,
                'invalid_request',
                'Missing nonce',
                $state,
                $response_type,
                $response_mode
            );
        }
        if (in_array('token', $responseTypeEntries) && !in_array('id_token', $responseTypeEntries)) {
            $this->logger->notice('Missing id_token in response_type of request for client ' . $client_id . '.');
            return $this->createAuthorizationErrorRedirect(
                (string)$redirect_uri,
                'request_not_supported',
                'Missing id_token',
                $state,
                $response_type,
                $response_mode
            );
        }

        $allowedResponseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
        $isImplicitFlowAllowed = false;
        if (in_array('id_token', $allowedResponseTypeEntries)) {
            $isImplicitFlowAllowed = true;
        }
        if (($implicitFlow && !$isImplicitFlowAllowed) || (!$codeFlow && !$implicitFlow)) {
            $this->logger->notice('Not allowed response_type in request for client ' . $client_id . '. Please check the configuration for not allowed flow types.');
            return $this->createAuthorizationErrorRedirect(
                (string)$redirect_uri,
                'unsupported_response_type',
                'Unsupported response_type',
                $state,
                $response_type,
                $response_mode
            );
        }

        if ($this->promptContains($prompt, 'login') && !$oidcLoginPending) {
            $this->logger->debug('prompt=login requested for client ' . $client_id . '. Forcing reauthentication.');
            $this->userSession->logout();
            return $this->redirectToLoginAfterOidcAuthentication(
                $client_id,
                $state,
                $response_type,
                $redirect_uri,
                $scope,
                $nonce,
                $resource,
                $code_challenge,
                $code_challenge_method,
                $prompt,
                $max_age,
                $response_mode,
                $claims,
                'Redirect to login for prompt=login reauthentication for client ' . $client_id . '.'
            );
        }

        if ($this->maxAgeExceeded($max_age, $authTime)) {
            if ($this->promptContains($prompt, 'none')) {
                $this->logger->debug('prompt=none requested but max_age is exceeded for client ' . $client_id . '. Returning login_required.');
                return $this->createAuthorizationErrorRedirect(
                    (string)$redirect_uri,
                    'login_required',
                    'User authentication is too old.',
                    $state,
                    $response_type,
                    $response_mode
                );
            }

            $this->logger->debug('max_age is exceeded for client ' . $client_id . '. Forcing reauthentication.');
            $this->userSession->logout();
            return $this->redirectToLoginAfterOidcAuthentication(
                $client_id,
                $state,
                $response_type,
                $redirect_uri,
                $scope,
                $nonce,
                $resource,
                $code_challenge,
                $code_challenge_method,
                $prompt,
                $max_age,
                $response_mode,
                $claims,
                'Redirect to login for max_age reauthentication for client ' . $client_id . '.'
            );
        }

        // Check if user is in allowed groups for client
        $clientGroups = $this->groupMapper->getGroupsByClientId($client->getId());
        $userGroups = $this->groupManager->getUserGroups($this->userSession->getUser());

        $groupFound = false;
        if (count($clientGroups) < 1) { $groupFound = true; }
        foreach ($clientGroups as $clientGroup) {
            foreach ($userGroups as $userGroup) {
                if ($clientGroup->getGroupId() === $userGroup->getGID()) {
                    $groupFound = true;
                    break;
                }
            }
        }
        if (!$groupFound) {
            $params = [
                'message' => $this->l->t('The user is not a member of the groups defined for the client. You are not allowed to retrieve a login token.'),
            ];
            $this->logger->notice('User ' . $this->userSession->getUser()->getUID() . ' is not accepted for client ' . $client_id . ' due to missing group assignment.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        $uid = $this->userSession->getUser()->getUID();

        // Check if user consent/settings are allowed by administrator
        $allowUserSettings = $this->appConfig->getAppValueString(
            Application::APP_CONFIG_ALLOW_USER_SETTINGS,
            Application::DEFAULT_ALLOW_USER_SETTINGS
        );

        if ($allowUserSettings === 'no') {
            // Administrator has disabled user consent - auto-grant all scopes
            $this->logger->debug('User consent disabled by admin for user ' . $uid . ' and client ' . $client_id . ' - auto-granting all scopes');

            // Check if consent record exists
            $existingConsent = $this->userConsentMapper->findByUserAndClient($uid, $client->getId());

            // Create or update consent record for display on personal settings page
            if ($existingConsent === null) {
                $consent = new UserConsent();
                $consent->setUserId($uid);
                $consent->setClientId($client->getId());
                $consent->setScopesGranted($scope);
                $consent->setCreatedAt($this->time->getTime());
                $consent->setUpdatedAt($this->time->getTime());
                $consent->setExpiresAt(null);
                $this->userConsentMapper->createOrUpdate($consent);
                $this->logger->debug('Auto-created consent record for user ' . $uid . ' and client ' . $client_id);
            } elseif ($existingConsent->getScopesGranted() !== $scope) {
                // Update consent if scopes changed
                $existingConsent->setScopesGranted($scope);
                $existingConsent->setUpdatedAt($this->time->getTime());
                $this->userConsentMapper->createOrUpdate($existingConsent);
                $this->logger->debug('Auto-updated consent record for user ' . $uid . ' and client ' . $client_id);
            }
            // Continue with authorization flow using all requested scopes
        } else {
            // User consent is enabled - check if consent is required
            $existingConsent = $this->userConsentMapper->findByUserAndClient($uid, $client->getId());

            $consentRequired = false;
            if ($existingConsent === null) {
                // No prior consent
                $consentRequired = true;
                $this->logger->debug('No existing consent found for user ' . $uid . ' and client ' . $client_id);
            } elseif ($existingConsent->getScopesGranted() !== $scope) {
                // Scopes changed since last consent
                $consentRequired = true;
                $this->logger->debug('Scopes changed for user ' . $uid . ' and client ' . $client_id . '. Old: ' . $existingConsent->getScopesGranted() . ', New: ' . $scope);
            } elseif ($existingConsent->getExpiresAt() !== null &&
                      $this->time->getTime() > $existingConsent->getExpiresAt()) {
                // Consent expired
                $consentRequired = true;
                $this->logger->debug('Consent expired for user ' . $uid . ' and client ' . $client_id);
            }

            if ($consentRequired) {
                // Store authorization request parameters in session for consent page
                // IMPORTANT: Preserve ALL OAuth parameters, not just consent-specific ones
                // These will be needed when redirecting back to authorize after consent
                $this->session->set('oidc_consent_pending', true);
                $this->session->set('oidc_client_id', $client_id);
                $this->session->set('oidc_client_name', $client->getName());
                $this->session->set('oidc_requested_scopes', $scope);
                // Also preserve other OAuth parameters for post-consent redirect
                $this->session->set('oidc_state', $state);
                $this->session->set('oidc_response_type', $response_type);
                $this->session->set('oidc_redirect_uri', $redirect_uri);
                $this->session->set('oidc_nonce', $nonce);
                $this->session->set('oidc_resource', $resource);
                $this->session->set('oidc_code_challenge', $code_challenge);
                $this->session->set('oidc_code_challenge_method', $code_challenge_method);
                $this->session->set('oidc_prompt', $prompt);
                $this->session->set('oidc_max_age', $max_age);
                $this->session->set('oidc_response_mode', $response_mode);
                $this->session->set('oidc_claims', $claims);

                $this->session->close(); // Close session to prevent session locking issues during redirect

                // Redirect to consent page
                $consentUrl = $this->urlGenerator->linkToRoute('oidc.Consent.show', []);
                $this->logger->debug('Redirecting to consent page for user ' . $uid . ' and client ' . $client_id);
                return new RedirectResponse($consentUrl);
            }

            // If consent exists and is valid, use the scopes from consent
            // (user may have approved a subset of requested scopes)
            if ($existingConsent !== null) {
                $scope = $existingConsent->getScopesGranted();
                $this->logger->debug('Using consented scopes for user ' . $uid . ' and client ' . $client_id . ': ' . $scope);
            }
        }

        // PKCE validation (RFC 7636)
        if (!empty($code_challenge)) {
            // Validate code_challenge format: 43-128 characters, unreserved chars only
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $code_challenge)) {
                $this->logger->notice('Invalid code_challenge format for client ' . $client_id . '.');
                return $this->createAuthorizationErrorRedirect(
                    (string)$redirect_uri,
                    'invalid_request',
                    'Invalid code_challenge format',
                    $state,
                    $response_type,
                    $response_mode
                );
            }

            // Default to S256 if method not specified
            if (empty($code_challenge_method)) {
                $code_challenge_method = 'S256';
            }

            // Validate code_challenge_method: only S256 and plain are allowed
            if (!in_array($code_challenge_method, ['S256', 'plain'])) {
                $this->logger->notice('Unsupported code_challenge_method for client ' . $client_id . ': ' . $code_challenge_method);
                return $this->createAuthorizationErrorRedirect(
                    (string)$redirect_uri,
                    'invalid_request',
                    'Unsupported code_challenge_method',
                    $state,
                    $response_type,
                    $response_mode
                );
            }

            $this->logger->debug('PKCE challenge received for client ' . $client_id . ' using method ' . $code_challenge_method);
        }

        $code = $this->random->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($uid);
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr($scope, 0, 512));
        if ($resource === null) {
            $accessToken->setResource(null);
        } else {
            $accessToken->setResource(substr($resource, 0, 2000));
        }
        $accessToken->setIdTokenClaims($this->encodeRequestedClaims($requestedIdTokenClaims));
        $accessToken->setUserinfoClaims($this->encodeRequestedClaims($requestedUserinfoClaims));
        $accessToken->setCreated($authTime);
        $accessToken->setRefreshed($this->time->getTime());
        if (empty($nonce) || !isset($nonce)) {
            $nonce = '';
        } else {
            $nonce = substr($nonce, 0, 256);
        }
        $accessToken->setNonce($nonce);

        // Store PKCE challenge if provided
        if (!empty($code_challenge)) {
            $accessToken->setCodeChallenge(substr($code_challenge, 0, 128));
            $accessToken->setCodeChallengeMethod(substr($code_challenge_method, 0, 16));
        }

        try {
            $accessToken->setAccessToken($this->jwtGenerator->generateAccessToken($accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost()));
            $accessToken = $this->accessTokenMapper->insert($accessToken);
            if (in_array('code', $responseTypeEntries)) {
                $this->authorizationCodeMapper->createForAccessToken(
                    $accessToken->getId(),
                    $code,
                    $this->time->getTime()
                );
            }
        } catch (JwtCreationErrorException $e) {
            $params = [
                'message' => $this->l->t('A failure during JWT creation occured. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Client ' . $client_id . ' is not authorized to connect, due to failure during JWT creation.');
            return new TemplateResponse('core', '500', $params, 'error');
        }

        if (empty($state) || !isset($state)) {
            $state = '';
        }

        $expireTime = $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, Application::DEFAULT_EXPIRE_TIME);

        $responseParams = [
            'state' => $state,
        ];
        if (in_array('code', $responseTypeEntries)) {
            $responseParams['code'] = $code;
        }
        if (in_array('token', $responseTypeEntries)) {
            $responseParams['access_token'] = $accessToken->getAccessToken();
            $responseParams['token_type'] = 'Bearer';
            $responseParams['expires_in'] = $expireTime;
            $responseParams['scope'] = $scope;
        }
        if (in_array('id_token', $responseTypeEntries)) {
            $jwt = $this->jwtGenerator->generateIdToken(
                $accessToken,
                $client,
                $this->request->getServerProtocol(),
                $this->request->getServerHost(),
                in_array('token', $responseTypeEntries),
                !$codeFlow,
                in_array('code', $responseTypeEntries) ? $code : null
            );
            $responseParams['id_token'] = $jwt;
        }

        $url = $this->buildAuthorizationResponseRedirectUri(
            (string)$redirect_uri,
            $responseParams,
            $this->authorizationResponseUsesFragment($responseTypeEntries, $responseMode)
        );

        $this->session->close(); // Close session to prevent session locking issues during redirect

        $this->logger->debug('Send redirect response for client ' . $client_id . '.');

        return new RedirectResponse($url);
    }

    private function rejectUnsupportedRequestParameters(
        mixed $clientId,
        mixed $redirectUri,
        mixed $state,
        mixed $responseType,
        mixed $responseMode
    ): ?Response {
        $requestObject = $this->request->getParam('request');
        $requestUri = $this->request->getParam('request_uri');

        if (
            !$this->hasNonEmptyRequestParameter($requestObject)
            && !$this->hasNonEmptyRequestParameter($requestUri)
        ) {
            return null;
        }

        $errorState = $state;
        if (!$this->hasNonEmptyRequestParameter($errorState) && $this->hasNonEmptyRequestParameter($requestObject)) {
            $errorState = $this->extractStateFromUnsignedRequestObject($requestObject) ?? $errorState;
        }

        if (!$this->hasNonEmptyRequestParameter($redirectUri)) {
            $this->logger->notice('Unsupported request parameter received without a redirect URI.');
            return new TemplateResponse('core', '400', [
                'message' => $this->l->t('Authorization request is missing a redirect URI.'),
            ], 'error');
        }

        $clientOrResponse = $this->loadAuthorizationClient($clientId);
        if ($clientOrResponse instanceof Response) {
            return $clientOrResponse;
        }

        $redirectUriErrorResponse = $this->validateAuthorizationRedirectUri(
            $clientOrResponse,
            $clientId,
            $redirectUri
        );
        if ($redirectUriErrorResponse !== null) {
            return $redirectUriErrorResponse;
        }

        if ($this->hasNonEmptyRequestParameter($requestObject)) {
            $this->logger->notice('Request object parameter is not supported for client ' . $clientId . '.');
            return $this->createAuthorizationErrorRedirect(
                (string)$redirectUri,
                'request_not_supported',
                'Request object parameter is not supported.',
                $errorState,
                $responseType,
                $responseMode
            );
        }

        $this->logger->notice('Request URI parameter is not supported for client ' . $clientId . '.');
        return $this->createAuthorizationErrorRedirect(
            (string)$redirectUri,
            'request_uri_not_supported',
            'Request URI parameter is not supported.',
            $errorState,
            $responseType,
            $responseMode
        );
    }

    private function loadAuthorizationClient(mixed $clientId): Client|Response
    {
        if (!is_string($clientId) || trim($clientId) === '') {
            $params = [
                'message' => $this->l->t('Your client is not authorized to connect. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Client ' . var_export($clientId, true) . ' is not authorized to connect.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        $this->clientMapper->cleanUp();

        try {
            $client = $this->clientMapper->getByIdentifier($clientId);
        } catch (ClientNotFoundException $e) {
            $params = [
                'message' => $this->l->t('Your client is not authorized to connect. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Client ' . $clientId . ' is not authorized to connect.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        if ($client === null) {
            $params = [
                'message' => $this->l->t('Your client is not authorized to connect. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Client ' . $clientId . ' is not authorized to connect.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        // The client must not be expired
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            $this->logger->warning('Client expired. Client id was ' . $clientId . '.');
            $params = [
                'message' => $this->l->t('Your client is expired. Please inform the administrator of your client.'),
            ];
            return new TemplateResponse('core', '400', $params, 'error');
        }

        return $client;
    }

    private function validateAuthorizationRedirectUri(
        Client $client,
        mixed $clientId,
        mixed $redirectUri
    ): ?TemplateResponse {
        if (!is_string($redirectUri) || trim($redirectUri) === '') {
            $params = [
                'message' => $this->l->t('The received redirect URI is not accepted to connect. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Redirect URI ' . var_export($redirectUri, true) . ' is not accepted for client ' . $clientId . '.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        // Check if redirect URI is configured for client
        $redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
        foreach ($redirectUris as $registeredRedirectUri) {
            if ($this->redirectUriService->matchRedirectUri($redirectUri, $registeredRedirectUri->getRedirectUri())) {
                return null;
            }
        }

        $params = [
            'message' => $this->l->t('The received redirect URI is not accepted to connect. Please inform the administrator of your client.'),
        ];
        $this->logger->notice('Redirect URI ' . $redirectUri . ' is not accepted for client ' . $clientId . '.');
        return new TemplateResponse('core', '403', $params, 'error');
    }

    private function hasNonEmptyRequestParameter(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return !empty($value);
    }

    private function redirectToLoginAfterOidcAuthentication(
        mixed $clientId,
        mixed $state,
        mixed $responseType,
        mixed $redirectUri,
        mixed $scope,
        mixed $nonce,
        mixed $resource,
        mixed $codeChallenge,
        mixed $codeChallengeMethod,
        mixed $prompt,
        mixed $maxAge,
        mixed $responseMode,
        mixed $claims,
        string $logMessage
    ): RedirectResponse {
        // Store OIDC attributes in the user session to be available after login.
        $this->session->set('oidc_client_id', $clientId);
        $this->session->set('oidc_state', $state);
        $this->session->set('oidc_response_type', $responseType);
        $this->session->set('oidc_redirect_uri', $redirectUri);
        $this->session->set('oidc_scope', $scope);
        $this->session->set('oidc_nonce', $nonce);
        $this->session->set('oidc_resource', $resource);
        $this->session->set('oidc_code_challenge', $codeChallenge);
        $this->session->set('oidc_code_challenge_method', $codeChallengeMethod);
        $this->session->set('oidc_prompt', $prompt);
        $this->session->set('oidc_max_age', $maxAge);
        $this->session->set('oidc_response_mode', $responseMode);
        $this->session->set('oidc_claims', $claims);
        $this->session->set('oidc_login_pending', true);

        $afterLoginRedirectUrl = $this->urlGenerator->linkToRoute('oidc.Page.index', array_filter([
            'client_id'             => $clientId,
            'state'                 => $state,
            'response_type'         => $responseType,
            'redirect_uri'          => $redirectUri,
            'scope'                 => $scope,
            'nonce'                 => $nonce,
            'resource'              => $resource,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'prompt'                => $prompt,
            'max_age'               => $maxAge,
            'response_mode'         => $responseMode,
            'claims'                => $claims,
        ], static function ($value): bool {
            return $value !== null && $value !== '';
        }));

        $loginUrl = $this->urlGenerator->linkToRoute(
            'core.login.showLoginForm',
            [
                'redirect_url' => $afterLoginRedirectUrl
            ]
        );

        $this->session->close(); // Close session to prevent session locking issues during redirect

        $this->logger->debug($logMessage);

        return new RedirectResponse($loginUrl);
    }

    /**
     * @return array<string, null|array<string, mixed>>
     */
    private function getRequestedClaims(mixed $claims, string $section): array
    {
        if (!$this->hasNonEmptyRequestParameter($claims)) {
            return [];
        }

        if (!is_string($claims)) {
            throw new \InvalidArgumentException('claims must be a JSON object string');
        }

        $decodedClaims = json_decode($claims);
        if (json_last_error() !== JSON_ERROR_NONE || !($decodedClaims instanceof \stdClass)) {
            throw new \InvalidArgumentException('claims must be valid JSON object');
        }

        if (!property_exists($decodedClaims, $section)) {
            return [];
        }

        $sectionClaims = $decodedClaims->{$section};
        if (!($sectionClaims instanceof \stdClass)) {
            throw new \InvalidArgumentException($section . ' must be a JSON object');
        }

        $requestedClaims = [];
        foreach (get_object_vars($sectionClaims) as $claimName => $claimRequest) {
            if ($claimName === '') {
                throw new \InvalidArgumentException($section . ' contains an empty claim name');
            }

            if ($claimRequest !== null && !($claimRequest instanceof \stdClass)) {
                throw new \InvalidArgumentException($section . '.' . $claimName . ' must be null or a JSON object');
            }

            if ($claimRequest instanceof \stdClass) {
                $claimRequest = json_decode(json_encode($claimRequest) ?: '{}', true);
                if (!is_array($claimRequest)) {
                    throw new \InvalidArgumentException($section . '.' . $claimName . ' must be null or a JSON object');
                }

                if (array_key_exists('essential', $claimRequest) && !is_bool($claimRequest['essential'])) {
                    throw new \InvalidArgumentException($section . '.' . $claimName . '.essential must be a boolean');
                }

                if (array_key_exists('values', $claimRequest) && !is_array($claimRequest['values'])) {
                    throw new \InvalidArgumentException($section . '.' . $claimName . '.values must be a JSON array');
                }
            }

            $requestedClaims[$claimName] = $claimRequest;
        }

        return $requestedClaims;
    }

    /**
     * @param array<string, null|array<string, mixed>> $claimRequests
     */
    private function encodeRequestedClaims(array $claimRequests): string
    {
        if ($claimRequests === []) {
            return '';
        }

        return json_encode($claimRequests) ?: '';
    }

    private function promptContains(mixed $prompt, string $expectedPrompt): bool
    {
        if (!is_string($prompt) || trim($prompt) === '') {
            return false;
        }

        $promptEntries = array_filter(array_map('trim', explode(' ', strtolower($prompt))));
        return in_array(strtolower($expectedPrompt), $promptEntries, true);
    }

    private function getOidcAuthenticationTime(): int
    {
        $storedAuthTime = $this->session->get('oidc_auth_time');
        $loginPending = $this->session->get('oidc_login_pending');

        if ($loginPending || !$this->isPositiveIntegerLike($storedAuthTime)) {
            $storedAuthTime = $this->time->getTime();
            $this->session->set('oidc_auth_time', $storedAuthTime);
        }

        if ($loginPending) {
            $this->session->remove('oidc_login_pending');
        }

        return (int)$storedAuthTime;
    }

    private function maxAgeExceeded(mixed $maxAge, int $authTime): bool
    {
        if (!$this->isNonNegativeIntegerLike($maxAge)) {
            return false;
        }

        return $this->time->getTime() > $authTime + (int)$maxAge;
    }

    private function isPositiveIntegerLike(mixed $value): bool
    {
        return $this->isNonNegativeIntegerLike($value) && (int)$value > 0;
    }

    private function isNonNegativeIntegerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        return $value !== '' && ctype_digit($value);
    }

    /**
     * @return list<string>
     */
    private function parseResponseTypeEntries(mixed $responseType): array
    {
        if (!is_string($responseType)) {
            return [];
        }

        $responseType = strtolower(trim($responseType));
        if ($responseType === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $responseType) ?: []));
    }

    private function normalizeAuthorizationResponseMode(mixed $responseMode): ?string
    {
        if (!is_string($responseMode)) {
            return null;
        }

        $responseMode = strtolower(trim($responseMode));
        if ($responseMode === '' || $responseMode === 'default') {
            return null;
        }

        return $responseMode;
    }

    /**
     * @param list<string> $responseTypeEntries
     */
    private function authorizationResponseUsesFragment(array $responseTypeEntries, ?string $responseMode = null): bool
    {
        if ($responseMode === 'fragment') {
            return true;
        }

        if ($responseMode === 'query') {
            return in_array('id_token', $responseTypeEntries, true) || in_array('token', $responseTypeEntries, true);
        }

        return in_array('id_token', $responseTypeEntries, true) || in_array('token', $responseTypeEntries, true);
    }

    /**
     * @param list<string> $responseTypeEntries
     */
    private function isSupportedAuthorizationResponseMode(?string $responseMode, array $responseTypeEntries): bool
    {
        if ($responseMode === null) {
            return true;
        }

        if ($responseMode === 'fragment') {
            return true;
        }

        if ($responseMode !== 'query') {
            return false;
        }

        return !in_array('id_token', $responseTypeEntries, true) && !in_array('token', $responseTypeEntries, true);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildAuthorizationResponseRedirectUri(string $redirectUri, array $params, bool $useFragment): string
    {
        $encodedParams = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $encodedParams[$key] = (string)$value;
        }

        $query = http_build_query($encodedParams, '', '&', PHP_QUERY_RFC3986);
        if ($query === '') {
            return $redirectUri;
        }

        if ($useFragment) {
            $separator = str_contains($redirectUri, '#') ? '&' : '#';
            if (str_ends_with($redirectUri, '#') || str_ends_with($redirectUri, '&')) {
                $separator = '';
            }
            return $redirectUri . $separator . $query;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        if (str_ends_with($redirectUri, '?') || str_ends_with($redirectUri, '&')) {
            $separator = '';
        }
        return $redirectUri . $separator . $query;
    }

    private function extractStateFromUnsignedRequestObject(mixed $requestObject): ?string
    {
        if (!is_string($requestObject)) {
            return null;
        }

        $parts = explode('.', $requestObject);
        if (count($parts) < 2) {
            return null;
        }

        $payload = $this->base64UrlDecode($parts[1]);
        if ($payload === null) {
            return null;
        }

        $claims = json_decode($payload, true);
        if (!is_array($claims) || !isset($claims['state']) || !is_string($claims['state'])) {
            return null;
        }

        $state = trim($claims['state']);
        return $state === '' ? null : $state;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    private function createAuthorizationErrorRedirect(
        string $redirectUri,
        string $error,
        string $errorDescription,
        mixed $state,
        mixed $responseType = null,
        mixed $responseMode = null
    ): RedirectResponse {
        $params = [
            'error' => $error,
            'error_description' => $errorDescription,
        ];
        if ($state !== null && trim((string)$state) !== '') {
            $params['state'] = (string)$state;
        }

        $responseTypeEntries = $this->parseResponseTypeEntries($responseType);
        $normalizedResponseMode = $this->normalizeAuthorizationResponseMode($responseMode);

        return new RedirectResponse($this->buildAuthorizationResponseRedirectUri(
            $redirectUri,
            $params,
            $this->authorizationResponseUsesFragment($responseTypeEntries, $normalizedResponseMode)
        ));
    }
}
