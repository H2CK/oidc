<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

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
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Controller\SettingsController;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;

use Psr\Log\LoggerInterface;

class SettingsControllerTest extends TestCase {
    protected $controller;
    /** @var IRequest */
    protected $request;
    /** @var ClientMapper */
    private $clientMapper;
    /** @var AccessTokenMapper  */
    private $accessTokenMapper;
    /** @var RedirectUriMapper  */
    private $redirectUriMapper;
    /** @var LogoutRedirectUriMapper  */
    private $logoutRedirectUriMapper;
    /** @var GroupMapper  */
    private $groupMapper;
    /** @var IGroupManager  */
    private $groupManager;
    /** @var IL10N */
    private $l;
    /** @var IUserSession */
    private $userSession;
    /** @var IAppConfig */
    private $appConfig;
    /** @var IConfig */
    private $config;
    /** @var LoggerInterface */
    private $logger;
    /** @var ISecureRandom */
    private $secureRandom;
    /** @var ITimeFactory */
    private $time;
    /** @var IDBConnection */
    private $db;

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
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig,
            $this->redirectUriMapper,
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

        $this->controller = new SettingsController(
            'oidc',
            $this->request,
            $this->clientMapper,
            $this->accessTokenMapper,
            $this->redirectUriMapper,
            $this->logoutRedirectUriMapper,
            $this->groupMapper,
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
