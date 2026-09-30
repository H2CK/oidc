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
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class AuthorizationResumeController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IL10N $l,
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

        $transaction = $this->transactions->consume($t ?? '');
        if ($transaction === null) {
            return $this->error($this->l->t('Authorization session expired. Please try again.'), Http::STATUS_BAD_REQUEST);
        }

        return $this->authorizationService->process($transaction['parameters'], true);
    }

    private function error(string $message, int $status): TemplateResponse {
        return new TemplateResponse('core', 'error', [
            'errors' => [['error' => $message]],
        ], TemplateResponse::RENDER_AS_ERROR, $status);
    }
}
