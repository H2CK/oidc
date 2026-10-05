<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use OCA\OIDCIdentityProvider\Controller\ConsentController;
use OCA\OIDCIdentityProvider\Service\AuthorizationService;
use OCA\OIDCIdentityProvider\Service\ClientAuthorizationService;
use OCA\OIDCIdentityProvider\Service\ScopeCeilingService;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class ConsentControllerTest extends TestCase {
    /** @var ConsentController */
    protected $controller;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISession */
    protected $session;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserSession */
    protected $userSession;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
    protected $urlGenerator;
    /** @var \PHPUnit\Framework\MockObject\MockObject|UserConsentMapper */
    protected $userConsentMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    protected $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    protected $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    protected $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IL10N */
    protected $l;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    protected $appConfig;
    /** @var LoggerInterface */
    protected $logger;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUser */
    protected $user;
    private AuthorizationService $authorizationService;
    private ClientAuthorizationService $clientAuthorizationService;
    private ScopeCeilingService $scopeCeiling;

    public function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->session = $this->createMock(ISession::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userConsentMapper = $this->createMock(UserConsentMapper::class);
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->l = $this->createMock(IL10N::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->user = $this->createMock(IUser::class);
        $this->authorizationService = $this->createMock(AuthorizationService::class);
        $this->clientAuthorizationService = $this->createMock(ClientAuthorizationService::class);
        $this->clientAuthorizationService->method('isUserAllowedForClient')->willReturn(true);
        $this->scopeCeiling = $this->createMock(ScopeCeilingService::class);
        $this->scopeCeiling->method('narrow')->willReturnCallback(static fn (string $uid, string $scope): string => $scope);

        $this->l->method('t')->willReturnCallback(function ($text) {
            return $text;
        });

        $this->controller = new ConsentController(
            'oidc',
            $this->request,
            $this->session,
            $this->userSession,
            $this->urlGenerator,
            $this->userConsentMapper,
            $this->accessTokenMapper,
            $this->clientMapper,
            $this->time,
            $this->l,
            $this->appConfig,
            $this->logger,
            $this->authorizationService,
            $this->clientAuthorizationService,
            $this->scopeCeiling
        );
    }

    public function testShowWithoutLogin() {
        $this->userSession->method('isLoggedIn')->willReturn(false);

        $response = $this->controller->show();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('403', $response->getTemplateName());
        $this->assertSame(403, $response->getStatus());
        $this->assertEquals('error', $response->getRenderAs());
    }

    public function testShowWithoutPendingConsent() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->session->method('get')->with('oidc_consent_pending')->willReturn(false);

        $response = $this->controller->show();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('error', $response->getTemplateName());
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('error', $response->getRenderAs());
        $this->assertSame(
            'No consent request pending.',
            $response->getParams()['errors'][0]['error']
        );
    }

    public function testShowSuccess() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->session->method('get')->willReturnCallback(function ($key) {
            $values = [
                'oidc_consent_pending' => true,
                'oidc_client_name' => 'Test Client',
                'oidc_requested_scopes' => 'openid profile email',
                'oidc_client_id' => 'test-client-id',
                'oidc_redirect_uri' => 'https://client.example:8443/callback',
            ];
            return $values[$key] ?? null;
        });

        $response = $this->controller->show();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('consent', $response->getTemplateName());
        $params = $response->getParams();
        $this->assertEquals('Test Client', $params['clientName']);
        $this->assertSame('https://client.example:8443', $params['redirectTarget']);
        $this->assertEquals('openid profile email', $params['requestedScopes']);
    }

    public function testRevokeConsentDeletesAccessTokens(): void {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->appConfig->method('getAppValueString')
            ->with(
                Application::APP_CONFIG_ALLOW_USER_SETTINGS,
                Application::DEFAULT_ALLOW_USER_SETTINGS
            )
            ->willReturn('yes');

        $this->userConsentMapper->expects($this->once())
            ->method('deleteByUserAndClient')
            ->with('testuser', 1);

        $this->accessTokenMapper->expects($this->once())
            ->method('deleteByUserAndClient')
            ->with('testuser', 1);

        $response = $this->controller->revokeConsent(1);

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals(['success' => true], $response->getData());
    }

    public function testGrantSuccess() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->session->method('get')->willReturnCallback(function ($key) {
            $values = [
                'oidc_consent_pending' => true,
                'oidc_requested_scopes' => 'openid profile email',
                'oidc_client_id' => 'test-client-id',
            ];
            return $values[$key] ?? null;
        });

        $client = new Client();
        $client->id = 1;
        $this->clientMapper->method('getByIdentifier')->willReturn($client);

        $this->request->method('getParam')->with('scopes')->willReturn('openid profile');

        $this->time->method('getTime')->willReturn(1234567890);

        $this->userConsentMapper->expects($this->once())
            ->method('createOrUpdate')
            ->with($this->callback(function ($consent) {
                // Verify basic consent fields
                if ($consent->getUserId() !== 'testuser' ||
                    $consent->getClientId() !== 1 ||
                    $consent->getScopesGranted() !== 'openid profile') {
                    return false;
                }
                // Verify expiration is set (90 days = 7776000 seconds from now)
                $expectedExpiration = 1234567890 + 7776000;
                return $consent->getExpiresAt() === $expectedExpiration;
            }));

        $this->authorizationService->expects($this->once())->method('process')
            ->with($this->callback(static fn (array $parameters): bool =>
                $parameters['client_id'] === 'test-client-id'
                && $parameters['scope'] === 'openid profile'
            ), false, null, true)
            ->willReturn(new RedirectResponse('https://client.example/callback'));

        $response = $this->controller->grant();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('authorization-handoff', $response->getTemplateName());
        $this->assertSame('https://client.example/callback', $response->getParams()['continueUrl']);
    }

    public function testDenySuccess() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->session->method('get')->willReturnCallback(function ($key) {
            $values = [
                'oidc_redirect_uri' => 'https://client.example.com/callback',
                'oidc_consent_pending' => true,
                'oidc_state' => 'test-state',
                'oidc_client_id' => 'test-client-id',
            ];
            return $values[$key] ?? null;
        });

        $this->authorizationService->expects($this->once())->method('authorizationError')
            ->with($this->callback(static fn (array $p): bool => $p['state'] === 'test-state'), 'access_denied', 'User denied consent')
            ->willReturn(new RedirectResponse('https://client.example.com/callback?error=access_denied&state=test-state'));
        $response = $this->controller->deny();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('authorization-handoff', $response->getTemplateName());
        $redirectUrl = $response->getParams()['continueUrl'];
        $this->assertStringContainsString('error=access_denied', $redirectUrl);
        $this->assertStringContainsString('state=test-state', $redirectUrl);
    }

    private function prepareScopeUpdate(array $selected, string $allowed = ''): UserConsent {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($this->user);
        $this->request->method('getParam')->with('scopes')->willReturn($selected);
        $this->time->method('getTime')->willReturn(1000);
        $this->appConfig->method('getAppValueString')->willReturn('yes');
        $client = new Client(allowedScopes: $allowed);
        $client->setId(1);
        $client->setClientIdentifier('client');
        $this->clientMapper->method('getByUid')->willReturn($client);
        $consent = new UserConsent();
        $consent->setScopesGranted('openid Files:Read');
        $consent->setScopesRequested('openid Files:Read email');
        $consent->setExpiresAt(2000);
        $this->userConsentMapper->method('findByUserAndClient')->willReturn($consent);
        $this->userConsentMapper->method('createOrUpdate')->willReturnArgument(0);
        return $consent;
    }

    public function testScopeUpdateWithEmptyClientLimitCanRetainRequestedPermissions(): void {
        $this->prepareScopeUpdate(['openid', 'Files:Read', 'email']);
        $this->accessTokenMapper->expects($this->never())->method('deleteByUserAndClient');
        $response = $this->controller->updateScopes(1);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('openid Files:Read email', $response->getData()['scopesGranted']);
        $this->assertSame(7777000, $response->getData()['expiresAt']);
    }

    public function testScopeRemovalRevokesStoredBearerAndRefreshFamilies(): void {
        $this->prepareScopeUpdate(['openid']);
        $this->accessTokenMapper->expects($this->once())->method('deleteByUserAndClient')->with('alice', 1);
        $this->assertSame(200, $this->controller->updateScopes(1)->getStatus());
    }

    public function testScopeEditorCannotGrantAnUnrequestedOrDifferentlyCasedScope(): void {
        $this->prepareScopeUpdate(['openid', 'files:read']);
        $this->userConsentMapper->expects($this->never())->method('createOrUpdate');
        $this->assertSame(400, $this->controller->updateScopes(1)->getStatus());
    }

    public function testGrantAndDenyWithoutPendingRequestShowAnErrorPage(): void {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        foreach (['grant', 'deny'] as $method) {
            $response = $this->controller->$method();
            $this->assertInstanceOf(TemplateResponse::class, $response);
            $this->assertSame(400, $response->getStatus());
        }
    }

}
