<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Http\FormPostResponse;
use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\AuthorizationTransactionService;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\ScopeCeilingService;
use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuthorizationServiceRegressionTest extends TestCase {
    private AuthorizationService $service;
    private UserConsentMapper $consents;
    private AuthorizationTransactionService $transactions;
    private AccessTokenMapper $tokens;
    private Client $client;
    private array $sessionValues = [];
    private string $allowConsent = 'yes';
    private bool $loggedIn = true;
    private IUserSession $userSession;

    protected function setUp(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('op.example');
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('getWebroot')->willReturn('/nextcloud');
        $urls->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/route/' . $route);
        $clients = $this->createMock(ClientMapper::class);
        $this->client = new Client('test', [], 'RS256', 'confidential', 'code id_token token', 'opaque', 'openid profile Files:Read offline_access');
        $this->client->setId(1);
        $this->client->setClientIdentifier('client');
        $clients->method('getByIdentifier')->willReturn($this->client);
        $groups = $this->createMock(GroupMapper::class);
        $groups->method('getGroupsByClientId')->willReturn([]);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->loggedIn);
        $this->userSession->method('getUser')->willReturn($user);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroups')->willReturn([]);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($user);
        $ceilings = $this->createMock(GroupScopeMapper::class);
        $ceilings->method('findByGroupIds')->willReturn([]);
        $scopeCeiling = new ScopeCeilingService($ceilings, $groupManager, $userManager, $logger);
        $this->tokens = $this->createMock(AccessTokenMapper::class);
        $this->tokens->method('insert')->willReturnCallback(static function (AccessToken $token): AccessToken {
            $token->setId(10);
            return $token;
        });
        $redirects = $this->createMock(RedirectUriMapper::class);
        $redirect = new RedirectUri();
        $redirect->setRedirectUri('https://rp.example/cb');
        $redirects->method('getByClientId')->willReturn([$redirect]);
        $this->consents = $this->createMock(UserConsentMapper::class);
        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturn(str_repeat('a', 128));
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(fn (string $key) => $this->sessionValues[$key] ?? null);
        $session->method('set')->willReturnCallback(function (string $key, $value): void { $this->sessionValues[$key] = $value; });
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturn(1000);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $config = $this->createMock(IAppConfig::class);
        $config->method('getAppValueString')->willReturnCallback(fn (string $key, string $default): string => $key === 'allow_user_settings' ? $this->allowConsent : $default);
        $jwt = $this->createMock(JwtGenerator::class);
        $jwt->method('generateAccessToken')->willReturn('access-token');
        $jwt->method('generateIdToken')->willReturn('id-token');
        $sessions = $this->createMock(SessionManagementService::class);
        $sessions->method('isSupported')->willReturn(false);
        $this->transactions = $this->createMock(AuthorizationTransactionService::class);
        $this->service = new AuthorizationService($request, $urls, $clients, $groups, $random,
            $session, $l, $clock, $this->userSession, $groupManager, $this->tokens,
            $this->createMock(AuthorizationCodeMapper::class), $redirects, $this->consents, $config, $jwt,
            new RedirectUriService($logger), $this->createMock(BackChannelLogoutService::class),
            $sessions, $logger, $scopeCeiling, $this->transactions);
    }

    private function request(array $overrides = []): array {
        return array_replace(['client_id' => 'client', 'redirect_uri' => 'https://rp.example/cb',
            'response_type' => 'code', 'scope' => 'openid profile Files:Read offline_access'], $overrides);
    }

    private function consent(string $granted, ?string $reviewed = null): UserConsent {
        $consent = new UserConsent();
        $consent->setScopesGranted($granted);
        $consent->setScopesRequested($reviewed);
        $consent->setExpiresAt(2000);
        return $consent;
    }

    public function testCaseSensitiveScopeSurvivesIssuance(): void {
        $this->allowConsent = 'no';
        $this->tokens->expects($this->once())->method('insert')->with($this->callback(
            static fn (AccessToken $token): bool => str_contains($token->getScope(), 'Files:Read') && !str_contains($token->getScope(), 'files:read')
        ));
        $this->service->process($this->request());
    }

    public function testReviewedPartialConsentDoesNotPromptAgainAndCannotWidenScope(): void {
        $this->consents->method('findByUserAndClient')->willReturn($this->consent('openid Files:Read', 'openid profile Files:Read offline_access'));
        $this->tokens->expects($this->once())->method('insert')->with($this->callback(static fn (AccessToken $t): bool => $t->getScope() === 'openid Files:Read'));
        $response = $this->service->process($this->request(['prompt' => 'none']));
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('code=', $response->getRedirectURL());
    }

    public function testSubsetAndReorderedScopesAreGrantedAsIntersection(): void {
        $this->consents->method('findByUserAndClient')->willReturn($this->consent('openid profile Files:Read'));
        $this->tokens->expects($this->once())->method('insert')->with($this->callback(static fn (AccessToken $t): bool => $t->getScope() === 'Files:Read openid'));
        $this->service->process($this->request(['scope' => 'Files:Read openid', 'prompt' => 'none']));
    }

    public function testPromptNoneReturnsConsentRequiredInsteadOfShowingConsent(): void {
        $this->tokens->expects($this->never())->method('insert');
        $response = $this->service->process($this->request(['prompt' => 'none']));
        $this->assertStringContainsString('error=consent_required', $response->getRedirectURL());
    }

    public function testPromptConsentIsForcedButDoesNotLoopAfterConsentGrant(): void {
        $this->consents->method('findByUserAndClient')->willReturn($this->consent('openid', 'openid'));
        $request = $this->request(['scope' => 'openid', 'prompt' => 'consent']);
        $response = $this->service->process($request);
        $this->assertSame('/route/oidc.Consent.show', $response->getRedirectURL());
        $response = $this->service->process($request, false, null, true);
        $this->assertStringContainsString('code=', $response->getRedirectURL());
    }

    public function testNoneCannotBeCombinedWithInteractivePrompts(): void {
        $response = $this->service->process($this->request(['prompt' => 'none consent']));
        $this->assertStringContainsString('error=invalid_request', $response->getRedirectURL());
    }

    public function testSelectAccountRequiresNewLoginAndResumedLoginDoesNotLoop(): void {
        $this->allowConsent = 'no';
        $this->transactions->expects($this->once())->method('create')->with($this->anything(), 'select_account')->willReturn(str_repeat('a', 64));
        $this->userSession->expects($this->once())->method('logout');
        $request = $this->request(['prompt' => 'select_account']);
        $this->assertSame('/route/core.login.showLoginForm', $this->service->process($request)->getRedirectURL());
        $this->assertStringContainsString('code=', $this->service->process($request, true)->getRedirectURL());
    }

    public function testConfidentialPkceOmittedMethodDefaultsToPlain(): void {
        $this->allowConsent = 'no';
        $this->tokens->expects($this->once())->method('insert')->with($this->callback(static fn (AccessToken $t): bool => $t->getCodeChallengeMethod() === 'plain'));
        $this->service->process($this->request(['code_challenge' => str_repeat('p', 43)]));
    }

    public function testPublicPkceOmittedMethodIsRejectedAsPlain(): void {
        $this->client->setType('public');
        $response = $this->service->process($this->request(['code_challenge' => str_repeat('p', 43)]));
        $this->assertStringContainsString('error=invalid_request', $response->getRedirectURL());
    }

    public function testMissingResponseTypeAndTokenOnlyUseCorrectErrorCodes(): void {
        $response = $this->service->process($this->request(['response_type' => null]));
        $this->assertStringContainsString('error=invalid_request', $response->getRedirectURL());
        $response = $this->service->process($this->request(['response_type' => 'token']));
        $this->assertStringContainsString('#error=unsupported_response_type', $response->getRedirectURL());
    }

    public function testIssuerAndStatePresenceInSuccessAndErrorResponses(): void {
        $this->allowConsent = 'no';
        foreach ([[], ['state' => '0'], ['state' => '']] as $overrides) {
            $response = $this->service->process($this->request($overrides));
            parse_str(parse_url($response->getRedirectURL(), PHP_URL_QUERY), $parameters);
            $this->assertSame('https://op.example/nextcloud', $parameters['iss']);
            $this->assertSame(isset($overrides['state']), array_key_exists('state', $parameters));
            if (isset($overrides['state'])) { $this->assertSame($overrides['state'], $parameters['state']); }
        }
        $response = $this->service->authorizationError($this->request(['response_type' => 'code id_token', 'state' => '0']), 'access_denied', 'User denied consent');
        parse_str(parse_url($response->getRedirectURL(), PHP_URL_FRAGMENT), $parameters);
        $this->assertSame('access_denied', $parameters['error']);
        $this->assertSame('0', $parameters['state']);
        $this->assertSame('https://op.example/nextcloud', $parameters['iss']);
        $response = $this->service->authorizationError($this->request(['response_mode' => 'form_post']), 'access_denied', 'Denied');
        $this->assertInstanceOf(FormPostResponse::class, $response);
        $this->assertStringContainsString('name="iss"', $response->render());
    }

    public function testSilentRequestWithNoApprovedRequestedScopeNeedsInteraction(): void {
        $this->consents->method('findByUserAndClient')->willReturn($this->consent('openid', 'openid Files:Read'));
        $this->tokens->expects($this->never())->method('insert');
        $response = $this->service->process($this->request(['scope' => 'Files:Read', 'prompt' => 'none']));
        $this->assertStringContainsString('error=interaction_required', $response->getRedirectURL());
    }
}
