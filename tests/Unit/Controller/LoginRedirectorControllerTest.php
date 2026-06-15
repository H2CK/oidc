<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\IRequest;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\Group\ISubAdmin;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\ISession;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\Server;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OC\AppFramework\Utility\TimeFactory;
use OCP\AppFramework\Http;
use OCP\Security\ISecureRandom;
use OCP\Security\ICrypto;
use OCP\Security\ICredentialsManager;

use OC\Authentication\Token\IProvider;
use OC\Security\SecureRandom;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AuthorizationCode;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Controller\LoginRedirectorController;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCA\OIDCIdentityProvider\Service\CredentialService;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;

use Psr\Log\LoggerInterface;

class LoginRedirectorControllerTest extends TestCase {
    protected $controller;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
    private $urlGenerator;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    private $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper  */
    private $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AuthorizationCodeMapper  */
    private $authorizationCodeMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|UserConsentMapper  */
    private $userConsentMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|GroupMapper  */
    private $groupMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ICredentialsManager */
    private $credentialsManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager  */
    private $groupManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IL10N */
    private $l;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ICrypto */
    private $crypto;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IProvider */
    private $tokenProvider;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISession */
    private $session;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserSession */
    private $userSession;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserConfig */
    private $userConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISecureRandom */
    private $secureRandom;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var IDBConnection */
    private $db;
    /** @var JwtGenerator */
    private $jwtGenerator;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriService */
    private $redirectUriService;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
    private $userManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISubAdmin */
    private $subAdminManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAccountManager */
    private $accountManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper  */
    private $customClaimMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimService */
    private $customClaimService;
    /** @var CredentialService */
    private $credentialService;

    private $client;

    public function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->request = $this->createMock(IRequest::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->secureRandom = $this->secureRandom = Server::get(SecureRandom::class);
        $this->time = Server::get(TimeFactory::class);
        $this->tokenProvider = Server::get(IProvider::class);
        $this->session = $this->createMock(ISession::class);
        $this->userSession = $this->createMock(IUserSession::class);
        
        // Create redirectUriMapper with constructor arguments
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $reflection1 = new \ReflectionClass(RedirectUriMapper::class);
        $constructor1 = $reflection1->getConstructor();
        $constructor1->invoke($this->redirectUriMapper, $this->db, $this->time, $this->appConfig);
        
        $this->customClaimMapper = $this->createMock(CustomClaimMapper::class);
        $this->subAdminManager = $this->createMock(ISubAdmin::class);
        
        // Create clientMapper with constructor arguments
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $reflection2 = new \ReflectionClass(ClientMapper::class);
        $constructor2 = $reflection2->getConstructor();
        $constructor2->invoke($this->clientMapper, $this->db, $this->time, $this->appConfig, $this->redirectUriMapper, $this->customClaimMapper, $this->secureRandom, $this->logger);
        
        // Create accessTokenMapper with constructor arguments
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $reflection3 = new \ReflectionClass(AccessTokenMapper::class);
        $constructor3 = $reflection3->getConstructor();
        $constructor3->invoke($this->accessTokenMapper, $this->db, $this->time, $this->appConfig);

        $this->authorizationCodeMapper = $this->createMock(AuthorizationCodeMapper::class);
        
        // Create groupMapper with constructor arguments
        $this->groupMapper = $this->createMock(GroupMapper::class);
        $reflection4 = new \ReflectionClass(GroupMapper::class);
        $constructor4 = $reflection4->getConstructor();
        $constructor4->invoke($this->groupMapper, $this->db, $this->groupManager);
        
        $this->userConsentMapper = $this->createMock(UserConsentMapper::class);
        $this->l = $this->createMock(IL10N::class);
        $this->customClaimService = new CustomClaimService(
            $this->customClaimMapper,
            $this->userManager,
            $this->groupManager,
            $this->subAdminManager,
            $this->accountManager,
            $this->logger
        );
        $this->credentialService = new CredentialService(
            $this->createMock(ICredentialsManager::class),
            $this->appConfig,
            $this->logger
        );
        $this->jwtGenerator = new JwtGenerator(
            $this->crypto,
            $this->tokenProvider,
            $this->secureRandom,
            $this->time,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $this->urlGenerator,
            $this->appConfig,
            $this->userConfig,
            $this->config,
            $this->customClaimService,
            $this->credentialService,
            $this->logger
        );
        $this->redirectUriService = new RedirectUriService(
            $this->logger
        );

        $this->controller = new LoginRedirectorController(
            'oidc',
            $this->request,
            $this->urlGenerator,
            $this->clientMapper,
            $this->groupMapper,
            $this->secureRandom,
            $this->session,
            $this->l,
            $this->time,
            $this->userSession,
            $this->groupManager,
            $this->accessTokenMapper,
            $this->authorizationCodeMapper,
            $this->redirectUriMapper,
            $this->userConsentMapper,
            $this->appConfig,
            $this->jwtGenerator,
            $this->redirectUriService,
            $this->logger
        );
    }

    public function testAuthorizeNotLoggedIn() {
        $client_id = 'ABCF23455';
        $state = '5348982';
        $response_type = 'code';
        $redirect_uri = 'http://callback/call-back';
        $scope = 'openid';
        $nonce = 'hdksio';
        $resource = null;

        // Simulate that user is not logged in
        $this->userSession
            ->method('isLoggedIn')
            ->willReturnCallBack (
                function () {
                    return false;
                }
            );
        // Simulate generating redirect url
        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturnCallBack (
                function ($arg1, $arg2) {
                    switch ($arg1) {
                        case 'oidc.Page.index':
                            return 'http://oidc.local/index-page';
                            break;
                        default:
                            return 'http://oidc.local/login-form';
                            break;
                    }
                }
            );

        $result = $this->controller->authorize(
            $client_id,
            $state,
            $response_type,
            $redirect_uri,
            $scope,
            $nonce,
            $resource
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals('http://oidc.local/login-form', $result->getRedirectURL());
    }

    public function testAuthorizePromptNoneNotLoggedInReturnsLoginRequired() {
        $clientId = 'client1';
        $state = 'state-1';
        $redirectUri = 'https://client.example.com/callback';

        $client = new Client(
            'Test Client',
            [$redirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            '',
            false
        );
        $client->id = 1;
        $client->setClientIdentifier($clientId);

        $registeredRedirectUri = new RedirectUri();
        $registeredRedirectUri->setClientId(1);
        $registeredRedirectUri->setRedirectUri($redirectUri);

        $this->userSession
            ->method('isLoggedIn')
            ->willReturn(false);
        $this->session
            ->expects($this->never())
            ->method('set');
        $this->urlGenerator
            ->expects($this->never())
            ->method('linkToRoute');
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);
        $this->redirectUriMapper
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$registeredRedirectUri]);
        $this->accessTokenMapper
            ->expects($this->never())
            ->method('insert');

        $result = $this->controller->authorize(
            $clientId,
            $state,
            'code',
            $redirectUri,
            'openid',
            'nonce-1',
            null,
            null,
            null,
            'none'
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals(
            $redirectUri . '?error=login_required&error_description=User%20is%20not%20logged%20in.&state=state-1',
            $result->getRedirectURL()
        );
    }

    public function testAuthorizeUsesStoredOidcAuthenticationTimeForAccessToken() {
        $clientId = 'client1';
        $state = 'state-1';
        $redirectUri = 'https://client.example.com/callback';
        $authTime = 1234567890;

        $client = new Client(
            'Test Client',
            [$redirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            '',
            false
        );
        $client->id = 1;
        $client->setClientIdentifier($clientId);

        $registeredRedirectUri = new RedirectUri();
        $registeredRedirectUri->setClientId(1);
        $registeredRedirectUri->setRedirectUri($redirectUri);

        $user = $this->createMock(\OCP\IUser::class);
        $user
            ->method('getUID')
            ->willReturn('testuser');

        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator
            ->method('generateAccessToken')
            ->willReturn('access-token');

        $controller = new LoginRedirectorController(
            'oidc',
            $this->request,
            $this->urlGenerator,
            $this->clientMapper,
            $this->groupMapper,
            $this->secureRandom,
            $this->session,
            $this->l,
            $this->time,
            $this->userSession,
            $this->groupManager,
            $this->accessTokenMapper,
            $this->authorizationCodeMapper,
            $this->redirectUriMapper,
            $this->userConsentMapper,
            $this->appConfig,
            $jwtGenerator,
            $this->redirectUriService,
            $this->logger
        );

        $this->userSession
            ->method('isLoggedIn')
            ->willReturn(true);
        $this->userSession
            ->method('getUser')
            ->willReturn($user);
        $this->session
            ->method('get')
            ->willReturnCallback(function ($key) use ($authTime) {
                $values = [
                    'oidc_auth_time' => $authTime,
                    'oidc_login_pending' => false,
                ];
                return $values[$key] ?? null;
            });
        $this->request
            ->method('getServerProtocol')
            ->willReturn('https');
        $this->request
            ->method('getServerHost')
            ->willReturn('server.example.com');
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);
        $this->redirectUriMapper
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$registeredRedirectUri]);
        $this->groupMapper
            ->method('getGroupsByClientId')
            ->with(1)
            ->willReturn([]);
        $this->groupManager
            ->method('getUserGroups')
            ->with($user)
            ->willReturn([]);
        $this->userConsentMapper
            ->method('findByUserAndClient')
            ->with('testuser', 1)
            ->willReturn(null);
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnCallback(function ($key, $default = '') {
                if ($key === Application::APP_CONFIG_ALLOW_USER_SETTINGS) {
                    return 'no';
                }
                return $default;
            });
        $this->accessTokenMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AccessToken $accessToken) use ($authTime) {
                $this->assertSame($authTime, $accessToken->getCreated());
                $accessToken->id = 23;
                return $accessToken;
            });
        $this->authorizationCodeMapper
            ->expects($this->once())
            ->method('createForAccessToken')
            ->with(
                23,
                $this->isType('string'),
                $this->isType('int')
            )
            ->willReturn(new AuthorizationCode());

        $result = $controller->authorize(
            $clientId,
            $state,
            'code',
            $redirectUri,
            'openid',
            'nonce-1',
            null,
            null,
            null,
            null,
            null
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertStringStartsWith($redirectUri . '?state=state-1&code=', $result->getRedirectURL());
    }

    public function testAuthorizeMaxAgeExceededForcesReauthentication() {
        $clientId = 'client1';
        $state = 'state-1';
        $redirectUri = 'https://client.example.com/callback';

        $client = new Client(
            'Test Client',
            [$redirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            '',
            false
        );
        $client->id = 1;
        $client->setClientIdentifier($clientId);

        $registeredRedirectUri = new RedirectUri();
        $registeredRedirectUri->setClientId(1);
        $registeredRedirectUri->setRedirectUri($redirectUri);

        $time = $this->createMock(ITimeFactory::class);
        $time
            ->method('getTime')
            ->willReturn(2000);

        $controller = new LoginRedirectorController(
            'oidc',
            $this->request,
            $this->urlGenerator,
            $this->clientMapper,
            $this->groupMapper,
            $this->secureRandom,
            $this->session,
            $this->l,
            $time,
            $this->userSession,
            $this->groupManager,
            $this->accessTokenMapper,
            $this->authorizationCodeMapper,
            $this->redirectUriMapper,
            $this->userConsentMapper,
            $this->appConfig,
            $this->jwtGenerator,
            $this->redirectUriService,
            $this->logger
        );

        $this->userSession
            ->method('isLoggedIn')
            ->willReturn(true);
        $this->userSession
            ->expects($this->once())
            ->method('logout');
        $this->session
            ->method('get')
            ->willReturnCallback(function ($key) {
                $values = [
                    'oidc_auth_time' => 1000,
                    'oidc_login_pending' => false,
                ];
                return $values[$key] ?? null;
            });
        $this->session
            ->expects($this->atLeastOnce())
            ->method('set');
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);
        $this->redirectUriMapper
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$registeredRedirectUri]);
        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturnCallback(function ($route) {
                if ($route === 'oidc.Page.index') {
                    return '/index.php/apps/oidc/redirect?client_id=client1';
                }
                if ($route === 'core.login.showLoginForm') {
                    return '/index.php/login?redirect_url=/index.php/apps/oidc/redirect?client_id=client1';
                }
                return '/unexpected';
            });
        $this->accessTokenMapper
            ->expects($this->never())
            ->method('insert');

        $result = $controller->authorize(
            $clientId,
            $state,
            'code',
            $redirectUri,
            'openid',
            'nonce-1',
            null,
            null,
            null,
            null,
            '1'
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals(
            '/index.php/login?redirect_url=/index.php/apps/oidc/redirect?client_id=client1',
            $result->getRedirectURL()
        );
    }

    public function testAuthorizePromptLoginForcesReauthentication() {
        $clientId = 'client1';
        $state = 'state-1';
        $redirectUri = 'https://client.example.com/callback';

        $client = new Client(
            'Test Client',
            [$redirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            '',
            false
        );
        $client->id = 1;
        $client->setClientIdentifier($clientId);

        $registeredRedirectUri = new RedirectUri();
        $registeredRedirectUri->setClientId(1);
        $registeredRedirectUri->setRedirectUri($redirectUri);

        $this->userSession
            ->method('isLoggedIn')
            ->willReturn(true);
        $this->userSession
            ->expects($this->once())
            ->method('logout');
        $this->session
            ->method('get')
            ->willReturnCallback(function ($key) {
                $values = [
                    'oidc_auth_time' => 2000,
                    'oidc_login_pending' => false,
                ];
                return $values[$key] ?? null;
            });
        $this->session
            ->expects($this->atLeastOnce())
            ->method('set');
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);
        $this->redirectUriMapper
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$registeredRedirectUri]);
        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturnCallback(function ($route) {
                if ($route === 'oidc.Page.index') {
                    return '/index.php/apps/oidc/redirect?client_id=client1&prompt=login';
                }
                if ($route === 'core.login.showLoginForm') {
                    return '/index.php/login?redirect_url=/index.php/apps/oidc/redirect?client_id=client1&prompt=login';
                }
                return '/unexpected';
            });
        $this->accessTokenMapper
            ->expects($this->never())
            ->method('insert');

        $result = $this->controller->authorize(
            $clientId,
            $state,
            'code',
            $redirectUri,
            'openid',
            'nonce-1',
            null,
            null,
            null,
            'login'
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals(
            '/index.php/login?redirect_url=/index.php/apps/oidc/redirect?client_id=client1&prompt=login',
            $result->getRedirectURL()
        );
    }

    public function testAuthorizeRejectsUnsupportedRequestObject() {
        $clientId = 'client1';
        $state = 'state-1';
        $redirectUri = 'https://client.example.com/callback';

        $client = new Client(
            'Test Client',
            [$redirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            '',
            false
        );
        $client->id = 1;
        $client->setClientIdentifier($clientId);

        $registeredRedirectUri = new RedirectUri();
        $registeredRedirectUri->setClientId(1);
        $registeredRedirectUri->setRedirectUri($redirectUri);

        $this->userSession
            ->method('isLoggedIn')
            ->willReturn(true);
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);
        $this->redirectUriMapper
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$registeredRedirectUri]);
        $this->request
            ->method('getParam')
            ->willReturnCallback(function ($key) {
                return $key === 'request' ? 'eyJhbGciOiJub25lIn0.e30.' : null;
            });

        $result = $this->controller->authorize(
            $clientId,
            $state,
            'code',
            $redirectUri,
            'openid',
            'nonce-1'
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals(
            $redirectUri . '?error=request_not_supported&error_description=Request%20object%20parameter%20is%20not%20supported.&state=state-1',
            $result->getRedirectURL()
        );
    }

    public function testAuthorizeRejectsUnsupportedRequestObjectBeforeLogin() {
        $clientId = 'client1';
        $redirectUri = 'https://client.example.com/callback';

        $client = new Client(
            'Test Client',
            [$redirectUri],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            '',
            false
        );
        $client->id = 1;
        $client->setClientIdentifier($clientId);

        $registeredRedirectUri = new RedirectUri();
        $registeredRedirectUri->setClientId(1);
        $registeredRedirectUri->setRedirectUri($redirectUri);

        $this->userSession
            ->expects($this->never())
            ->method('isLoggedIn');
        $this->session
            ->expects($this->never())
            ->method('set');
        $this->urlGenerator
            ->expects($this->never())
            ->method('linkToRoute');
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);
        $this->redirectUriMapper
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$registeredRedirectUri]);
        $this->request
            ->method('getParam')
            ->willReturnCallback(function ($key) {
                return $key === 'request' ? 'eyJhbGciOiJub25lIn0.e30.' : null;
            });

        $result = $this->controller->authorize(
            $clientId,
            null,
            'code',
            $redirectUri,
            'openid',
            null
        );

        $this->assertEquals(Http::STATUS_SEE_OTHER, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals(
            $redirectUri . '?error=request_not_supported&error_description=Request%20object%20parameter%20is%20not%20supported.',
            $result->getRedirectURL()
        );
    }

}
