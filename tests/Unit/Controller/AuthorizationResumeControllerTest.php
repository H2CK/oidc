<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use OCA\OIDCIdentityProvider\Controller\AuthorizationResumeController;
use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AuthorizationResumeControllerTest extends TestCase {
    private IUserSession $userSession;
    private AuthorizationTransactionService $transactions;
    private AuthorizationService $authorizationService;
    private AuthorizationResumeController $controller;

    protected function setUp(): void {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->transactions = $this->createMock(AuthorizationTransactionService::class);
        $this->authorizationService = $this->createMock(AuthorizationService::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $message): string => $message);
        $this->controller = new AuthorizationResumeController(
            'oidc', $this->createMock(IRequest::class), $this->userSession,
            $l, $this->transactions, $this->authorizationService
        );
    }

    public function testRequiresAuthenticatedNextcloudUserBeforeConsuming(): void {
        $this->transactions->expects($this->never())->method('consume');
        $this->authorizationService->expects($this->never())->method('process');
        $response = $this->controller->resume(str_repeat('a', 64));
        $this->assertSame(403, $response->getStatus());
    }

    public function testExpiredUnknownOrReplayedTransactionReturns400(): void {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $this->transactions->method('consume')->willReturn(null);
        $this->authorizationService->expects($this->never())->method('process');
        $response = $this->controller->resume('invalid');
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Authorization session expired. Please try again.', $response->getParams()['errors'][0]['error']);
    }

    public function testResumePassesOriginalRequestDirectlyToSharedService(): void {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
        $parameters = [
            'client_id' => 'client-1', 'state' => 'a+b', 'response_type' => 'code',
            'redirect_uri' => 'https://rp.example/callback', 'scope' => 'openid',
            'nonce' => 'abc', 'resource' => 'https://resource.example/',
            'code_challenge' => str_repeat('X', 43), 'code_challenge_method' => 'S256',
            'prompt' => 'login', 'max_age' => '0', 'response_mode' => 'form_post',
            'claims' => '{"id_token":{"email":{"essential":true}}}',
        ];
        $this->transactions->expects($this->once())->method('consume')
            ->with(str_repeat('a', 64))
            ->willReturn(['parameters' => $parameters, 'reason' => 'prompt_login']);
        $expected = new RedirectResponse('https://rp.example/callback?code=abc');
        $this->authorizationService->expects($this->once())->method('process')
            ->with($parameters, true)->willReturn($expected);

        $this->assertSame($expected, $this->controller->resume(str_repeat('a', 64)));
    }
}
