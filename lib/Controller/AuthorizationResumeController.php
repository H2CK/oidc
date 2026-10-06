<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class AuthorizationResumeController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IL10N $l,
        private IURLGenerator $urlGenerator,
        private AuthorizationTransactionService $transactions,
        private AuthorizationService $authorizationService,
    ) {
        parent::__construct($appName, $request);
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function resume(?string $t = null): Response {
        if (!$this->userSession->isLoggedIn() || $this->userSession->getUser() === null) {
            return $this->error($this->l->t('You must be logged in to continue authorization.'), Http::STATUS_FORBIDDEN);
        }
        if (!$this->transactions->isPending($t ?? '')) {
            return $this->error($this->l->t('Authorization session expired. Please try again.'), Http::STATUS_BAD_REQUEST);
        }

        // A redirect straight from the login POST through /resume to an
        // external RP can be blocked by the login page's CSP form-action.
        // Finish that form navigation on this page, then start a new GET
        // navigation to the server-side authorization continuation.
        $response = new TemplateResponse('oidc', 'authorization-handoff', [
            'continueUrl' => $this->urlGenerator->linkToRoute('oidc.AuthorizationResume.complete', ['t' => $t]),
            'continueLabel' => $this->l->t('Continue authorization'),
        ], TemplateResponse::RENDER_AS_GUEST);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function complete(?string $t = null): Response {
        if (!$this->userSession->isLoggedIn() || $this->userSession->getUser() === null) {
            return $this->error($this->l->t('You must be logged in to continue authorization.'), Http::STATUS_FORBIDDEN);
        }

        $transaction = $this->transactions->consume($t ?? '');
        if ($transaction === null) {
            return $this->error($this->l->t('Authorization session expired. Please try again.'), Http::STATUS_BAD_REQUEST);
        }

        if ($transaction['reason'] === 'authorization_post') {
            return $this->error($this->l->t('Invalid authorization continuation.'), Http::STATUS_BAD_REQUEST);
        }

        $response = $this->authorizationService->process($transaction['parameters'],
            $this->authorizationService->hasFreshAuthenticationSince($transaction['created_at']));
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }

    #[BruteForceProtection(action: 'oidc_login')]
    #[PublicPage]
    #[NoCSRFRequired]
    #[UseSession]
    public function completePost(?string $t = null): Response {
        $transaction = $this->transactions->consume($t ?? '', 'authorization_post');
        if ($transaction === null) {
            return $this->error($this->l->t('Authorization session expired. Please try again.'), Http::STATUS_BAD_REQUEST);
        }
        // This continuation does not establish a new authentication time and
        // must still honor prompt=login/select_account and max_age.
        $response = $this->authorizationService->process($transaction['parameters']);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }

    private function error(string $message, int $status): TemplateResponse {
        return new TemplateResponse('core', 'error', [
            'errors' => [['error' => $message]],
        ], TemplateResponse::RENDER_AS_ERROR, $status);
    }
}
