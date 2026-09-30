<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class LoginRedirectorController extends ApiController {
    public function __construct(string $appName, IRequest $request, private AuthorizationService $authorizationService) {
        parent::__construct($appName, $request);
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function authorizePost(
        $client_id, $state, $response_type, $redirect_uri, $scope, $nonce,
        $resource = null, $code_challenge = null, $code_challenge_method = null,
        $prompt = null, $max_age = null
    ): Response {
        return $this->authorize(
            $client_id, $state, $response_type, $redirect_uri, $scope, $nonce,
            $resource, $code_challenge, $code_challenge_method, $prompt, $max_age
        );
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function authorize(
        $client_id, $state, $response_type, $redirect_uri, $scope, $nonce,
        $resource = null, $code_challenge = null, $code_challenge_method = null,
        $prompt = null, $max_age = null
    ): Response {
        return $this->authorizationService->process([
            'client_id' => $client_id,
            'state' => $state,
            'response_type' => $response_type,
            'redirect_uri' => $redirect_uri,
            'scope' => $scope,
            'nonce' => $nonce,
            'resource' => $resource,
            'code_challenge' => $code_challenge,
            'code_challenge_method' => $code_challenge_method,
            'prompt' => $prompt ?? $this->request->getParam('prompt'),
            'max_age' => $max_age ?? $this->request->getParam('max_age'),
            'claims' => $this->request->getParam('claims'),
            'response_mode' => $this->request->getParam('response_mode'),
            'request' => $this->request->getParam('request'),
            'request_uri' => $this->request->getParam('request_uri'),
        ]);
    }
}
