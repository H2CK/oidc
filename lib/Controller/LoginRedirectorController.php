<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

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
                    $resource,
                    $code_challenge = null,
                    $code_challenge_method = null
                    ): Response
        {
        if (!$this->userSession->isLoggedIn()) {
            // Not authenticated yet
            // Store oidc attributes in user session to be available after login
            $this->session->set('oidc_client_id', $client_id);
            $this->session->set('oidc_state', $state);
            $this->session->set('oidc_response_type', $response_type);
            $this->session->set('oidc_redirect_uri', $redirect_uri);
            $this->session->set('oidc_scope', $scope);
            $this->session->set('oidc_nonce', $nonce);
            $this->session->set('oidc_resource', $resource);
            $this->session->set('oidc_code_challenge', $code_challenge);
            $this->session->set('oidc_code_challenge_method', $code_challenge_method);

            $afterLoginRedirectUrl = $this->urlGenerator->linkToRoute('oidc.Page.index', []);

            $loginUrl = $this->urlGenerator->linkToRoute(
                            'core.login.showLoginForm',
                            [
                                'redirect_url' => $afterLoginRedirectUrl
                            ]
            );

            $this->logger->debug('Not authenticated yet for client ' . $client_id . '. Redirect to login.');

            return new RedirectResponse($loginUrl);
        }

        if (empty($client_id)) {
            $client_id = $this->session->get('oidc_client_id');
        }
        if (empty($state)) {
            $state = $this->session->get('oidc_state');
        }
        if (empty($response_type)) {
            $response_type = $this->session->get('oidc_response_type');
        }
        if (empty($redirect_uri)) {
            $redirect_uri = $this->session->get('oidc_redirect_uri');
        }

        // Debug: Log scope before and after fallback
        $scopeFromParam = $scope ?? 'null';
        $this->logger->debug('[SCOPE DEBUG] Scope from URL parameter: ' . $scopeFromParam);

        if (empty($scope)) {
            $scope = $this->session->get('oidc_scope');
            $this->logger->debug('[SCOPE DEBUG] Scope from session fallback: ' . ($scope ?? 'null'));
        }
        if (empty($nonce)) {
            $nonce = $this->session->get('oidc_nonce');
        }
        if (empty($resource)) {
            $resource = $this->session->get('oidc_resource');
        }
        if (empty($code_challenge)) {
            $code_challenge = $this->session->get('oidc_code_challenge');
        }
        if (empty($code_challenge_method)) {
            $code_challenge_method = $this->session->get('oidc_code_challenge_method');
        }

        // Set default scope if scope is not set at all
        if (!isset($scope)) {
            $scope = Application::DEFAULT_SCOPE;
        }

        $this->clientMapper->cleanUp();

        try {
            $client = $this->clientMapper->getByIdentifier($client_id);
        } catch (ClientNotFoundException $e) {
            $params = [
                'message' => $this->l->t('Your client is not authorized to connect. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Client ' . $client_id . ' is not authorized to connect.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        // The client must not be expired
        if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + (int)$this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_CLIENT_EXPIRE_TIME, Application::DEFAULT_CLIENT_EXPIRE_TIME))) {
            $this->logger->warning('Client expired. Client id was ' . $client_id . '.');
            $params = [
                'message' => $this->l->t('Your client is expired. Please inform the administrator of your client.'),
            ];
            return new TemplateResponse('core', '400', $params, 'error');
        }

        // Set default resource if resource is not set at all
        if (!isset($resource) || trim($resource)==='') {
            // Try client-specific resource_url first (RFC 9728)
            $clientResourceUrl = $client->getResourceUrl();
            if (isset($clientResourceUrl) && trim($clientResourceUrl) !== '') {
                $resource = $clientResourceUrl;
            } else {
                // Fall back to global default
                $resource = $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_RESOURCE_IDENTIFIER, Application::DEFAULT_RESOURCE_IDENTIFIER);
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

        // Check if redirect URI is configured for client
        $redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
        $redirectUriFound = false;
        foreach ($redirectUris as $redirectUri) {
            if ($this->redirectUriService->matchRedirectUri($redirect_uri, $redirectUri->getRedirectUri())) {
                $redirectUriFound = true;
                break;
            }
        }
        if (!$redirectUriFound) {
            $params = [
                'message' => $this->l->t('The received redirect URI is not accepted to connect. Please inform the administrator of your client.'),
            ];
            $this->logger->notice('Redirect URI ' . $redirect_uri . ' is not accepted for client ' . $client_id . '.');
            return new TemplateResponse('core', '403', $params, 'error');
        }

        // Check response type
        $responseTypeEntries = explode(' ', strtolower(trim($response_type)), 3);
        $codeFlow = false;
        $implicitFlow = false;
        if (in_array('code', $responseTypeEntries)) {
            $codeFlow = true;
        }
        if (in_array('token', $responseTypeEntries) || in_array('id_token', $responseTypeEntries)) {
            $implicitFlow = true;
        }
        if (in_array('id_token', $responseTypeEntries) && empty($nonce)) {
            $this->logger->notice('Missing nonce in request for client ' . $client_id . '.');
            $url = $redirect_uri . '?error=request_not_supported&error_description=Missing%20nonce&state=' . $state;
            return new RedirectResponse($url);
        }
        if (in_array('token', $responseTypeEntries) && !in_array('id_token', $responseTypeEntries)) {
            $this->logger->notice('Missing id_token in response_type of request for client ' . $client_id . '.');
            $url = $redirect_uri . '?error=request_not_supported&error_description=Missing%20id_token&state=' . $state;
            return new RedirectResponse($url);
        }

        $allowedResponseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
        $isImplicitFlowAllowed = false;
        if (in_array('id_token', $allowedResponseTypeEntries)) {
            $isImplicitFlowAllowed = true;
        }
        if (($implicitFlow && !$isImplicitFlowAllowed) || (!$codeFlow && !$implicitFlow)) {
            $this->logger->notice('Not allowed response_type in request for client ' . $client_id . '. Please check the configuration for not allowed flow types.');
            $url = $redirect_uri . '?error=unsupported_response_type&state=' . $state;
            return new RedirectResponse($url);
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
                $url = $redirect_uri . '?error=invalid_request&error_description=Invalid%20code_challenge%20format&state=' . urlencode($state);
                return new RedirectResponse($url);
            }

            // Default to S256 if method not specified
            if (empty($code_challenge_method)) {
                $code_challenge_method = 'S256';
            }

            // Validate code_challenge_method: only S256 and plain are allowed
            if (!in_array($code_challenge_method, ['S256', 'plain'])) {
                $this->logger->notice('Unsupported code_challenge_method for client ' . $client_id . ': ' . $code_challenge_method);
                $url = $redirect_uri . '?error=invalid_request&error_description=Unsupported%20code_challenge_method&state=' . urlencode($state);
                return new RedirectResponse($url);
            }

            $this->logger->debug('PKCE challenge received for client ' . $client_id . ' using method ' . $code_challenge_method);
        }

        $code = $this->random->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($uid);
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr($scope, 0, 512));
        $accessToken->setResource(substr($resource, 0, 2000));
        $accessToken->setCreated($this->time->getTime());
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
            $this->accessTokenMapper->insert($accessToken);
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

        $url = $redirect_uri . '?state=' . urlencode($state);
        if (str_contains($redirect_uri, '?')) {
            $url = $redirect_uri . '&state=' . urlencode($state);
        }
        if (in_array('code', $responseTypeEntries)) {
            $url = $url . '&code=' . $code;
        }
        if (in_array('token', $responseTypeEntries)) {
            $url = $url . '&access_token=' . $accessToken->getAccessToken();
        }
        if (in_array('id_token', $responseTypeEntries)) {
            $jwt = $this->jwtGenerator->generateIdToken(
                $accessToken, $client, $this->request->getServerProtocol(), $this->request->getServerHost(), in_array('token', $responseTypeEntries)
            );
            $url = $url . '&id_token=' . $jwt;
        }
        if (in_array('id_token', $responseTypeEntries) || in_array('token', $responseTypeEntries)) {
            $url = $url . '&token_type=Bearer&expires_in=' . $expireTime . '&scope=' . $scope;
        }

        $this->logger->debug('Send redirect response for client ' . $client_id . '.');

        return new RedirectResponse($url);
    }
}
