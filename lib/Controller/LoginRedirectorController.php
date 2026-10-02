<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;

class LoginRedirectorController extends ApiController {
    private const PARAMETERS = [
        'client_id', 'state', 'response_type', 'redirect_uri', 'scope', 'nonce',
        'resource', 'code_challenge', 'code_challenge_method', 'prompt', 'max_age',
        'claims', 'response_mode', 'request', 'request_uri',
    ];

    public function __construct(
        string $appName, IRequest $request, private AuthorizationService $authorizationService,
        private ?FormUrlencodedParameterParser $parameterParser = null,
        private ?AuthorizationTransactionService $transactions = null,
        private ?IURLGenerator $urlGenerator = null,
    ) {
        parent::__construct($appName, $request);
        $this->parameterParser ??= new FormUrlencodedParameterParser();
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function authorizePost(
        $client_id = null, $state = null, $response_type = null, $redirect_uri = null, $scope = null, $nonce = null,
        $resource = null, $code_challenge = null, $code_challenge_method = null,
        $prompt = null, $max_age = null
    ): Response {
        $contentType = strtolower(trim(explode(';', $this->request->getHeader('Content-Type'), 2)[0]));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            return $this->invalidRequest('Authorization POST requests must use application/x-www-form-urlencoded.');
        }
        $parameters = $this->parseParameters(compact(
            'client_id', 'state', 'response_type', 'redirect_uri', 'scope', 'nonce',
            'resource', 'code_challenge', 'code_challenge_method', 'prompt', 'max_age'
        ), true);
        if ($parameters instanceof Response) {
            return $parameters;
        }
        $error = $this->authorizationService->validateHandoff($parameters);
        if ($error !== null) {
            return $error;
        }

        // End the inbound form navigation before any external redirect. A new
        // GET also lets the browser attach its Nextcloud SameSite session cookie.
        $transactions = $this->transactions ?? Server::get(AuthorizationTransactionService::class);
        $urlGenerator = $this->urlGenerator ?? Server::get(IURLGenerator::class);
        $id = $transactions->create($parameters, 'authorization_post');
        $response = new TemplateResponse('oidc', 'authorization-handoff', [
            'continueUrl' => $urlGenerator->linkToRoute('oidc.AuthorizationResume.completePost', ['t' => $id]),
            'continueLabel' => 'Continue authorization',
        ], TemplateResponse::RENDER_AS_GUEST);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[NoCSRFRequired]
    #[UseSession]
    #[PublicPage]
    public function authorize(
        $client_id = null, $state = null, $response_type = null, $redirect_uri = null, $scope = null, $nonce = null,
        $resource = null, $code_challenge = null, $code_challenge_method = null,
        $prompt = null, $max_age = null
    ): Response {
        $parameters = $this->parseParameters(compact(
            'client_id', 'state', 'response_type', 'redirect_uri', 'scope', 'nonce',
            'resource', 'code_challenge', 'code_challenge_method', 'prompt', 'max_age'
        ), false);
        return $parameters instanceof Response ? $parameters : $this->authorizationService->process($parameters);
    }

    /** @return array<string, mixed>|Response */
    private function parseParameters(array $fallback, bool $post): array|Response {
        $query = $this->parameterParser->parseSelectedQueryParameters($this->request->getRequestUri(), self::PARAMETERS);
        $body = $post ? $this->parameterParser->readSelectedParameters(self::PARAMETERS) : [];
        if ($body === null) {
            return $this->invalidRequest('Unable to read the authorization request body.');
        }
        $occurrences = $this->parameterParser->mergeParameterSets($query, $body);
        $parameters = [];
        foreach (self::PARAMETERS as $name) {
            $values = $occurrences[$name] ?? [];
            if (count($values) > 1) {
                // Ambiguous client/redirect fields must never be used for an error redirect.
                return $this->invalidRequest('Parameter ' . $name . ' must not occur more than once.');
            }
            $value = $values[0] ?? ($fallback[$name] ?? $this->request->getParam($name));
            if ($value !== null && !is_string($value)) {
                return $this->invalidRequest('Parameter ' . $name . ' must be a string.');
            }
            $parameters[$name] = $value === '' && $name !== 'state' ? null : $value;
        }
        try {
            if (strlen(json_encode($parameters, JSON_THROW_ON_ERROR)) > 32768) {
                return $this->invalidRequest('Authorization request is too large.');
            }
        } catch (\JsonException $e) {
            return $this->invalidRequest('Authorization parameters must use valid UTF-8.');
        }
        return $parameters;
    }

    private function invalidRequest(string $message): TemplateResponse {
        $response = new TemplateResponse('core', 'error', [
            'errors' => [['error' => $message]],
        ], TemplateResponse::RENDER_AS_ERROR, Http::STATUS_BAD_REQUEST);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
}
