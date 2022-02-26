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

class LogoutController extends ApiController {
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
	 */
	public function __construct(string $appName,
								IRequest $request,
								IURLGenerator $urlGenerator,
								ClientMapper $clientMapper,
								ISession $session,
								IL10N $l,
								ITimeFactory $time,
								IUserSession $userSession,
								AccessTokenMapper $accessTokenMapper) {
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->clientMapper = $clientMapper;
		$this->session = $session;
		$this->l = $l;
		$this->time = $time;
		$this->userSession = $userSession;
		$this->accessTokenMapper = $accessTokenMapper;
	}

	/**
	 * @CORS
     * @PublicPage
	 * @NoCSRFRequired
     * @UseSession
	 *
	 * @param string $client_id
	 * @param string $refresh_token
	 * @param string $response_type
	 * @param string $redirect_uri
	 * @param string $scope
	 * @return Response
	 */
	public function logout($client_id,
							$refresh_token): Response {
        try {
            $accessToken = $this->accessTokenMapper->getByCode($refresh_token);
        } catch (AccessTokenNotFoundException $e) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Could not find access token for code or refresh_token.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $client = $this->clientMapper->getByUid($accessToken->getClientId());
        } catch (ClientNotFoundException $e) {
            return new JSONResponse([
                'error' => 'invalid_request',
                'error_description' => 'Could not find client for access token.',
            ], Http::STATUS_BAD_REQUEST);
        }
        
        $this->accessTokenMapper->deleteByClientId($client->getId());
        
		if ($this->userSession !== null && $this->userSession->isLoggedIn()) {
			// Logout user from session
            $this->userSession->logout();
        }

        $logoutRedirectUrl = $this->urlGenerator->linkToRoute(
            'core.login.showLoginForm',
            [
            ]
        );
        return new RedirectResponse($logoutRedirectUrl);
			
    }
}
