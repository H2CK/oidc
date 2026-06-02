<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use OCA\OIDCIdentityProvider\Controller\ConsentController;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
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

    public function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->session = $this->createMock(ISession::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->userConsentMapper = $this->createMock(UserConsentMapper::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->l = $this->createMock(IL10N::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->user = $this->createMock(IUser::class);

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
            $this->clientMapper,
            $this->time,
            $this->l,
            $this->appConfig,
            $this->logger
        );
    }

    public function testShowWithoutLogin() {
        $this->userSession->method('isLoggedIn')->willReturn(false);

        $response = $this->controller->show();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('403', $response->getTemplateName());
        $this->assertEquals('error', $response->getRenderAs());
    }

    public function testShowWithoutPendingConsent() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->session->method('get')->with('oidc_consent_pending')->willReturn(false);

        $response = $this->controller->show();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('400', $response->getTemplateName());
        $this->assertEquals('error', $response->getRenderAs());
    }

    public function testShowSuccess() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->session->method('get')->willReturnCallback(function ($key) {
            $values = [
                'oidc_consent_pending' => true,
                'oidc_client_name' => 'Test Client',
                'oidc_requested_scopes' => 'openid profile email',
                'oidc_client_id' => 'test-client-id',
            ];
            return $values[$key] ?? null;
        });

        $response = $this->controller->show();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('consent', $response->getTemplateName());
        $params = $response->getParams();
        $this->assertEquals('Test Client', $params['clientName']);
        $this->assertEquals('openid profile email', $params['requestedScopes']);
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

        $this->urlGenerator->method('linkToRoute')
            ->with('oidc.LoginRedirector.authorize', [
                'client_id' => 'test-client-id',
                'scope' => 'openid profile',
            ])
            ->willReturn('/apps/oidc/authorize');

        $response = $this->controller->grant();

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testDenySuccess() {
        $this->userSession->method('isLoggedIn')->willReturn(true);
        $this->user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->session->method('get')->willReturnCallback(function ($key) {
            $values = [
                'oidc_redirect_uri' => 'https://client.example.com/callback',
                'oidc_state' => 'test-state',
                'oidc_client_id' => 'test-client-id',
            ];
            return $values[$key] ?? null;
        });

        $response = $this->controller->deny();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $redirectUrl = $response->getRedirectURL();
        $this->assertStringContainsString('error=access_denied', $redirectUrl);
        $this->assertStringContainsString('state=test-state', $redirectUrl);
    }
}
