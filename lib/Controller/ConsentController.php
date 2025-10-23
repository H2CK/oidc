<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IL10N;
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
    /** @var UserConsentMapper */
    private $userConsentMapper;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var ITimeFactory */
    private $time;
    /** @var IL10N */
    private $l;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ISession $session,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        UserConsentMapper $userConsentMapper,
        ClientMapper $clientMapper,
        ITimeFactory $time,
        IL10N $l,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->session = $session;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->userConsentMapper = $userConsentMapper;
        $this->clientMapper = $clientMapper;
        $this->time = $time;
        $this->l = $l;
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
            ], 'error');
        }

        // Check if consent is pending
        if (!$this->session->get('oidc_consent_pending')) {
            return new TemplateResponse('core', '400', [
                'message' => $this->l->t('No consent request pending.')
            ], 'error');
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
    public function grant(): RedirectResponse {
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
            return new RedirectResponse($this->urlGenerator->getBaseUrl());
        }

        // Get parameters from request body (JSON)
        $requestBody = $this->request->getParam('scopes');
        if ($requestBody === null) {
            // Fallback: use all requested scopes if no selection provided
            $requestBody = $this->session->get('oidc_requested_scopes') ?? '';
        }

        $grantedScopes = trim($requestBody);
        $clientId = $this->session->get('oidc_client_id');
        $uid = $this->userSession->getUser()->getUID();

        // Get client
        try {
            $client = $this->clientMapper->getByIdentifier($clientId);
        } catch (\Exception $e) {
            $this->logger->error('Client not found during consent grant: ' . $clientId);
            return new RedirectResponse($this->urlGenerator->getBaseUrl());
        }

        // Validate granted scopes are a subset of requested scopes
        $requestedScopes = explode(' ', $this->session->get('oidc_requested_scopes') ?? '');
        $grantedScopesArr = explode(' ', $grantedScopes);
        foreach ($grantedScopesArr as $grantedScope) {
            if (!in_array($grantedScope, $requestedScopes)) {
                $this->logger->warning('User attempted to grant scope not in requested list: ' . $grantedScope);
                // Ignore invalid scopes
            }
        }

        // Ensure at least 'openid' scope is granted
        if (!in_array('openid', $grantedScopesArr)) {
            $grantedScopes = 'openid ' . $grantedScopes;
        }
        $grantedScopes = trim($grantedScopes);

        // Store consent in database
        $consent = new UserConsent();
        $consent->setUserId($uid);
        $consent->setClientId($client->getId());
        $consent->setScopesGranted($grantedScopes);
        $consent->setCreatedAt($this->time->getTime());
        $consent->setUpdatedAt($this->time->getTime());
        // No expiration for now (can be added later as admin config)
        $consent->setExpiresAt(null);

        $this->userConsentMapper->createOrUpdate($consent);

        $this->logger->info('User ' . $uid . ' granted consent to client ' . $clientId . ' with scopes: ' . $grantedScopes);

        // Clear consent pending flag (but keep OIDC params for authorize to continue)
        $this->session->set('oidc_consent_pending', false);

        // Update the scope in session to use granted scopes instead of requested
        $this->session->set('oidc_scope', $grantedScopes);

        // Build authorize URL BEFORE closing session (need to read oidc_client_id)
        $authorizeUrl = $this->urlGenerator->linkToRoute('oidc.LoginRedirector.authorize', [
            'client_id' => $this->session->get('oidc_client_id'),
            'scope' => $grantedScopes,
        ]);

        // IMPORTANT: Close the session to commit changes before redirecting
        // Without this, the authorize endpoint won't see the updated session values
        $this->session->close();

        // Redirect back to authorize endpoint to complete the flow
        // LoginRedirectorController will read all parameters from session (fallback mechanism)
        return new RedirectResponse($authorizeUrl);
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

        $uid = $this->userSession->getUser()->getUID();

        try {
            $this->userConsentMapper->deleteByUserAndClient($uid, $clientId);
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

        $uid = $this->userSession->getUser()->getUID();

        // Get scopes from request
        $scopes = $this->request->getParam('scopes');
        if (!is_array($scopes)) {
            return new JSONResponse(['error' => 'Invalid scopes format'], Http::STATUS_BAD_REQUEST);
        }

        // Ensure openid is always included (mandatory scope)
        if (!in_array('openid', $scopes)) {
            $scopes[] = 'openid';
        }

        // Get the client to validate allowed scopes
        try {
            $client = $this->clientMapper->getByUid($clientId);
        } catch (\Exception $e) {
            $this->logger->error('Client not found during scope update: ' . $clientId);
            return new JSONResponse(['error' => 'Client not found'], Http::STATUS_NOT_FOUND);
        }

        // Validate all scopes are in client's allowedScopes
        $allowedScopes = explode(' ', $client->getAllowedScopes());
        foreach ($scopes as $scope) {
            if (!in_array($scope, $allowedScopes)) {
                $this->logger->warning('User attempted to enable scope not allowed by client: ' . $scope);
                return new JSONResponse(
                    ['error' => 'Scope not allowed: ' . $scope],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        // Get existing consent
        $consent = $this->userConsentMapper->findByUserAndClient($uid, $clientId);

        if ($consent === null) {
            $this->logger->error('Consent not found for update - user: ' . $uid . ', client: ' . $clientId);
            return new JSONResponse(['error' => 'Consent not found'], Http::STATUS_NOT_FOUND);
        }

        // Update scopes
        $scopesString = implode(' ', $scopes);
        $consent->setScopesGranted($scopesString);
        $consent->setUpdatedAt($this->time->getTime());

        try {
            $this->userConsentMapper->createOrUpdate($consent);
            $this->logger->info('User ' . $uid . ' updated scopes for client ID ' . $clientId . ' to: ' . $scopesString);

            return new JSONResponse([
                'success' => true,
                'scopesGranted' => $scopesString,
                'updatedAt' => $consent->getUpdatedAt()
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
    public function deny(): RedirectResponse {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }

        $redirectUri = $this->session->get('oidc_redirect_uri');
        $state = $this->session->get('oidc_state');
        $clientId = $this->session->get('oidc_client_id');
        $uid = $this->userSession->getUser()->getUID();

        $this->logger->info('User ' . $uid . ' denied consent to client ' . $clientId);

        // Clear session
        $this->session->remove('oidc_consent_pending');
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
        $this->session->remove('oidc_requested_scopes');

        // Return error to client
        if (!empty($redirectUri)) {
            $separator = str_contains($redirectUri, '?') ? '&' : '?';
            $url = $redirectUri . $separator . 'error=access_denied&error_description=User%20denied%20consent';
            if (!empty($state)) {
                $url .= '&state=' . urlencode($state);
            }
            return new RedirectResponse($url);
        } else {
            // No redirect URI, just show error
            return new RedirectResponse($this->urlGenerator->getBaseUrl());
        }
    }
}
