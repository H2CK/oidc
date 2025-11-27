<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCP\IRequest;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Http;
use OCP\Security\ISecureRandom;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Controller\SettingsController;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;

use Psr\Log\LoggerInterface;

class SettingsControllerTest extends TestCase {
    protected $controller;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    private $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper */
    private $customClaimMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper  */
    private $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|LogoutRedirectUriMapper  */
    private $logoutRedirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|GroupMapper  */
    private $groupMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager  */
    private $groupManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IL10N */
    private $l;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserSession */
    private $userSession;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var IDBConnection */
    private $db;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriService */
    private $redirectUriService;

    private $client;

    public function setUp(): void {
        $this->request = $this->getMockBuilder(IRequest::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->config = $this->getMockBuilder(IConfig::class)->getMock();
        $this->userSession = $this->getMockBuilder(IUserSession::class)->getMock();
        $this->secureRandom = $this->getMockBuilder(ISecureRandom::class)->getMock();
        $this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
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
        $this->logoutRedirectUriMapper = $this->getMockBuilder(LogoutRedirectUriMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig])->getMock();
        $this->groupManager = $this->getMockBuilder(IGroupManager::class)->getMock();
        $this->groupMapper = $this->getMockBuilder(GroupMapper::class)->setConstructorArgs([
            $this->db,
            $this->groupManager])->getMock();
        $this->l = $this->getMockBuilder(IL10N::class)->getMock();
        $this->redirectUriService = new RedirectUriService(
            $this->logger
        );

        $this->controller = new SettingsController(
            'oidc',
            $this->request,
            $this->clientMapper,
            $this->accessTokenMapper,
            $this->redirectUriMapper,
            $this->logoutRedirectUriMapper,
            $this->groupMapper,
            $this->redirectUriService,
            $this->groupManager,
            $this->l,
            $this->userSession,
            $this->appConfig,
            $this->config,
            $this->logger
        );
    }

    public function testAddClient() {
        $name = 'TEST';
        $redirectUri = 'https://local.lo';
        $signingAlg = 'RS256';
        $type = 'confidential';
        $flowType = 'code';
        $tokenType = 'opaque';

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    $client = $arg;
                    $client->setId(1);
                    return $client;
                }
            );

        $this->redirectUriMapper
            ->method('getByClientId')
            ->willReturnCallBack (
                function ($arg) {
                    $redirectUri = new RedirectUri();
                    $redirectUri->setId(1);
                    $redirectUri->setClientId(1);
                    $redirectUri->setRedirectUri('https://local.lo');
                    return [ $redirectUri ];
                }
            );

        $result = $this->controller->addClient(
            $name,
            $redirectUri,
            $signingAlg,
            $type,
            $flowType,
            $tokenType
        );

        $this->assertEquals(Http::STATUS_OK, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals('1', $result->getData()['id']);
        $this->assertEquals($name, $result->getData()['name']);
        $this->assertEquals($signingAlg, $result->getData()['signingAlg']);
    }

    public function testAddClientwCreds() {
        $name = 'TEST';
        $redirectUri = 'https://local.lo';
        $signingAlg = 'RS256';
        $type = 'confidential';
        $flowType = 'code';
        $tokenType = 'opaque';

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    $client = $arg;
                    $client->setId(1);
                    return $client;
                }
            );

        $this->redirectUriMapper
            ->method('getByClientId')
            ->willReturnCallBack (
                function ($arg) {
                    $redirectUri = new RedirectUri();
                    $redirectUri->setId(1);
                    $redirectUri->setClientId(1);
                    $redirectUri->setRedirectUri('https://local.lo');
                    return [ $redirectUri ];
                }
            );

        $clientId = "0582bb51ac974f318c4fe11779c439a0";
        $clientSecret = "0582bb51ac974f318c4fe11779c439a0";

        $result = $this->controller->addClient(
            $name,
            $redirectUri,
            $signingAlg,
            $type,
            $flowType,
            $tokenType,
            $clientId,
            $clientSecret
        );

        $this->assertEquals(Http::STATUS_OK, $result->getStatus(), 'Status Code does not match!');
        $this->assertEquals('1', $result->getData()['id']);
        $this->assertEquals($name, $result->getData()['name']);
        $this->assertEquals($signingAlg, $result->getData()['signingAlg']);
        $this->assertEquals($clientId, $result->getData()['clientId']);
        $this->assertEquals($clientSecret, $result->getData()['clientSecret']);
    }

    public function testAddClientwWrongCreds1() {
        $name = 'TEST';
        $redirectUri = 'https://local.lo';
        $signingAlg = 'RS256';
        $type = 'confidential';
        $flowType = 'code';
        $tokenType = 'opaque';

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    $client = $arg;
                    $client->setId(1);
                    return $client;
                }
            );

        $this->redirectUriMapper
            ->method('getByClientId')
            ->willReturnCallBack (
                function ($arg) {
                    $redirectUri = new RedirectUri();
                    $redirectUri->setId(1);
                    $redirectUri->setClientId(1);
                    $redirectUri->setRedirectUri('https://local.lo');
                    return [ $redirectUri ];
                }
            );

        $clientId = "0582bb51ac974f318c4fe1Ä#1779c439a0";
        $clientSecret = "0582bb51ac974f318c4fe11779c439a0";

        $result = $this->controller->addClient(
            $name,
            $redirectUri,
            $signingAlg,
            $type,
            $flowType,
            $tokenType,
            $clientId,
            $clientSecret
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
    }

    public function testAddClientwWrongCreds2() {
        $name = 'TEST';
        $redirectUri = 'https://local.lo';
        $signingAlg = 'RS256';
        $type = 'confidential';
        $flowType = 'code';
        $tokenType = 'opaque';

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    $client = $arg;
                    $client->setId(1);
                    return $client;
                }
            );

        $this->redirectUriMapper
            ->method('getByClientId')
            ->willReturnCallBack (
                function ($arg) {
                    $redirectUri = new RedirectUri();
                    $redirectUri->setId(1);
                    $redirectUri->setClientId(1);
                    $redirectUri->setRedirectUri('https://local.lo');
                    return [ $redirectUri ];
                }
            );

        $clientId = "0123456789012345678901234567890123456789012345678901234567890123456789";
        $clientSecret = "0582bb51ac974f318c4fe11779c439a0";

        $result = $this->controller->addClient(
            $name,
            $redirectUri,
            $signingAlg,
            $type,
            $flowType,
            $tokenType,
            $clientId,
            $clientSecret
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
    }

    public function testAddClientwWrongCreds3() {
        $name = 'TEST';
        $redirectUri = 'https://local.lo';
        $signingAlg = 'RS256';
        $type = 'confidential';
        $flowType = 'code';
        $tokenType = 'opaque';

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    $client = $arg;
                    $client->setId(1);
                    return $client;
                }
            );

        $this->redirectUriMapper
            ->method('getByClientId')
            ->willReturnCallBack (
                function ($arg) {
                    $redirectUri = new RedirectUri();
                    $redirectUri->setId(1);
                    $redirectUri->setClientId(1);
                    $redirectUri->setRedirectUri('https://local.lo');
                    return [ $redirectUri ];
                }
            );

        $clientId = "0123456789012345678901234567890";
        $clientSecret = "0582bb51ac974f318c4fe11779c439a0";

        $result = $this->controller->addClient(
            $name,
            $redirectUri,
            $signingAlg,
            $type,
            $flowType,
            $tokenType,
            $clientId,
            $clientSecret
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
    }

    public function testAddClientBadRedirectUri() {
        $name = 'TEST';
        $redirectUri = 'bad-uri';
        $signingAlg = 'RS256';
        $type = 'confidential';
        $flowType = 'code';
        $tokenType = 'opaque';

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    $client = $arg;
                    $client->setId(1);
                    return $client;
                }
            );

        $result = $this->controller->addClient(
            $name,
            $redirectUri,
            $signingAlg,
            $type,
            $flowType,
            $tokenType
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
    }

    public function testUpdateClientFlowCode() {
        $id = '11';
        $flowType = 'any string';

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) {
                    $localClient = new Client();
                    $localClient->setId(1);
                    return $localClient;
                }
            );

        $this->clientMapper
            ->method('update')
            ->willReturnCallBack (
                function ($arg) {
                    $this->client = $arg;
                    return $arg;
                }
            );

        $result = $this->controller->updateClientFlow(
            $id,
            $flowType
        );

        $this->assertEquals('code', $this->client->getFlowType(), 'FlowType does not match!');
    }

    public function testUpdateClientFlowIdToken() {
        $id = '11';
        $flowType = 'any id_token new york';

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) {
                    $localClient = new Client();
                    $localClient->setId(1);
                    return $localClient;
                }
            );

        $this->clientMapper
            ->method('update')
            ->willReturnCallBack (
                function ($arg) {
                    $this->client = $arg;
                    return $arg;
                }
            );

        $result = $this->controller->updateClientFlow(
            $id,
            $flowType
        );

        $this->assertEquals('code id_token', $this->client->getFlowType(), 'FlowType does not match!');
    }

    public function testUpdateTokenTypeJwt() {
        $id = '11';
        $tokenType = 'jwt';

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) {
                    $localClient = new Client();
                    $localClient->setId(1);
                    return $localClient;
                }
            );

        $this->clientMapper
            ->method('update')
            ->willReturnCallBack (
                function ($arg) {
                    $this->client = $arg;
                    return $arg;
                }
            );

        $result = $this->controller->updateTokenType(
            $id,
            $tokenType
        );

        $this->assertEquals('jwt', $this->client->getTokenType(), 'TokenType does not match!');
    }

    public function testUpdateTokenTypeOpaque() {
        $id = '11';
        $tokenType = 'any other string';

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallBack (
                function ($arg) {
                    $localClient = new Client();
                    $localClient->setId(1);
                    return $localClient;
                }
            );

        $this->clientMapper
            ->method('update')
            ->willReturnCallBack (
                function ($arg) {
                    $this->client = $arg;
                    return $arg;
                }
            );

        $result = $this->controller->updateTokenType(
            $id,
            $tokenType
        );

        $this->assertEquals('opaque', $this->client->getTokenType(), 'TokenType does not match!');
    }

    public function testAddRedirectUriBadRedirectUri() {
        $id = '11';
        $redirectUri = 'bad-uri';

        $result = $this->controller->addRedirectUri(
            $id,
            $redirectUri
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus(), 'Status Code does not match!');
    }


}
