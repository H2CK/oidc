<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Http\FormPostResponse;
use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\ScopeCeilingService;
use OCA\OIDCIdentityProvider\Service\ClientAuthorizationService;
use OCP\Server;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use Psr\Log\LoggerInterface;

class ConsentController extends Controller {
    /** @var ISession */
    private $session;
    /** @var IUserSession */
    private $userSession;
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var AccessTokenMapper */
    private $accessTokenMapper;
    /** @var UserConsentMapper */
    private $userConsentMapper;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var ITimeFactory */
    private $time;
    /** @var IL10N */
    private $l;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ISession $session,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        UserConsentMapper $userConsentMapper,
        AccessTokenMapper $accessTokenMapper,
        ClientMapper $clientMapper,
        ITimeFactory $time,
        IL10N $l,
        IAppConfig $appConfig,
        LoggerInterface $logger,
        private AuthorizationService $authorizationService,
        private ?ClientAuthorizationService $clientAuthorizationService = null,
        private ?ScopeCeilingService $scopeCeiling = null,
    ) {
        parent::__construct($appName, $request);
        $this->session = $session;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->userConsentMapper = $userConsentMapper;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->clientMapper = $clientMapper;
        $this->time = $time;
        $this->l = $l;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * @UseSession
     *
     * Display the consent page
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function show(): TemplateResponse {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            return new TemplateResponse('core', '403', [
                'message' => $this->l->t('You must be logged in to view this page.')
            ], TemplateResponse::RENDER_AS_ERROR, Http::STATUS_FORBIDDEN);
        }

        // Check if consent is pending
        if (!$this->session->get('oidc_consent_pending')) {
            return new TemplateResponse(
                'core',
                'error',
                [
                    'errors' => [
                        ['error' => $this->l->t('No consent request pending.')],
                    ],
                ],
                TemplateResponse::RENDER_AS_ERROR,
                Http::STATUS_BAD_REQUEST
            );
        }

        // Get stored parameters from session
        $clientName = $this->session->get('oidc_client_name') ?? 'Unknown Application';
        $requestedScopes = $this->session->get('oidc_requested_scopes') ?? 'openid';
        $clientId = $this->session->get('oidc_client_id') ?? '';

        // Debug: Log key session values when showing consent
        $this->logger->debug('Showing consent page - oidc_consent_pending: ' . var_export($this->session->get('oidc_consent_pending'), true));
        $this->logger->debug('Showing consent page for client: ' . $clientName . ', scopes: ' . $requestedScopes);

        // Prepare parameters for template
        $parameters = [
            'clientName' => $clientName,
            'requestedScopes' => $requestedScopes,
            'clientId' => $clientId,
            'redirectTarget' => $this->redirectTarget((string)$this->session->get('oidc_redirect_uri')),

        ];

        return new TemplateResponse('oidc', 'consent', $parameters, TemplateResponse::RENDER_AS_USER);
    }

    /**
     * @NoAdminRequired
     * @UseSession
     *
     * Handle user granting consent
     */
    #[NoAdminRequired]
    #[UseSession]
    public function grant(): Response {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            $this->logger->warning('Consent grant attempt without being logged in');
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }

        // Debug: Log key session values
        $consentPending = $this->session->get('oidc_consent_pending');
        $this->logger->debug('Consent grant - oidc_consent_pending: ' . var_export($consentPending, true));
        $this->logger->debug('Consent grant - oidc_client_id: ' . var_export($this->session->get('oidc_client_id'), true));
        $this->logger->debug('Consent grant - oidc_client_name: ' . var_export($this->session->get('oidc_client_name'), true));

        // Check if consent is pending
        if (!$this->session->get('oidc_consent_pending')) {
            $this->logger->warning('Consent grant attempt without pending consent');
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }

        // Get parameters from request body (JSON)
        $requestBody = $this->request->getParam('scopes');
        if ($requestBody === null) {
            // Fallback: use all requested scopes if no selection provided
            $requestBody = $this->session->get('oidc_requested_scopes') ?? '';
        }

        if (!is_string($requestBody)) {
            return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
        }
        $grantedScopes = trim($requestBody);
        $clientId = $this->session->get('oidc_client_id');
        $uid = $this->userSession->getUser()->getUID();

        // Get client
        try {
            $client = $this->clientMapper->getByIdentifier($clientId);
        } catch (\Exception $e) {
            $this->logger->error('Client not found during consent grant: ' . $clientId);
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }

        $requestedScopeString = $this->session->get('oidc_requested_scopes') ?? '';
        $requestedScopes = preg_split('/ +/', trim($requestedScopeString), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grantedScopesArr = array_values(array_unique(preg_split('/ +/', $grantedScopes, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if (array_diff($grantedScopesArr, $requestedScopes) !== []) {
            return new JSONResponse(['error' => 'invalid_scope'], Http::STATUS_BAD_REQUEST);
        }
        if (in_array('openid', $requestedScopes, true) && !in_array('openid', $grantedScopesArr, true)) {
            $grantedScopesArr[] = 'openid';
        }
        $grantedScopes = implode(' ', $grantedScopesArr);

        // Store consent in database
        $consent = new UserConsent();
        $consent->setUserId($uid);
        $consent->setClientId($client->getId());
        $consent->setScopesGranted($grantedScopes);
        $consent->setScopesRequested($requestedScopeString);
        $consent->setCreatedAt($this->time->getTime());
        $consent->setUpdatedAt($this->time->getTime());
        // Set consent to expire after 90 days (7776000 seconds)
        // This provides a balance between security and user convenience
        $consent->setExpiresAt($this->time->getTime() + 7776000);

        $this->userConsentMapper->createOrUpdate($consent);

        $this->logger->info('User ' . $uid . ' granted consent to client ' . $clientId . ' with scopes: ' . $grantedScopes);

        // Consent uses the authenticated session; the original login request was
        // already resumed from its single-use database transaction.
        $freshLogin = $this->session->get('oidc_consent_fresh_login') === true;
        $this->session->set('oidc_consent_pending', false);
        $this->session->remove('oidc_consent_fresh_login');

        $response = $this->authorizationService->process([
            'client_id' => $this->session->get('oidc_client_id'),
            'scope' => $grantedScopes,
            'state' => $this->session->get('oidc_state'),
            'response_type' => $this->session->get('oidc_response_type'),
            'redirect_uri' => $this->session->get('oidc_redirect_uri'),
            'nonce' => $this->session->get('oidc_nonce'),
            'resource' => $this->session->get('oidc_resource'),
            'code_challenge' => $this->session->get('oidc_code_challenge'),
            'code_challenge_method' => $this->session->get('oidc_code_challenge_method'),
            'prompt' => $this->session->get('oidc_prompt'),
            'max_age' => $this->session->get('oidc_max_age'),
            'response_mode' => $this->session->get('oidc_response_mode'),
            'claims' => $this->session->get('oidc_claims'),
        ], $freshLogin, null, true);

        return $this->handoffAuthorizationRedirect($response);
    }

    /**
     * @NoAdminRequired
     *
     * Get all consents for the current user
     */
    #[NoAdminRequired]
    public function listUserConsents(): JSONResponse {
        if (!$this->userSession->isLoggedIn()) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $this->userSession->getUser()->getUID();
        $consents = $this->userConsentMapper->findByUserId($uid);

        $result = [];
        foreach ($consents as $consent) {
            try {
                $client = $this->clientMapper->getByUid($consent->getClientId());
                $result[] = [
                    'id' => $consent->getId(),
                    'clientId' => $consent->getClientId(),
                    'clientName' => $client->getName(),
                    'clientIdentifier' => $client->getClientIdentifier(),
                    'scopesGranted' => $consent->getScopesGranted(),
                    'scopesRequested' => $consent->getScopesRequested() ?? $consent->getScopesGranted(),
                    'expiresAt' => $consent->getExpiresAt(),
                    'allowedScopes' => $client->getAllowedScopes(),
                    'createdAt' => $consent->getCreatedAt(),
                    'updatedAt' => $consent->getUpdatedAt(),
                ];
            } catch (\Exception $e) {
                // Skip if client no longer exists
                $this->logger->warning('Consent references non-existent client: ' . $consent->getClientId());
            }
        }

        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     *
     * Revoke a user consent
     */
    #[NoAdminRequired]
    public function revokeConsent(int $clientId): JSONResponse {
        if (!$this->userSession->isLoggedIn()) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        // Check if user settings are allowed by administrator
        $allowUserSettings = $this->appConfig->getAppValueString(
            Application::APP_CONFIG_ALLOW_USER_SETTINGS,
            Application::DEFAULT_ALLOW_USER_SETTINGS
        );

        if ($allowUserSettings === 'no') {
            $this->logger->warning('User attempted to revoke consent but user settings are disabled by admin');
            return new JSONResponse(
                ['error' => 'Consent revocation is disabled by your administrator'],
                Http::STATUS_FORBIDDEN
            );
        }

        $uid = $this->userSession->getUser()->getUID();

        try {
            $this->userConsentMapper->deleteByUserAndClient($uid, $clientId);
            $this->accessTokenMapper->deleteByUserAndClient($uid, $clientId);
            $this->logger->info('User ' . $uid . ' revoked consent for client ID: ' . $clientId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Error revoking consent: ' . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to revoke consent'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     *
     * Update scopes for an existing consent
     */
    #[NoAdminRequired]
    public function updateScopes(int $clientId): JSONResponse {
        if (!$this->userSession->isLoggedIn()) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        // Check if user settings are allowed by administrator
        $allowUserSettings = $this->appConfig->getAppValueString(
            Application::APP_CONFIG_ALLOW_USER_SETTINGS,
            Application::DEFAULT_ALLOW_USER_SETTINGS
        );

        if ($allowUserSettings === 'no') {
            $this->logger->warning('User attempted to update consent scopes but user settings are disabled by admin');
            return new JSONResponse(
                ['error' => 'Consent modification is disabled by your administrator'],
                Http::STATUS_FORBIDDEN
            );
        }

        $uid = $this->userSession->getUser()->getUID();

        $scopes = $this->request->getParam('scopes');
        if (!is_array($scopes) || array_filter($scopes, static fn ($scope): bool =>
            !is_string($scope) || !preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $scope))) {
            return new JSONResponse(['error' => 'Invalid scopes format'], Http::STATUS_BAD_REQUEST);
        }
        $scopes = array_values(array_unique($scopes));
        if (strlen(implode(' ', $scopes)) > 512) {
            return new JSONResponse(['error' => 'Scope is too long'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $client = $this->clientMapper->getByUid($clientId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'Client not found'], Http::STATUS_NOT_FOUND);
        }
        $consent = $this->userConsentMapper->findByUserAndClient($uid, $clientId);
        if ($consent === null || ($consent->getExpiresAt() !== null && $this->time->getTime() >= $consent->getExpiresAt())) {
            return new JSONResponse(['error' => 'Consent not found or expired'], Http::STATUS_NOT_FOUND);
        }
        $requested = preg_split('/ +/', trim($consent->getScopesRequested() ?? $consent->getScopesGranted()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (in_array('openid', $requested, true) && !in_array('openid', $scopes, true)) {
            $scopes[] = 'openid';
        }
        if (strlen(implode(' ', $scopes)) > 512) {
            return new JSONResponse(['error' => 'Scope is too long'], Http::STATUS_BAD_REQUEST);
        }
        $allowed = preg_split('/ +/', trim($client->getAllowedScopes() ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (array_diff($scopes, $requested) !== [] || ($allowed !== [] && array_diff($scopes, $allowed) !== [])) {
            return new JSONResponse(['error' => 'Scope not requested or not allowed'], Http::STATUS_BAD_REQUEST);
        }

        if (!($this->clientAuthorizationService ?? Server::get(ClientAuthorizationService::class))->isUserAllowedForClient($this->userSession->getUser(), $client)) {
            return new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        }
        $permitted = ($this->scopeCeiling ?? Server::get(ScopeCeilingService::class))->narrow($uid, implode(' ', $scopes), $client->getAllowedScopes() ?? '', $client->getClientIdentifier());
        if ($permitted !== implode(' ', $scopes)) {
            return new JSONResponse(['error' => 'Scope not permitted'], Http::STATUS_FORBIDDEN);
        }
        // Log before update
        $oldScopes = $consent->getScopesGranted();
        $this->logger->info('Updating scopes for user ' . $uid . ', client ' . $clientId . ': ' . $oldScopes . ' -> ' . implode(' ', $scopes));

        // Update scopes
        $scopesString = implode(' ', $scopes);
        $consent->setScopesGranted($scopesString);
        $consent->setUpdatedAt($this->time->getTime());
        // Reset expiration to 90 days from now when consent is updated
        $consent->setExpiresAt($this->time->getTime() + 7776000);

        try {
            $updatedConsent = $this->userConsentMapper->createOrUpdate($consent);
            if (array_diff(preg_split('/ +/', $oldScopes, -1, PREG_SPLIT_NO_EMPTY) ?: [], $scopes) !== []) {
                // Existing bearer tokens must not keep permissions the user removed.
                $this->accessTokenMapper->deleteByUserAndClient($uid, $clientId);
            }
            $this->logger->info('Successfully updated scopes. DB now has: ' . $updatedConsent->getScopesGranted());

            return new JSONResponse([
                'success' => true,
                'scopesGranted' => $updatedConsent->getScopesGranted(),
                'updatedAt' => $updatedConsent->getUpdatedAt(),
                'expiresAt' => $updatedConsent->getExpiresAt()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error updating consent scopes: ' . $e->getMessage());
            return new JSONResponse(['error' => 'Failed to update scopes'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     * @UseSession
     *
     * Handle user denying consent
     */
    #[NoAdminRequired]
    #[UseSession]
    public function deny(): Response {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }

        if (!$this->session->get('oidc_consent_pending')) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }
        $parameters = [];
        foreach (['client_id', 'redirect_uri', 'state', 'response_type', 'response_mode'] as $name) {
            $parameters[$name] = $this->session->get('oidc_' . $name);
        }

        // Clear session
        $this->session->remove('oidc_consent_pending');
        $this->session->remove('oidc_consent_fresh_login');
        $this->session->remove('oidc_client_id');
        $this->session->remove('oidc_client_name');
        $this->session->remove('oidc_state');
        $this->session->remove('oidc_response_type');
        $this->session->remove('oidc_redirect_uri');
        $this->session->remove('oidc_scope');
        $this->session->remove('oidc_nonce');
        $this->session->remove('oidc_resource');
        $this->session->remove('oidc_code_challenge');
        $this->session->remove('oidc_code_challenge_method');
        $this->session->remove('oidc_prompt');
        $this->session->remove('oidc_max_age');
        $this->session->remove('oidc_response_mode');
        $this->session->remove('oidc_claims');
        $this->session->remove('oidc_requested_scopes');

        $response = $this->authorizationService->authorizationError($parameters, 'access_denied', 'User denied consent');
        return $this->handoffAuthorizationRedirect($response);
    }

    private function handoffAuthorizationRedirect(Response $response): Response {
        if (!$response instanceof RedirectResponse) {
            return $response;
        }

        $handoff = new TemplateResponse('oidc', 'authorization-handoff', [
            'continueUrl' => $response->getRedirectURL(),
            'continueLabel' => $this->l->t('Continue authorization'),
        ], TemplateResponse::RENDER_AS_GUEST);
        $handoff->addHeader('Cache-Control', 'no-store');
        $handoff->addHeader('Referrer-Policy', 'no-referrer');
        return $handoff;
    }
    private function consentErrorPage(string $message): TemplateResponse {
        return new TemplateResponse('core', 'error', [
            'errors' => [['error' => $message]],
        ], TemplateResponse::RENDER_AS_ERROR, Http::STATUS_BAD_REQUEST);
    }

    private function redirectTarget(string $uri): string {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'])) {
            return '';
        }
        return isset($parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : $parts['scheme'] . ':';
    }

}
