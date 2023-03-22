<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
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

require __DIR__ . '/../../vendor/autoload.php';

use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IInitialStateService;
use OC_App;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Services\IAppConfig;
use Psr\Log\LoggerInterface;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use DomainException;
use InvalidArgumentException;
use UnexpectedValueException;

class LogoutController extends ApiController
{
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ClientMapper */
	private $clientMapper;
	/** @var AccessTokenMapper */
	private $accessTokenMapper;
	/** @var ISession */
	private $session;
	/** @var IL10N */
	private $l;
	/** @var ITimeFactory */
	private $time;
	/** @var IUserSession */
	private $userSession;
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
	 * @param AccessTokenMapper $accessTokenMapper
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
					AccessTokenMapper $accessTokenMapper,
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
		$this->accessTokenMapper = $accessTokenMapper;
		$this->appConfig = $appConfig;
		$this->logger = $logger;
	}

	/**
	 * @CORS
     * @PublicPage
	 * @NoCSRFRequired
     * @UseSession
	 *
	 * @param string $client_id
	 * @param string $refresh_token
	 * @return Response
	 */
	public function logout(
					$client_id,		// Optional
					$refresh_token, // Not standardized - deprecated will not be used any more
					$id_token_hint  // Recommended to be used
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
				'kid' => $this->appConfig->getAppValue('kid'),
				'n' => $this->appConfig->getAppValue('public_key_n'),
				'e' => $this->appConfig->getAppValue('public_key_e'),
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
				// 	'error' => 'invalid_jwt',
				// 	'error_description' => 'Provided JWT is trying to be used after "exp" claim.'
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

        $logoutRedirectUrl = $this->urlGenerator->linkToRoute(
            'core.login.showLoginForm',
            [
            ]
        );
        return new RedirectResponse($logoutRedirectUrl);

    }
}
