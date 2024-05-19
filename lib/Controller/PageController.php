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

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\ISession;
use OCP\Util;
use OC_App;
use OCP\AppFramework\Utility\ITimeFactory;

class PageController extends Controller {
    /** @var ISession */
	private $session;
	/** @var IL10N */
	private $l;
	/** @var ITimeFactory */
	private $time;
	/** @var IUserSession */
	private $userSession;

	public function __construct(
					string $appName,
					IRequest $request,
					ISession $session,
					IL10N $l,
					ITimeFactory $time,
					IUserSession $userSession
					)
	{
		parent::__construct($appName, $request);
        $this->session = $session;
        $this->l = $l;
        $this->time = $time;
        $this->userSession = $userSession;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
     * @UseSession
	 * @BruteForceProtection(action=oidc_page)
	 *
	 * Render default template
	 */
	#[BruteForceProtection(action: 'oidc_page')]
	public function index()
	{
        $client_id = $this->session->get('client_id');
		$state = $this->session->get('state');
		$response_type = $this->session->get('response_type');
		$redirect_uri = $this->session->get('redirect_uri');
		$scope = $this->session->get('scope');

        $parameters = [
            'client_id' => $client_id,
            'state' => $state,
            'response_type' => $response_type,
            'redirect_uri' => $redirect_uri,
            'scope' => $scope
        ];

		return new TemplateResponse('oidc', 'main', $parameters);
	}
}
