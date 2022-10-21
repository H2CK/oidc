<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Thorsten Jagel <dev@jagel.net>
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

use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
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
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use Psr\Log\LoggerInterface;

class LoginRedirectorController extends ApiController
{
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var ClientMapper */
	private $clientMapper;
	/** @var AccessTokenMapper */
	private $accessTokenMapper;
	/** @var RedirectUriMapper */
	private $redirectUriMapper;
	/** @var ISecureRandom */
	private $random;
	/** @var ICrypto */
	private $crypto;
	/** @var IProvider */
	private $tokenProvider;
	/** @var ISession */
	private $session;
	/** @var IL10N */
	private $l;
	/** @var ITimeFactory */
	private $time;
	/** @var IUserSession */
	private $userSession;
	/** @var IInitialStateService */
	private $initialStateService;
	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param ClientMapper $clientMapper
	 * @param ISecureRandom $random
	 * @param ICrypto $crypto
	 * @param IProvider $tokenProvider
	 * @param ISession $session
	 * @param IL10N $l
	 * @param ITimeFactory $time
	 * @param IUserSession $userSession
	 * @param AccessTokenMapper $accessTokenMapper
	 * @param RedirectUriMapper $redirectUriMapper
	 * @param IInitialStateService $initialStateService
	 * @param LoggerInterface $loggerInterface
	 */
	public function __construct(
					string $appName,
					IRequest $request,
					IURLGenerator $urlGenerator,
					ClientMapper $clientMapper,
					ISecureRandom $random,
					ICrypto $crypto,
					IProvider $tokenProvider,
					ISession $session,
					IL10N $l,
					ITimeFactory $time,
					IUserSession $userSession,
					AccessTokenMapper $accessTokenMapper,
					RedirectUriMapper $redirectUriMapper,
					IInitialStateService $initialStateService,
					LoggerInterface $logger
					)
		{
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->clientMapper = $clientMapper;
		$this->random = $random;
		$this->crypto = $crypto;
		$this->tokenProvider = $tokenProvider;
		$this->session = $session;
		$this->l = $l;
		$this->time = $time;
		$this->userSession = $userSession;
		$this->accessTokenMapper = $accessTokenMapper;
		$this->redirectUriMapper = $redirectUriMapper;
		$this->initialStateService = $initialStateService;
		$this->logger = $logger;
	}

	/**
	 * @CORS
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $client_id
	 * @param string $state
	 * @param string $response_type
	 * @param string $redirect_uri
	 * @param string $scope
	 * @param string $nonce
	 * @return Response
	 */
	public function authorize(
					$client_id,
					$state,
					$response_type,
					$redirect_uri,
					$scope,
					$nonce
					): Response
		{
		if (!$this->userSession->isLoggedIn()) {
			// Not authenticated yet
			// Store things in user session to be available after login
			$this->session->set('client_id', $client_id);
			$this->session->set('state', $state);
			$this->session->set('response_type', $response_type);
			$this->session->set('redirect_uri', $redirect_uri);
			$this->session->set('scope', $scope);
			$this->session->set('nonce', $nonce);

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
			$client_id = $this->session->get('client_id');
		}
		if (empty($state)) {
			$state = $this->session->get('state');
		}
		if (empty($response_type)) {
			$response_type = $this->session->get('response_type');
		}
		if (empty($redirect_uri)) {
			$redirect_uri = $this->session->get('redirect_uri');
		}
		if (empty($scope)) {
			$scope = $this->session->get('scope');
		}
		if (empty($nonce)) {
			$nonce = $this->session->get('nonce');
		}

		// Set default scope if scope is not set at all
		if (!isset($scope)) {
			$scope = 'openid profile email roles';
		}

		try {
			$client = $this->clientMapper->getByIdentifier($client_id);
		} catch (ClientNotFoundException $e) {
			$params = [
				'content' => $this->l->t('Your client is not authorized to connect. Please inform the administrator of your client.'),
			];
			$this->logger->notice('Client ' . $client_id . ' is not authorized to connect.');
			return new TemplateResponse('core', '404', $params, 'guest');
		}

		// Check if redirect uri is configured for client
		$redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
		$redirectUriFound = false;
		foreach ($redirectUris as $i => $redirectUri) {
			if ($redirect_uri === $redirectUri->getRedirectUri()) {
				$redirectUriFound = true;
				break;
			}
		}
		if (!$redirectUriFound) {
			$params = [
				'content' => $this->l->t('The received redirect URI is not accepted to connect. Please inform the administrator of your client.'),
			];
			$this->logger->notice('Redirect URI ' . $redirect_uri . ' is not accepted for client ' . $client_id . '.');
			return new TemplateResponse('core', '404', $params, 'guest');
		}

		if ($response_type !== 'code' && $response_type !== 'code id_token') {
			//Fail
			$url = $redirect_uri . '?error=unsupported_response_type&state=' . $state;
			return new RedirectResponse($url);
		}

		$accessTokenCode = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$uid = $this->userSession->getUser()->getUID();

		$code = $this->random->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$accessToken = new AccessToken();
		$accessToken->setClientId($client->getId());
		$accessToken->setUserId($uid);
		$accessToken->setAccessToken($accessTokenCode);
		$accessToken->setHashedCode(hash('sha512', $code));
		$accessToken->setScope(substr($scope, 0, 128));
		$accessToken->setCreated($this->time->getTime());
		$accessToken->setRefreshed($this->time->getTime());
		if (empty($nonce) || !isset($nonce)) {
			$nonce = '';
		} else {
			$nonce = substr($nonce, 0, 256);
		}
		$accessToken->setNonce($nonce);
		$this->accessTokenMapper->insert($accessToken);

		if (empty($state) || !isset($state)) {
			$state = '';
		}
		$url = $redirect_uri . '?code=' . $code . '&state=' . urlencode($state);
		if (str_contains($redirect_uri, '?')) {
			$url = $redirect_uri . '&code=' . $code . '&state=' . urlencode($state);
		}
		$this->logger->debug('Send redirect response for client ' . $client_id . '.');

		return new RedirectResponse($url);
	}
}
