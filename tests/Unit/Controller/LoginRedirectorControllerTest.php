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

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Controller\LoginRedirectorController;
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

}
