<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\ISession;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
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
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function index()
    {
        $request = $this->request;

        // Prefer URL parameters (survive SAML session regeneration),
        // fall back to session values for backwards compatibility.
        $client_id     = $request->getParam('client_id')     ?? $this->session->get('oidc_client_id');
        $state         = $request->getParam('state')          ?? $this->session->get('oidc_state');
        $response_type = $request->getParam('response_type')  ?? $this->session->get('oidc_response_type');
        $redirect_uri  = $request->getParam('redirect_uri')   ?? $this->session->get('oidc_redirect_uri');
        $scope         = $request->getParam('scope')           ?? $this->session->get('oidc_scope');
        $resource      = $request->getParam('resource')        ?? $this->session->get('oidc_resource');


        $parameters = [
            'client_id' => $client_id,
            'state' => $state,
            'response_type' => $response_type,
            'redirect_uri' => $redirect_uri,
            'nonce' => $request->getParam('nonce') ?? $this->session->get('oidc_nonce'),
            'code_challenge' => $request->getParam('code_challenge') ?? $this->session->get('oidc_code_challenge'),
            'code_challenge_method' => $request->getParam('code_challenge_method') ?? $this->session->get('oidc_code_challenge_method'),
            'scope' => $scope,
            'resource' => $resource
        ];

        $response = new TemplateResponse('oidc', 'main', $parameters);
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Access-Control-Allow-Methods', 'GET');

        return $response;
    }
}
