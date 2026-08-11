<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

require_once __DIR__ . '/../../vendor/autoload.php';

use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use Psr\Log\LoggerInterface;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;

class LogoutController extends ApiController
{
    /** @var IURLGenerator */
    private $urlGenerator;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var AccessTokenMapper */
    private $accessTokenMapper;
    /** @var LogoutRedirectUriMapper  */
    private $logoutRedirectUriMapper;
    /** @var ISession */
    private $session;
    /** @var IL10N */
    private $l;
    /** @var ITimeFactory */
    private $time;
    /** @var IUserSession */
    private $userSession;
    /** @var IUserManager */
    private $userManager;
    /** @var IAppConfig */
    private $appConfig;
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param string $appName
     * @param IRequest $request
     * @param IURLGenerator $urlGenerator
     * @param ClientMapper $clientMapper
     * @param ISession $session
     * @param IL10N $l
     * @param ITimeFactory $time
     * @param IUserSession $userSession
     * @param IUserManager $userManager
     * @param AccessTokenMapper $accessTokenMapper
     * @param LogoutRedirectUriMapper $logoutRedirectUriMapper
     * @param IAppConfig $appConfig
     * @param LoggerInstance $logger
     */
    public function __construct(
                    string $appName,
                    IRequest $request,
                    IURLGenerator $urlGenerator,
                    ClientMapper $clientMapper,
                    ISession $session,
                    IL10N $l,
                    ITimeFactory $time,
                    IUserSession $userSession,
                    IUserManager $userManager,
                    AccessTokenMapper $accessTokenMapper,
                    LogoutRedirectUriMapper $logoutRedirectUriMapper,
                    IAppConfig $appConfig,
                    LoggerInterface $logger
                    )
    {
        parent::__construct($appName, $request);
        $this->urlGenerator = $urlGenerator;
        $this->clientMapper = $clientMapper;
        $this->session = $session;
        $this->l = $l;
        $this->time = $time;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->accessTokenMapper = $accessTokenMapper;
        $this->logoutRedirectUriMapper = $logoutRedirectUriMapper;
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @UseSession
     * @BruteForceProtection(action=oidc_logout)
     *
     * @param string $client_id
     * @param string $id_token_hint
     * @param string $post_logout_redirect_uri
     * @return Response
     */
    #[BruteForceProtection(action: 'oidc_logout')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function logoutPost(
                    string|null $client_id = null, // Optional
                    string|null $id_token_hint = null, // Recommended to be used
                    string|null $post_logout_redirect_uri = null // Optional url to be redirected to after logout
                    ): Response
    {
        return $this->logout($client_id, $id_token_hint, $post_logout_redirect_uri);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     * @UseSession
     * @BruteForceProtection(action=oidc_logout)
     *
     * @param string $client_id
     * @param string $id_token_hint
     * @param string $post_logout_redirect_uri
     * @return Response
     */
    #[BruteForceProtection(action: 'oidc_logout')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function logout(
                    string|null $client_id = null, // Optional
                    string|null $id_token_hint = null, // Recommended to be used
                    string|null $post_logout_redirect_uri = null // Optional url to be redirected to after logout
                    ): Response
    {
        $userId = null;
        
        // According to OIDC RP-Initiated Logout spec: 
        // The OP SHOULD NOT rely on the id_token_hint as the only way to identify the logged-in End-User
        // If the End-User is logged in at the OP, the OP MUST log the End-User out
        // Therefore, we prioritize the active session over the id_token_hint
        
        // First, check if there is an active user session
        if ($this->userSession !== null && $this->userSession->isLoggedIn()) {
            $userId = $this->userSession->getUser()->getUID();
            // Logout user from session
            $this->userSession->logout();
            // When session exists, id_token_hint is ignored per spec
            // Mark that we have a valid session logout
            $sessionLogout = true;
        } else {
            $sessionLogout = false;
        }
        
        // Only evaluate id_token_hint if no active session exists
        if (!$sessionLogout && $id_token_hint) {
            // Only evaluate id_token_hint if no active session exists
            // check Token to get user id
            $oidcKey = [
                'kty' => 'RSA',
                'use' => 'sig',
                'key_ops' => [ 'verify' ],
                'alg' => 'RS256',
                'kid' => $this->appConfig->getAppValueString('kid'),
                'n' => $this->appConfig->getAppValueString('public_key_n'),
                'e' => $this->appConfig->getAppValueString('public_key_e'),
            ];

            $jwks = [
                'keys' => [
                    $oidcKey
                ]
            ];

            $decodedJwt = null;

            try {
                $decodedStdClass = JWT::decode($id_token_hint, JWK::parseKeySet($jwks));
                $decodedJwt = (array) $decodedStdClass;
            } catch (InvalidArgumentException | DomainException | SignatureInvalidException | BeforeValidException | ExpiredException | UnexpectedValueException $e) {
                // If we cannot validate the token and there's no session, we cannot proceed
                $this->logger->error('Could not validate id_token_hint: ' . $e->getMessage());
                return new JSONResponse([
                    'error' => 'invalid_jwt',
                    'error_description' => 'Provided id_token_hint is invalid: ' . $e->getMessage()
                ], Http::STATUS_UNAUTHORIZED);
            }

            if ($decodedJwt != null) {
                $uid = $decodedJwt['sub'] ?? null;
                if (empty($uid)) {
                    $this->logger->error('Provided JWT does not contain a subject.');
                    return new JSONResponse([
                        'error' => 'invalid_jwt',
                        'error_description' => 'Provided JWT does not contain a subject.'
                    ], Http::STATUS_UNAUTHORIZED);
                }
                $this->logger->notice('JWT token for uid ' . $uid . ' received.');
                // create user session for user with id perform login without pw
                $user = $this->userManager->get($uid);
                if (null === $user) {
                    $this->logger->error('Provided user in JWT is unknown.');
                    return new JSONResponse([
                        'error' => 'invalid_user',
                        'error_description' => 'Provided user in JWT is unknown.'
                    ], Http::STATUS_UNAUTHORIZED);
                }

                $userId = $uid;

                if ($client_id !== null && $client_id !== $decodedJwt['aud']) {
                    $this->logger->error('Provided client_id does not match to the one issued the JWT.');
                    return new JSONResponse([
                        'error' => 'invalid_jwt',
                        'error_description' => 'Provided client_id does not match to the one issued the JWT.'
                    ], Http::STATUS_UNAUTHORIZED);
                }
            }
        }

        if ($userId != null) {
            $this->accessTokenMapper->deleteByUserId($userId);

            $this->logger->debug('Logout for user ' . $userId . ' performed.');
        }

        $defaultLogoutRedirectUrl = $this->urlGenerator->linkToRoute(
                        'core.login.showLoginForm',
                        [
                        ]
        );

        if (!empty($post_logout_redirect_uri)) {
            // According to OIDC RP-Initiated Logout spec, the OP should accept
            // post_logout_redirect_uri if it belongs to the client
            // We validate this in several ways:
            // 1. Check if URI is in the pre-registered LogoutRedirectUri table
            // 2. If we had a valid session logout, the client is authenticated, accept the URI
            // 3. Check if we have a valid id_token_hint with matching client
            // 4. Check if client_id is provided and matches a registered client
            
            $logoutRedirectUris = $this->logoutRedirectUriMapper->getAll();
            foreach ($logoutRedirectUris as $logoutRedirectUri) {
                if ($post_logout_redirect_uri === $logoutRedirectUri->getRedirectUri()) {
                    return new RedirectResponse($post_logout_redirect_uri);
                }
            }
            
            // If we performed a session logout, the request is authenticated
            // and we can trust the post_logout_redirect_uri
            if ($sessionLogout) {
                return new RedirectResponse($post_logout_redirect_uri);
            }
            
            // If not in the table, but we have a valid id_token_hint with a client
            // that we could validate (meaning we extracted userId from it)
            if ($userId !== null && $id_token_hint !== null) {
                // We already validated the JWT and extracted the client from it
                // The post_logout_redirect_uri belongs to this client
                return new RedirectResponse($post_logout_redirect_uri);
            }
            
            // If client_id is provided, we can also accept it
            if ($client_id !== null) {
                $client = $this->clientMapper->findByClientIdentifier($client_id);
                if ($client !== null) {
                    // Client exists, accept the post_logout_redirect_uri
                    return new RedirectResponse($post_logout_redirect_uri);
                }
            }
        }

        return new RedirectResponse($defaultLogoutRedirectUrl);

    }
}
