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
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Service\ConsentRequestService;
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
        private ?DeviceCodeMapper $deviceCodeMapper = null,
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
    public function show(?string $t = null): TemplateResponse {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            return new TemplateResponse('core', '403', [
                'message' => $this->l->t('You must be logged in to view this page.')
            ], TemplateResponse::RENDER_AS_ERROR, Http::STATUS_FORBIDDEN);
        }

        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }

        $snapshot = (new ConsentRequestService($this->session, $this->time))->get($t, $uid);
        if ($snapshot === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }
        $request = $snapshot['parameters'];
        $parameters = [
            'clientName' => $snapshot['clientName'],
            'requestedScopes' => $request['scope'],
            'clientId' => $request['client_id'],
            'consentRequestId' => $t,
            'redirectTarget' => $this->redirectTarget((string)$request['redirect_uri']),
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
    public function grant(?string $t = null): Response {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            $this->logger->warning('Consent grant attempt without being logged in');
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }

        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }
        $requests = new ConsentRequestService($this->session, $this->time);
        $snapshot = $requests->get($t, $uid);
        if ($snapshot === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }
        $parameters = $snapshot['parameters'];

        // Get parameters from request body (JSON)
        $requestBody = $this->request->getParam('scopes');
        if ($requestBody === null) {
            // Fallback: use all requested scopes if no selection provided
            $requestBody = $parameters['scope'];
        }

        if (!is_string($requestBody)) {
            return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
        }
        $grantedScopes = trim($requestBody);
        $clientId = $parameters['client_id'];
        $uid = $this->userSession->getUser()->getUID();

        // Get client
        try {
            $client = $this->clientMapper->getByIdentifier($clientId);
        } catch (\Exception $e) {
            $this->logger->error('Client not found during consent grant: ' . $clientId);
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }

        $requestedScopeString = $parameters['scope'];
        $requestedScopes = preg_split('/ +/', trim($requestedScopeString), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grantedScopesArr = array_values(array_unique(preg_split('/ +/', $grantedScopes, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if (array_diff($grantedScopesArr, $requestedScopes) !== []) {
            return new JSONResponse(['error' => 'invalid_scope'], Http::STATUS_BAD_REQUEST);
        }
        if (in_array('openid', $requestedScopes, true) && !in_array('openid', $grantedScopesArr, true)) {
            $grantedScopesArr[] = 'openid';
        }
        $grantedScopes = implode(' ', $grantedScopesArr);

        if ($client === null || !($this->clientAuthorizationService ?? Server::get(ClientAuthorizationService::class))
            ->isUserAllowedForClient($this->userSession->getUser(), $client)) {
            return new JSONResponse(['error' => 'access_denied'], Http::STATUS_FORBIDDEN);
        }
        $grantedScopes = ($this->scopeCeiling ?? Server::get(ScopeCeilingService::class))->narrow(
            $uid, $grantedScopes, $client->getAllowedScopes() ?? '', $client->getClientIdentifier()
        );
        if ($grantedScopes === '' || (in_array('openid', $requestedScopes, true) && !in_array('openid', explode(' ', $grantedScopes), true))) {
            return new JSONResponse(['error' => 'invalid_scope'], Http::STATUS_BAD_REQUEST);
        }
        if ($requests->consume($t, $uid) === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }

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

        $parameters['scope'] = $grantedScopes;
        $response = $this->authorizationService->process($parameters, $snapshot['freshLogin'] === true, null, true);

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

        $this->userConsentMapper->beginChange($uid, $clientId);
        try {
            ($this->deviceCodeMapper ?? Server::get(DeviceCodeMapper::class))->denyApprovedByUserAndClient($uid, $clientId);
            $this->userConsentMapper->deleteByUserAndClient($uid, $clientId);
            $this->accessTokenMapper->deleteByUserAndClient($uid, $clientId);
            $this->userConsentMapper->commitChange();
            $this->logger->info('User ' . $uid . ' revoked consent for client ID: ' . $clientId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->userConsentMapper->rollbackChange();
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
        $oldUpdatedAt = $consent->getUpdatedAt();
        $this->logger->info('Updating scopes for user ' . $uid . ', client ' . $clientId . ': ' . $oldScopes . ' -> ' . implode(' ', $scopes));

        // Update scopes
        $scopesString = implode(' ', $scopes);
        $this->userConsentMapper->beginChange($uid, $clientId);
        try {
            // A concurrent revoke must not be undone by an update based on a stale row.
            $currentConsent = $this->userConsentMapper->findByUserAndClient($uid, $clientId);
            if ($currentConsent === null || $currentConsent->getId() !== $consent->getId()
                || $currentConsent->getUpdatedAt() !== $oldUpdatedAt || $currentConsent->getScopesGranted() !== $oldScopes) {
                $this->userConsentMapper->rollbackChange();
                return new JSONResponse(['error' => 'Consent changed. Reload and try again.'], Http::STATUS_CONFLICT);
            }
            $consent->setScopesGranted($scopesString);
            $consent->setUpdatedAt($this->time->getTime());
            $consent->setExpiresAt($this->time->getTime() + 7776000);
            $updatedConsent = $this->userConsentMapper->createOrUpdate($consent);
            if (array_diff(preg_split('/ +/', $oldScopes, -1, PREG_SPLIT_NO_EMPTY) ?: [], $scopes) !== []) {
                ($this->deviceCodeMapper ?? Server::get(DeviceCodeMapper::class))->denyApprovedByUserAndClient($uid, $clientId);
                // Existing bearer tokens must not keep permissions the user removed.
                $this->accessTokenMapper->deleteByUserAndClient($uid, $clientId);
            }
            $this->userConsentMapper->commitChange();
            $this->logger->info('Successfully updated scopes. DB now has: ' . $updatedConsent->getScopesGranted());

            return new JSONResponse([
                'success' => true,
                'scopesGranted' => $updatedConsent->getScopesGranted(),
                'updatedAt' => $updatedConsent->getUpdatedAt(),
                'expiresAt' => $updatedConsent->getExpiresAt()
            ]);
        } catch (\Exception $e) {
            $this->userConsentMapper->rollbackChange();
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
    public function deny(?string $t = null): Response {
        // Check if user is logged in
        if (!$this->userSession->isLoggedIn()) {
            return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm'));
        }

        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }

        $snapshot = (new ConsentRequestService($this->session, $this->time))->consume($t, $uid);
        if ($snapshot === null) {
            return $this->consentErrorPage($this->l->t('No consent request pending. Please start authorization again.'));
        }
        $parameters = $snapshot['parameters'];

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
