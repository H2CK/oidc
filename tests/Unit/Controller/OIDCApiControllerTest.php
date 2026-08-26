<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Services\IAppConfig;
use OC\Authentication\Token\IProvider as TokenProvider;

use OCA\OIDCIdentityProvider\Controller\OIDCApiController;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\AuthorizationCodeMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AuthorizationCode;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\TexTargets;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;

use OC\Security\Bruteforce\Throttler;

class OIDCApiControllerTest extends TestCase {
    protected $controller;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    protected $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    protected $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AuthorizationCodeMapper */
    protected $authorizationCodeMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|GroupMapper */
    protected $groupMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|UserConsentMapper */
    protected $userConsentMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|TexTargetMapper */
    protected $texTargetMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
    protected $userManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager */
    protected $groupManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAccountManager */
    protected $accountManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    protected $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    protected $appConfig;
    /** @var IDBConnection */
    protected $db;
    /** @var LoggerInterface */
    protected $logger;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ICrypto */
    protected $crypto;
    /** @var \PHPUnit\Framework\MockObject\MockObject|TokenProvider */
    protected $tokenProvider;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISecureRandom */
    protected $secureRandom;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
    protected $urlGenerator;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    protected $config;
    /** @var \PHPUnit\Framework\MockObject\MockObject|JwtGenerator */
    protected $jwtGenerator;

    public function setUp(): void {
        parent::setUp();
        
        $this->request = $this->createMock(IRequest::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->tokenProvider = $this->createMock(TokenProvider::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->config = $this->createMock(IConfig::class);
        $this->jwtGenerator = $this->createMock(JwtGenerator::class);

        // Create accessTokenMapper with constructor arguments
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $reflection = new \ReflectionClass(AccessTokenMapper::class);
        $constructor = $reflection->getConstructor();
        $constructor->invoke($this->accessTokenMapper, $this->db, $this->time, $this->appConfig);

        $this->authorizationCodeMapper = $this->createMock(AuthorizationCodeMapper::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->groupMapper = $this->createMock(GroupMapper::class);
        $this->userConsentMapper = $this->createMock(UserConsentMapper::class);
        $this->texTargetMapper = $this->createMock(TexTargetMapper::class);

        $throttler = $this->createMock(Throttler::class);
        
        $this->controller = new OIDCApiController(
            'oidc',
            $this->request,
            $this->crypto,
            $this->accessTokenMapper,
            $this->authorizationCodeMapper,
            $this->clientMapper,
            $this->groupMapper,
            $this->userConsentMapper,
            $this->texTargetMapper,
            $this->tokenProvider,
            $this->secureRandom,
            $this->time,
            $throttler,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $this->urlGenerator,
            $this->appConfig,
            $this->jwtGenerator,
            $this->logger
        );

        // Default configuration
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function($key, $default) {
                switch ($key) {
                    case Application::APP_CONFIG_DEFAULT_EXPIRE_TIME:
                        return '900';
                    case Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME:
                        return '900';
                    default:
                        return $default;
                }
            });
    }

    // ==================== Token Exchange Tests ====================

    public function testTokenExchangeMissingSubjectToken() {
        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return null;
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('subject_token', $result->getData()['error_description']);
    }

    public function testTokenExchangeMissingClientId() {
        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'some_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });
        
        $this->request
            ->method('getHeader')
            ->willReturn('');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testTokenExchangeClientNotFound() {
        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'some_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $this->request
            ->method('getHeader')
            ->willReturn('');

        $this->clientMapper
            ->method('getByIdentifier')
            ->willThrowException(new ClientNotFoundException('Client not found'));

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'invalid-client-id');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testTokenExchangeClientAuthenticationFailed() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setTexEnabled(true);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'some_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'wrong-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testTokenExchangeNotEnabled() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setTexEnabled(false); // TEX not enabled

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'some_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('not enabled', $result->getData()['error_description']);
    }

    public function testTokenExchangeInvalidSubjectToken() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'invalid_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $this->accessTokenMapper
            ->method('getByCode')
            ->willThrowException(new AccessTokenNotFoundException('Token not found'));
        
        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willThrowException(new AccessTokenNotFoundException('Token not found'));

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_grant', $result->getData()['error']);
    }

    public function testTokenExchangeSubjectTokenWrongClient() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $otherClient = new Client('other-client', ['https://test.org'], 'RS256');
        $otherClient->setId(2);

        $accessToken = new AccessToken();
        $accessToken->setClientId(2); // Token belongs to other client
        $accessToken->setUserId('user1');
        $accessToken->setScope('openid profile');
        $accessToken->setRefreshed(time());

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'valid_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willReturn($accessToken);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_grant', $result->getData()['error']);
        $this->assertStringContainsString('not valid for this client', $result->getData()['error_description']);
    }

    public function testTokenExchangeSuccess() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(time());
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]); // No group restrictions
        $this->texTargetMapper->method('getByClientId')->willReturn([]);

        // Mock new token generation
        $newToken = new AccessToken();
        $newToken->setAccessToken('new_jwt_token');
        
        $this->secureRandom->method('generate')->willReturn('new_refresh_token');
        $this->jwtGenerator->method('generateAccessToken')
            ->willReturn('new_jwt_token');
        $this->jwtGenerator->method('generateIdToken')
            ->willReturn('new_id_token');
        
        // Mock time
        $this->time->method('getTime')->willReturn(1000);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'old_jwt_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return 'openid profile';
                    default:
                        return null;
                }
            });

        // Mock server protocol and host
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('example.com');

        $this->accessTokenMapper->method('insert')->willReturn($newToken);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertArrayHasKey('access_token', $result->getData());
        $this->assertArrayHasKey('token_type', $result->getData());
        $this->assertEquals('Bearer', $result->getData()['token_type']);
    }

    public function testTokenExchangeWithResource() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(time());
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]); // No group restrictions

        // Create actual TEX target
        $texTarget = new TexTargets();
        $texTarget->setResourceUrl('https://resource-server.example/');
        $texTarget->setId(1);
        $texTarget->setUsedAt(0);

        $this->texTargetMapper->method('getByClientId')->willReturn([$texTarget]);

        // Mock new token generation
        $newToken = new AccessToken();
        $newToken->setAccessToken('new_jwt_token');
        
        $this->secureRandom->method('generate')->willReturn('new_refresh_token');
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new_jwt_token');
        $this->jwtGenerator->method('generateIdToken')->willReturn(null);
        
        // Mock time
        $this->time->method('getTime')->willReturn(1000);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'old_jwt_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return 'https://resource-server.example/';
                    case 'scope':
                        return 'openid profile';
                    default:
                        return null;
                }
            });

        $this->accessTokenMapper->method('insert')->willReturn($newToken);
        $this->texTargetMapper->method('markUsed')->willReturn(true);
        $this->urlGenerator->method('getServerProtocol')->willReturn('https');
        $this->urlGenerator->method('getServerHost')->willReturn('example.com');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertArrayHasKey('access_token', $result->getData());
    }

    public function testTokenExchangeWithInvalidResource() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(time());
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);

        // Mock TEX targets - empty list
        $this->texTargetMapper->method('getByClientId')->willReturn([]);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'old_jwt_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return 'https://invalid-resource.example/';
                    case 'scope':
                        return 'openid profile';
                    default:
                        return null;
                }
            });

        // Mock time
        $this->time->method('getTime')->willReturn(1000);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeWithInvalidScope() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);
        $client->setTexAllowedScopes('openid profile'); // Only openid and profile allowed

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(time());
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);
        $this->texTargetMapper->method('getByClientId')->willReturn([]);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'old_jwt_token';
                    case 'subject_token_type':
                        return 'access_token';
                    case 'resource':
                        return null;
                    case 'scope':
                        return 'openid profile email'; // email is not allowed
                    default:
                        return null;
                }
            });

        // Mock time
        $this->time->method('getTime')->willReturn(1000);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_scope', $result->getData()['error']);
    }

    // ==================== Authorization Code Flow Tests ====================

    public function testGetTokenWithInvalidGrantType() {
        $result = $this->controller->getToken('invalid_grant_type');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('unsupported_grant_type', $result->getData()['error']);
    }
}
