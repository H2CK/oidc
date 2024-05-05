<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
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
use OCP\IGroup;
use OCP\IGroupManager;
use OC_App;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
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
	/** @var IGroupManager */
	private $groupManager;
	/** @var IInitialStateService */
	private $initialStateService;
	/** @var IAppConfig */
	private $appConfig;
	/** @var JwtGenerator */
	private $jwtGenerator;
	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param ClientMapper $clientMapper
	 * @param GroupMapper $groupMapper
	 * @param ISecureRandom $random
	 * @param ICrypto $crypto
	 * @param IProvider $tokenProvider
	 * @param ISession $session
	 * @param IL10N $l
	 * @param ITimeFactory $time
	 * @param IUserSession $userSession
	 * @param IGroupManager $groupManager
	 * @param AccessTokenMapper $accessTokenMapper
	 * @param RedirectUriMapper $redirectUriMapper
	 * @param IInitialStateService $initialStateService
	 * @param IAppConfig $appConfig
	 * @param JwtGenerator $jwtGenerator
	 * @param LoggerInterface $loggerInterface
	 */
	public function __construct(
					string $appName,
					IRequest $request,
					IURLGenerator $urlGenerator,
					ClientMapper $clientMapper,
					GroupMapper $groupMapper,
					ISecureRandom $random,
					ICrypto $crypto,
					IProvider $tokenProvider,
					ISession $session,
					IL10N $l,
					ITimeFactory $time,
					IUserSession $userSession,
					IGroupManager $groupManager,
					AccessTokenMapper $accessTokenMapper,
					RedirectUriMapper $redirectUriMapper,
					IInitialStateService $initialStateService,
					IAppConfig $appConfig,
					JwtGenerator $jwtGenerator,
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
		$this->crypto = $crypto;
		$this->tokenProvider = $tokenProvider;
		$this->session = $session;
		$this->l = $l;
		$this->time = $time;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->accessTokenMapper = $accessTokenMapper;
		$this->redirectUriMapper = $redirectUriMapper;
		$this->initialStateService = $initialStateService;
		$this->appConfig = $appConfig;
		$this->jwtGenerator = $jwtGenerator;
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
	 * @return Response
	 */
	#[BruteForceProtection(action: 'oidc_login')]
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
				'message' => $this->l->t('Your client is not authorized to connect. Please inform the administrator of your client.'),
			];
			$this->logger->notice('Client ' . $client_id . ' is not authorized to connect.');
			return new TemplateResponse('core', '403', $params, 'error');
		}

		// The client must not be expired
		if ($client->isDcr() && $this->time->getTime() > ($client->getIssuedAt() + $this->appConfig->getAppValue('client_expire_time', '3600'))) {
			$this->logger->warning('Client expired. Client id was ' . $client_id . '.');
			$params = [
				'message' => $this->l->t('Your client is expired. Please inform the administrator of your client.'),
			];
			return new TemplateResponse('core', '400', $params, 'error');
		}

		// Check if redirect URI is configured for client
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
				'message' => $this->l->t('The received redirect URI is not accepted to connect. Please inform the administrator of your client.'),
			];
			$this->logger->notice('Redirect URI ' . $redirect_uri . ' is not accepted for client ' . $client_id . '.');
			return new TemplateResponse('core', '403', $params, 'error');
		}

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
			$url = $redirect_uri . '?error=request_not_supported&error_description=Missing%20nonce&state=' . $state;
			return new RedirectResponse($url);
		}
		if (in_array('token', $responseTypeEntries) && !in_array('id_token', $responseTypeEntries)) {
			// array_push($responseTypeEntries, 'id_token'); // Add ID token by default
			$url = $redirect_uri . '?error=request_not_supported&error_description=Missing%20id_token&state=' . $state;
			return new RedirectResponse($url);
		}

		$allowedResponseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
		$isImplicitFlowAllowed = false;
		if (in_array('id_token', $allowedResponseTypeEntries)) {
			$isImplicitFlowAllowed = true;
		}
		if (($implicitFlow && !$isImplicitFlowAllowed) || (!$codeFlow && !$implicitFlow)) {
			// $params = [
			// 	'message' => $this->l->t('The received response_type is not accepted. Please inform the administrator of your client.'),
			// ];
			// $this->logger->notice('Response_type ' . $response_type . ' from client ' . $client_id . ' is not accepted.');
			// return new TemplateResponse('core', '403', $params, 'error');
			//Fail - Instead Template Response???
			$url = $redirect_uri . '?error=unsupported_response_type&state=' . $state;
			return new RedirectResponse($url);
		}

		// Check if user is in allowed groups for client
		$clientGroups = $this->groupMapper->getGroupsByClientId($client->getId());
		$userGroups = $this->groupManager->getUserGroups($this->userSession->getUser());

		$groupFound = false;
		if (count($clientGroups) < 1) { $groupFound = true; }
		foreach ($clientGroups as $i => $clientGroup) {
			foreach ($userGroups as $j => $userGroup) {
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

		$expireTime = $this->appConfig->getAppValue('expire_time');

		$url = $redirect_uri . '?state=' . urlencode($state);
		if (str_contains($redirect_uri, '?')) {
			$url = $redirect_uri . '&state=' . urlencode($state);
		}
		if (in_array('code', $responseTypeEntries)) {
			$url = $url . '&code=' . $code;
		}
		if (in_array('token', $responseTypeEntries)) {
			$url = $url . '&access_token=' . $accessTokenCode;
		}
		if (in_array('id_token', $responseTypeEntries)) {
			$jwt = $this->jwtGenerator->generateIdToken($accessToken, $client, $this->request, in_array('token', $responseTypeEntries));
			$url = $url . '&id_token=' . $jwt;
		}
		if (in_array('id_token', $responseTypeEntries) || in_array('token', $responseTypeEntries)) {
			$url = $url . '&token_type=Bearer&expires_in=' . $expireTime . '&scope=' . $scope;
		}

		$this->logger->debug('Send redirect response for client ' . $client_id . '.');

		return new RedirectResponse($url);
	}
}
