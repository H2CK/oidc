<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

require_once __DIR__ . '/../../vendor/autoload.php';

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class LogoutController extends ApiController {
    private const LOGOUT_CONFIRMATION_SESSION_KEY = 'oidc_logout_confirmation_v1';
    private const LOGOUT_CONFIRMATION_TTL = 300;

    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private ClientMapper $clientMapper,
        private ISession $session,
        private IL10N $l,
        private ITimeFactory $time,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private LogoutRedirectUriMapper $logoutRedirectUriMapper,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ISecureRandom $secureRandom,
        private BackChannelLogoutService $backChannelLogoutService,
        private FrontChannelLogoutService $frontChannelLogoutService,
        private SessionManagementService $sessionManagementService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Build a logout redirect URL with optional state parameter.
     */
    private function buildLogoutRedirectUrl(string $url, ?string $state): string {
        if ($state !== null && $state !== '') {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . 'state=' . urlencode($state);
        }
        return $url;
    }

    private function buildLogoutRedirect(string $url, ?string $state): RedirectResponse {
        return new RedirectResponse($this->buildLogoutRedirectUrl($url, $state));
    }

    /** @param list<string> $frontChannelUris */
    private function completeBrowserLogout(array $frontChannelUris, string $redirectUrl): Response {
        if ($frontChannelUris === []) {
            return new RedirectResponse($redirectUrl);
        }

        $frames = '';
        $frameOrigins = [];
        foreach ($frontChannelUris as $uri) {
            $frames .= '<iframe src="' . htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:none" aria-hidden="true"></iframe>';
            $parts = parse_url($uri);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origin = strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']);
                if (isset($parts['port'])) {
                    $origin .= ':' . (int)$parts['port'];
                }
                $frameOrigins[$origin] = true;
            }
        }
        $escapedRedirect = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="1;url=' . $escapedRedirect . '"><title>Logout</title></head><body>' . $frames . '</body></html>';
        $response = new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->addHeader('Content-Security-Policy', "default-src 'none'; frame-src " . implode(' ', array_keys($frameOrigins)) . "; frame-ancestors 'none'; base-uri 'none'");
        return $response;
    }

    private function getDefaultLogoutRedirect(): RedirectResponse {
        return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', []));
    }

    private function getActiveUserId(): ?string {
        if (!$this->userSession->isLoggedIn()) {
            return null;
        }

        $user = $this->userSession->getUser();
        return $user?->getUID();
    }

    /**
     * Generate a short-lived, one-time confirmation token bound to the current
     * Nextcloud browser session. The public logout endpoint cannot use the
     * normal CSRF middleware because RPs must be able to call it directly.
     *
     * @param array{client_id:?string,post_logout_redirect_uri:?string,state:?string} $redirectContext
     */
    private function createLogoutConfirmationToken(string $userId, array $redirectContext): string {
        $token = $this->secureRandom->generate(
            64,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );
        $this->session->set(self::LOGOUT_CONFIRMATION_SESSION_KEY, [
            'token_hash' => hash('sha256', $token),
            'created_at' => $this->time->getTime(),
            'user_id' => $userId,
            'client_id' => $redirectContext['client_id'],
            'post_logout_redirect_uri' => $redirectContext['post_logout_redirect_uri'],
            'state' => $redirectContext['state'],
        ]);

        return $token;
    }

    /**
     * Consume the confirmation token and return the server-side redirect
     * context that was validated before the confirmation page was shown.
     *
     * @return array{client_id:?string,post_logout_redirect_uri:?string,state:?string}|null
     */
    private function consumeLogoutConfirmationToken(?string $token, string $userId): ?array {
        $stored = $this->session->get(self::LOGOUT_CONFIRMATION_SESSION_KEY);
        // Consume the token on every attempt to make it non-replayable.
        $this->session->remove(self::LOGOUT_CONFIRMATION_SESSION_KEY);

        if ($token === null || $token === '' || !is_array($stored)) {
            return null;
        }

        $storedHash = $stored['token_hash'] ?? null;
        $createdAt = $stored['created_at'] ?? null;
        $storedUserId = $stored['user_id'] ?? null;
        if (!is_string($storedHash) || !is_int($createdAt) || !is_string($storedUserId)) {
            return null;
        }

        if (!hash_equals($storedUserId, $userId)) {
            return null;
        }

        $age = $this->time->getTime() - $createdAt;
        if ($age < 0 || $age > self::LOGOUT_CONFIRMATION_TTL) {
            return null;
        }

        if (!hash_equals($storedHash, hash('sha256', $token))) {
            return null;
        }

        $clientId = $stored['client_id'] ?? null;
        $redirectUri = $stored['post_logout_redirect_uri'] ?? null;
        $state = $stored['state'] ?? null;
        if (($clientId !== null && !is_string($clientId))
            || ($redirectUri !== null && !is_string($redirectUri))
            || ($state !== null && !is_string($state))) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'post_logout_redirect_uri' => $redirectUri,
            'state' => $state,
        ];
    }

    private function resolveClient(?string $clientId): ?Client {
        if ($clientId === null || trim($clientId) === '') {
            return null;
        }

        try {
            $client = $this->clientMapper->getByIdentifier(trim($clientId));
            return $client instanceof Client ? $client : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isRegisteredPostLogoutRedirect(Client $client, string $redirectUri): bool {
        foreach ($this->logoutRedirectUriMapper->getEffectiveByClientId($client->getId()) as $registered) {
            if (hash_equals($registered->getRedirectUri(), $redirectUri)) {
                return true;
            }
        }
        return false;
    }

    /**
     * client_id plus an exact registered redirect URI is the independent
     * legitimacy signal used when no id_token_hint is available. If an OP
     * session is active, the user must still explicitly confirm logout.
     *
     * @return array{client_id:?string,post_logout_redirect_uri:?string,state:?string}
     */
    private function buildConfirmationRedirectContext(?string $clientId, ?string $redirectUri, ?string $state): array {
        $empty = [
            'client_id' => null,
            'post_logout_redirect_uri' => null,
            'state' => null,
        ];
        if ($redirectUri === null || $redirectUri === '') {
            return $empty;
        }

        $client = $this->resolveClient($clientId);
        if ($client === null || !$this->isRegisteredPostLogoutRedirect($client, $redirectUri)) {
            return $empty;
        }

        return [
            'client_id' => $client->getClientIdentifier(),
            'post_logout_redirect_uri' => $redirectUri,
            'state' => $state,
        ];
    }

    /**
     * Revalidate a stored confirmation context immediately before using it.
     */
    private function redirectFromConfirmationContext(array $context): ?RedirectResponse {
        $clientId = $context['client_id'] ?? null;
        $redirectUri = $context['post_logout_redirect_uri'] ?? null;
        $state = $context['state'] ?? null;
        if (!is_string($clientId) || !is_string($redirectUri) || $redirectUri === '') {
            return null;
        }

        $client = $this->resolveClient($clientId);
        if ($client === null || !$this->isRegisteredPostLogoutRedirect($client, $redirectUri)) {
            return null;
        }

        return $this->buildLogoutRedirect($redirectUri, is_string($state) ? $state : null);
    }

    /**
     * @param array{client_id:?string,post_logout_redirect_uri:?string,state:?string}|null $redirectContext
     */
    private function buildLogoutConfirmationResponse(string $userId, ?array $redirectContext = null): TemplateResponse {
        $redirectContext ??= [
            'client_id' => null,
            'post_logout_redirect_uri' => null,
            'state' => null,
        ];
        $token = $this->createLogoutConfirmationToken($userId, $redirectContext);

        $response = new TemplateResponse(
            Application::APP_ID,
            'logout_confirmation',
            [
                'action' => $this->urlGenerator->linkToRoute('oidc.Logout.logoutPost', []),
                'cancelUrl' => $this->urlGenerator->getAbsoluteURL('/'),
                'confirmationToken' => $token,
                'title' => $this->l->t('Confirm logout'),
                'message' => $this->l->t('A relying party requested to end your OpenID Connect session. Do you want to log out?'),
                'logoutLabel' => $this->l->t('Log out'),
                'cancelLabel' => $this->l->t('Cancel'),
            ],
            TemplateResponse::RENDER_AS_GUEST,
        );

        // The endpoint is public by design, but the confirmation itself is
        // session-bound and must never be cached or embedded.
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('X-Frame-Options', 'DENY');

        return $response;
    }

    private function invalidIdTokenHint(string $description): JSONResponse {
        return new JSONResponse([
            'error' => 'invalid_jwt',
            'error_description' => $description,
        ], Http::STATUS_UNAUTHORIZED);
    }

    /**
     * Validate an RP-Initiated Logout ID Token hint using the signing algorithm
     * configured for the identified RP. The unsigned payload is used only to
     * locate the RP/key; all security-relevant claims are read again from the
     * cryptographically verified token.
     *
     * An expired token can still be signature-validated and returned as
     * expired=true. The caller may use it for silent logout only when its sid
     * is still bound to the current OP/RP browser session.
     *
     * @return array{user_id: string, client: Client, sid: ?string, expired: bool}|JSONResponse
     */
    private function validateIdTokenHint(string $idTokenHint, ?string $clientId): array|JSONResponse {
        $parts = explode('.', $idTokenHint);
        if (count($parts) !== 3) {
            $this->logger->error('Could not decode id_token_hint: invalid JWT structure.');
            return $this->invalidIdTokenHint('Provided id_token_hint has invalid format');
        }

        try {
            $headerData = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 512, JSON_THROW_ON_ERROR);
            $unverifiedPayload = json_decode(JWT::urlsafeB64Decode($parts[1]), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Could not decode id_token_hint: ' . $e->getMessage());
            return $this->invalidIdTokenHint('Provided id_token_hint has invalid format');
        }

        if (!is_array($headerData) || !is_array($unverifiedPayload)) {
            return $this->invalidIdTokenHint('Provided id_token_hint has invalid format');
        }

        $algorithm = $headerData['alg'] ?? null;
        if (!is_string($algorithm) || !in_array($algorithm, ['RS256', 'HS256'], true)) {
            $this->logger->error('id_token_hint uses an unsupported signing algorithm.');
            return $this->invalidIdTokenHint('id_token_hint uses an unsupported signing algorithm');
        }

        $aud = $unverifiedPayload['aud'] ?? null;
        $resolvedClientId = $clientId;
        if ($resolvedClientId !== null) {
            $matchesAudience = is_string($aud)
                ? hash_equals($aud, $resolvedClientId)
                : is_array($aud) && in_array($resolvedClientId, $aud, true);
            if (!$matchesAudience) {
                return $this->invalidIdTokenHint('Provided client_id does not match the ID Token audience.');
            }
        } elseif (is_string($aud) && $aud !== '') {
            $resolvedClientId = $aud;
        } elseif (is_array($aud) && count($aud) === 1 && is_string(reset($aud)) && reset($aud) !== '') {
            $resolvedClientId = (string)reset($aud);
        } else {
            return $this->invalidIdTokenHint('Could not identify the relying party from id_token_hint.');
        }

        try {
            $client = $this->clientMapper->getByIdentifier($resolvedClientId);
        } catch (\Throwable $e) {
            $this->logger->error('Could not resolve the RP identified by id_token_hint.');
            return $this->invalidIdTokenHint('Unknown relying party in id_token_hint.');
        }
        if (!$client instanceof Client) {
            return $this->invalidIdTokenHint('Unknown relying party in id_token_hint.');
        }

        if (!hash_equals($client->getSigningAlg(), $algorithm)) {
            $this->logger->error('id_token_hint signing algorithm does not match the RP configuration.');
            return $this->invalidIdTokenHint('id_token_hint signing algorithm does not match the relying party configuration.');
        }

        if ($algorithm === 'HS256') {
            $clientSecret = $client->getSecret();
            if (!is_string($clientSecret) || trim($clientSecret) === '') {
                $this->logger->error('Cannot validate HS256 id_token_hint because the RP has no usable client secret.');
                return $this->invalidIdTokenHint('HS256 id_token_hint cannot be validated for this relying party.');
            }
            $verificationKey = new Key($clientSecret, 'HS256');
        } else {
            $ourKid = $this->appConfig->getAppValueString('kid');
            if (!empty($ourKid) && isset($headerData['kid']) && $headerData['kid'] !== $ourKid) {
                $this->logger->error('id_token_hint has an invalid kid.');
                return $this->invalidIdTokenHint('id_token_hint has invalid kid');
            }
            $oidcKey = [
                'kty' => 'RSA',
                'use' => 'sig',
                'key_ops' => ['verify'],
                'alg' => 'RS256',
                'kid' => $ourKid,
                'n' => $this->appConfig->getAppValueString('public_key_n'),
                'e' => $this->appConfig->getAppValueString('public_key_e'),
            ];
            $verificationKey = JWK::parseKeySet(['keys' => [$oidcKey]]);
        }

        $expired = false;
        try {
            $decodedJwt = (array)JWT::decode($idTokenHint, $verificationKey);
        } catch (ExpiredException $e) {
            // php-jwt raises ExpiredException only after parsing the token,
            // selecting the expected algorithm/key and verifying the signature.
            // Since v6.9 the exception carries that verified payload; v7.1 is
            // pinned by this project. RP-Initiated Logout may use an expired ID
            // Token hint only if the caller later correlates its sid to the
            // current OP/RP browser session.
            $decodedJwt = (array)$e->getPayload();
            $expired = true;
        } catch (InvalidArgumentException | DomainException | SignatureInvalidException | BeforeValidException | UnexpectedValueException $e) {
            $this->logger->error('Could not validate id_token_hint: ' . $e->getMessage());
            return $this->invalidIdTokenHint('Provided id_token_hint is invalid');
        }

        $ourIssuer = $this->request->getServerProtocol()
            . '://'
            . $this->request->getServerHost()
            . $this->urlGenerator->getWebroot();
        if (($decodedJwt['iss'] ?? null) !== $ourIssuer) {
            return $this->invalidIdTokenHint('id_token_hint has invalid issuer');
        }

        $verifiedAud = $decodedJwt['aud'] ?? null;
        $audienceMatches = is_string($verifiedAud)
            ? hash_equals($verifiedAud, $resolvedClientId)
            : is_array($verifiedAud) && in_array($resolvedClientId, $verifiedAud, true);
        if (!$audienceMatches) {
            return $this->invalidIdTokenHint('Provided client_id does not match the ID Token audience.');
        }

        $uid = $decodedJwt['sub'] ?? null;
        if (!is_string($uid) || $uid === '') {
            return $this->invalidIdTokenHint('Provided JWT does not contain a subject.');
        }
        if ($this->userManager->get($uid) === null) {
            return new JSONResponse([
                'error' => 'invalid_user',
                'error_description' => 'Provided user in JWT is unknown.',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $sid = $decodedJwt['sid'] ?? null;
        if ($sid !== null && !is_string($sid)) {
            return $this->invalidIdTokenHint('id_token_hint contains an invalid sid.');
        }

        return [
            'user_id' => $uid,
            'client' => $client,
            'sid' => $sid,
            'expired' => $expired,
        ];
    }


    #[BruteForceProtection(action: 'oidc_logout')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function logoutPost(
        string|null $client_id = null,
        string|null $id_token_hint = null,
        string|null $post_logout_redirect_uri = null,
        string|null $state = null,
        string|null $confirm_logout = null,
        string|null $logout_confirmation_token = null,
    ): Response {
        return $this->logout(
            $client_id,
            $id_token_hint,
            $post_logout_redirect_uri,
            $state,
            $confirm_logout,
            $logout_confirmation_token
        );
    }

    #[BruteForceProtection(action: 'oidc_logout')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function logout(
        string|null $client_id = null,
        string|null $id_token_hint = null,
        string|null $post_logout_redirect_uri = null,
        string|null $state = null,
        string|null $confirm_logout = null,
        string|null $logout_confirmation_token = null,
    ): Response {
        $activeUserId = $this->getActiveUserId();

        // The public endpoint intentionally bypasses the normal CSRF middleware
        // for RP calls. A user-confirmed logout therefore requires its own
        // short-lived, one-time token tied to this browser session.
        if ($confirm_logout === '1') {
            if ($activeUserId === null) {
                return $this->getDefaultLogoutRedirect();
            }
            $confirmationContext = $this->consumeLogoutConfirmationToken($logout_confirmation_token, $activeUserId);
            if ($confirmationContext === null) {
                return new JSONResponse([
                    'error' => 'invalid_request',
                    'error_description' => 'Logout confirmation is missing, expired, or has already been used.',
                ], Http::STATUS_BAD_REQUEST);
            }

            // Revalidate the RP-specific redirect immediately before logout.
            // No OAuth/OIDC grants are revoked here: RP-Initiated Logout ends
            // browser sessions and triggers registered logout notifications;
            // it is not a global grant-revocation endpoint.
            $confirmedRedirect = $this->redirectFromConfirmationContext($confirmationContext);
            $frontChannelUris = $this->frontChannelLogoutService->getLogoutUris($this->backChannelLogoutService->getCurrentClientSessions());
            $this->sessionManagementService->resetBrowserState();
            $this->userSession->logout();
            $targetUrl = $this->urlGenerator->linkToRoute('core.login.showLoginForm', []);
            if ($confirmedRedirect !== null && is_string($confirmationContext['post_logout_redirect_uri'] ?? null)) {
                $targetUrl = $this->buildLogoutRedirectUrl(
                    $confirmationContext['post_logout_redirect_uri'],
                    is_string($confirmationContext['state'] ?? null) ? $confirmationContext['state'] : null
                );
            }
            return $this->completeBrowserLogout($frontChannelUris, $targetUrl);
        }

        // RP-Initiated Logout requires explicit end-user confirmation when an
        // active OP session exists but no trustworthy id_token_hint identifies
        // the RP/User/session that requested the logout. When client_id plus an
        // exact registered post_logout_redirect_uri are supplied, remember that
        // independently validated redirect across the confirmation round-trip.
        if ($id_token_hint === null || $id_token_hint === '') {
            $redirectContext = $this->buildConfirmationRedirectContext($client_id, $post_logout_redirect_uri, $state);
            if ($activeUserId === null) {
                // The user is already logged out. A client_id plus an exact
                // registered redirect URI is sufficient independent evidence
                // that the requested destination is controlled by that RP.
                // There is no OP session left that requires user confirmation.
                return $this->redirectFromConfirmationContext($redirectContext)
                    ?? $this->getDefaultLogoutRedirect();
            }
            return $this->buildLogoutConfirmationResponse($activeUserId, $redirectContext);
        }

        $validated = $this->validateIdTokenHint($id_token_hint, $client_id);
        if ($validated instanceof JSONResponse) {
            return $activeUserId !== null
                ? $this->buildLogoutConfirmationResponse($activeUserId)
                : $validated;
        }

        $userId = $validated['user_id'];
        $client = $validated['client'];
        $sid = $validated['sid'];
        $expiredHint = $validated['expired'];

        if ($activeUserId !== null) {
            // A valid token for a different user, an old pre-upgrade sid, or an
            // RP not participating in this browser session must never cause a
            // silent logout. Ask the current user instead.
            if (!hash_equals($activeUserId, $userId)
                || !$this->backChannelLogoutService->isCurrentClientSession($client, $sid)) {
                $this->logger->warning('RP-Initiated Logout id_token_hint does not match the active OP/RP session.');
                return $this->buildLogoutConfirmationResponse($activeUserId);
            }

            // Only now is it safe to end the OP session. The Back-Channel
            // Logout listener observes this event, records recent sid state and
            // notifies participating RPs.
            $frontChannelUris = $this->frontChannelLogoutService->getLogoutUris($this->backChannelLogoutService->getCurrentClientSessions());
            $this->sessionManagementService->resetBrowserState();
            $this->userSession->logout();
        } else {
            // With no active OP browser session, only a session that this OP
            // actually logged out recently is trusted. This also enables the
            // specification's SHOULD-level acceptance of expired ID Token hints
            // for a recent RP session without turning arbitrary old hints into
            // logout authority.
            if (!$this->backChannelLogoutService->isRecentClientSession($client, $userId, $sid)) {
                $description = $expiredHint
                    ? 'Expired id_token_hint does not match a recent OP/RP session.'
                    : 'id_token_hint does not match a current or recent OP/RP session.';
                return $this->invalidIdTokenHint($description);
            }
        }

        $targetUrl = $this->urlGenerator->linkToRoute('core.login.showLoginForm', []);
        if (!empty($post_logout_redirect_uri)
            && $this->isRegisteredPostLogoutRedirect($client, $post_logout_redirect_uri)) {
            $targetUrl = $this->buildLogoutRedirectUrl($post_logout_redirect_uri, $state);
        }

        return $this->completeBrowserLogout($frontChannelUris ?? [], $targetUrl);
    }
}
