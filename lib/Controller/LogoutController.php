<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2025 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
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
     * @param string $refresh_token
     * @return Response
     */
    #[BruteForceProtection(action: 'oidc_logout')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function logout(
                    $client_id, // Optional
                    $refresh_token, // Not standardized - deprecated will not be used any more
                    $id_token_hint, // Recommended to be used
                    $post_logout_redirect_uri // Optional url to be redirected to after logout
                    ): Response
    {
        $userId = null;
        if ($id_token_hint) {
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
            } catch (InvalidArgumentException $e) {
                // provided key/key-array is empty or malformed.
                $this->logger->error('Provided key/key-array is empty or malformed.');
                return new JSONResponse([
                    'error' => 'invalid_jwt',
                    'error_description' => 'Provided key/key-array is empty or malformed.'
                ], Http::STATUS_UNAUTHORIZED);
            } catch (DomainException $e) {
                // provided algorithm is unsupported OR
                // provided key is invalid OR
                // unknown error thrown in openSSL or libsodium OR
                // libsodium is required but not available.
                $this->logger->error('Provided algorithm is unsupported OR provided key is invalid OR unknown error thrown in openSSL or libsodium OR libsodium is required but not available.');
                return new JSONResponse([
                    'error' => 'invalid_jwt',
                    'error_description' => 'Provided algorithm is unsupported OR provided key is invalid OR unknown error thrown in openSSL or libsodium OR libsodium is required but not available.'
                ], Http::STATUS_UNAUTHORIZED);
            } catch (SignatureInvalidException $e) {
                // provided JWT signature verification failed.
                $this->logger->error('Provided JWT signature verification failed.');
                return new JSONResponse([
                    'error' => 'invalid_jwt',
                    'error_description' => 'Provided JWT signature verification failed.'
                ], Http::STATUS_UNAUTHORIZED);
            } catch (BeforeValidException $e) {
                // provided JWT is trying to be used before "nbf" claim OR
                // provided JWT is trying to be used before "iat" claim.
                $this->logger->error('Provided JWT is trying to be used before "nbf" claim OR provided JWT is trying to be used before "iat" claim.');
                return new JSONResponse([
                    'error' => 'invalid_jwt',
                    'error_description' => 'Provided JWT is trying to be used before "nbf" claim OR provided JWT is trying to be used before "iat" claim.'
                ], Http::STATUS_UNAUTHORIZED);
            } catch (ExpiredException $e) {
                // provided JWT is trying to be used after "exp" claim.
                // $this->logger->error('Provided JWT is trying to be used after "exp" claim.');
                // return new JSONResponse([
                //   'error' => 'invalid_jwt',
                //   'error_description' => 'Provided JWT is trying to be used after "exp" claim.'
                // ], Http::STATUS_UNAUTHORIZED);
            } catch (UnexpectedValueException $e) {
                // provided JWT is malformed OR
                // provided JWT is missing an algorithm / using an unsupported algorithm OR
                // provided JWT algorithm does not match provided key OR
                // provided key ID in key/key-array is empty or invalid.
                $this->logger->error('Provided JWT is malformed OR provided JWT is missing an algorithm / using an unsupported algorithm OR provided JWT algorithm does not match provided key OR provided key ID in key/key-array is empty or invalid.');
                return new JSONResponse([
                    'error' => 'invalid_jwt',
                    'error_description' => 'Provided JWT is malformed OR provided JWT is missing an algorithm / using an unsupported algorithm OR provided JWT algorithm does not match provided key OR provided key ID in key/key-array is empty or invalid.'
                ], Http::STATUS_UNAUTHORIZED);
            }

            if ($decodedJwt != null) {
                $uid = $decodedJwt['preferred_username'];
                $this->logger->notice('JWT token for uid ' . $uid . ' received.' );
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

        if ($this->userSession !== null && $this->userSession->isLoggedIn()) {
            $userId = $this->userSession->getUser()->getUID();
            // Logout user from session
            $this->userSession->logout();
        }

        $this->accessTokenMapper->deleteByUserId($userId);

        $this->logger->debug('Logout for user ' . $userId . ' performed.');

        $defaultLogoutRedirectUrl = $this->urlGenerator->linkToRoute(
                        'core.login.showLoginForm',
                        [
                        ]
        );

        if ($post_logout_redirect_uri) {
            $logoutRedirectUris = $this->logoutRedirectUriMapper->getAll();
            foreach ($logoutRedirectUris as $logoutRedirectUri) {
                if (str_starts_with($post_logout_redirect_uri, $logoutRedirectUri->getRedirectUri())) {
                    return new RedirectResponse($post_logout_redirect_uri);
                }
            }
        }

        return new RedirectResponse($defaultLogoutRedirectUrl);

    }
}
