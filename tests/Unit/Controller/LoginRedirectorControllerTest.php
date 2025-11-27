<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\IRequest;
use OCP\IL10N;
use OCP\IGroupManager;
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
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAccountManager */
    private $accountManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper  */
    private $customClaimMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimService */
    private $customClaimService;

    private $client;

    public function setUp(): void {
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->request = $this->getMockBuilder(IRequest::class)->getMock();
        $this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
        $this->crypto = $this->getMockBuilder(ICrypto::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->config = $this->getMockBuilder(IConfig::class)->getMock();
        $this->userManager = $this->getMockBuilder(IUserManager::class)->getMock();
        $this->groupManager = $this->getMockBuilder(IGroupManager::class)->getMock();
        $this->accountManager = $this->getMockBuilder(IAccountManager::class)->getMock();
        $this->secureRandom = $this->secureRandom = Server::get(SecureRandom::class);
        $this->time = Server::get(TimeFactory::class);
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->tokenProvider = Server::get(IProvider::class);
        $this->session = $this->getMockBuilder(ISession::class)->getMock();
        $this->userSession = $this->getMockBuilder(IUserSession::class)->getMock();
        $this->redirectUriMapper = $this->getMockBuilder(RedirectUriMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig])->getMock();
        $this->customClaimMapper = $this->getMockBuilder(CustomClaimMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig,
            $this->redirectUriMapper,
            $this->customClaimMapper,
            $this->secureRandom,
            $this->logger])->getMock();
        $this->accessTokenMapper = $this->getMockBuilder(AccessTokenMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig])->getMock();
        $this->groupMapper = $this->getMockBuilder(GroupMapper::class)->setConstructorArgs([
            $this->db,
            $this->groupManager])->getMock();
        $this->userConsentMapper = $this->getMockBuilder(UserConsentMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->l = $this->getMockBuilder(IL10N::class)->getMock();
        $this->customClaimService = new CustomClaimService(
            $this->customClaimMapper,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
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
