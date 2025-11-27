<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Services\IAppConfig;

use OCA\OIDCIdentityProvider\Controller\IntrospectionController;

class IntrospectionControllerTest extends TestCase {
    protected $controller;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    protected $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    protected $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
    protected $userManager;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    protected $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    protected $appConfig;
    /** @var IDBConnection */
    protected $db;
    /** @var LoggerInterface */
    protected $logger;

    public function setUp(): void {
        parent::setUp();
        $this->request = $this->getMockBuilder(IRequest::class)->getMock();
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->userManager = $this->getMockBuilder(IUserManager::class)->getMock();
        $this->accessTokenMapper = $this->getMockBuilder(AccessTokenMapper::class)
            ->setConstructorArgs([$this->db, $this->time, $this->appConfig])
            ->getMock();
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->controller = new IntrospectionController(
            'oidc',
            $this->request,
            $this->clientMapper,
            $this->accessTokenMapper,
            $this->userManager,
            $this->time,
            $this->appConfig,
            $this->logger
        );
    }

    public function testInvalidClientCredentials() {
        // No credentials provided
        $this->request
            ->method('getHeader')
            ->willReturn('');

        $this->request
            ->method('getParam')
            ->willReturn(null);

        $result = $this->controller->introspectToken('some_token');

        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testMissingTokenParameter() {
        // Setup valid client credentials
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');

        $this->request
            ->method('getHeader')
            ->willReturn('Basic ' . base64_encode('test-client:test-secret'));

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $result = $this->controller->introspectToken('');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
    }

    public function testTokenNotFound() {
        // Setup valid client credentials
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');

        $this->request
            ->method('getHeader')
            ->willReturn('Basic ' . base64_encode('test-client:test-secret'));

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willThrowException(new \Exception('Token not found'));

        $result = $this->controller->introspectToken('invalid_token');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertFalse($result->getData()['active']);
    }

    public function testExpiredToken() {
        // Setup valid client credentials
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');

        $this->request
            ->method('getHeader')
            ->willReturn('Basic ' . base64_encode('test-client:test-secret'));

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        // Create an expired token
        $accessToken = new AccessToken();
        $accessToken->setCreated(1000000); // Old timestamp
        $accessToken->setUserId('user1');
        $accessToken->setClientId(1);

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willReturn($accessToken);

        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('900'); // 900 seconds expire time

        $this->time
            ->method('getTime')
            ->willReturn(2000000); // Way after expiration

        $result = $this->controller->introspectToken('expired_token');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertFalse($result->getData()['active']);
    }

    public function testValidTokenIntrospection() {
        // Setup valid client credentials - client owns the token
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');
        $client->setClientIdentifier('client123');

        $this->request
            ->method('getHeader')
            ->willReturn('Basic ' . base64_encode('test-client:test-secret'));

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        // Create a valid token
        $accessToken = new AccessToken();
        $accessToken->setCreated(1000000);
        $accessToken->setUserId('user1');
        $accessToken->setClientId(1);
        $accessToken->setScope('openid profile email');
        $accessToken->setResource('https://resource.example.com');

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willReturn($accessToken);

        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('900'); // 900 seconds expire time

        $this->time
            ->method('getTime')
            ->willReturn(1000500); // Within expiration window

        // Mock user
        $user = $this->getMockBuilder(IUser::class)->getMock();
        $user->method('getUID')->willReturn('user1');

        $this->userManager
            ->method('get')
            ->willReturn($user);

        // Mock token client - same as requesting client (client owns token)
        $tokenClient = new Client('token-client', ['https://app.org'], 'RS256');
        $tokenClient->setClientIdentifier('client123');

        $this->clientMapper
            ->method('getByUid')
            ->willReturn($tokenClient);

        $result = $this->controller->introspectToken('valid_token');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['active']);
        $this->assertEquals('openid profile email', $data['scope']);
        $this->assertEquals('client123', $data['client_id']);
        $this->assertEquals('user1', $data['username']);
        $this->assertEquals('Bearer', $data['token_type']);
        $this->assertEquals('user1', $data['sub']);
    }

    public function testTokenIntrospectionAsResourceServer() {
        // Setup client credentials for resource server
        $resourceClient = new Client('resource-server', ['https://resource.example.com'], 'RS256');
        $resourceClient->setSecret('resource-secret');
        $resourceClient->setClientIdentifier('resource-server-id');

        $this->request
            ->method('getHeader')
            ->willReturn('Basic ' . base64_encode('resource-server:resource-secret'));

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($resourceClient);

        // Create a valid token with resource matching the requesting client
        $accessToken = new AccessToken();
        $accessToken->setCreated(1000000);
        $accessToken->setUserId('user1');
        $accessToken->setClientId(1);
        $accessToken->setScope('openid profile email');
        $accessToken->setResource('resource-server-id'); // Matches requesting client

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willReturn($accessToken);

        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('900');

        $this->time
            ->method('getTime')
            ->willReturn(1000500);

        // Mock user
        $user = $this->getMockBuilder(IUser::class)->getMock();
        $user->method('getUID')->willReturn('user1');

        $this->userManager
            ->method('get')
            ->willReturn($user);

        // Mock token client - different from requesting client
        $tokenClient = new Client('token-owner', ['https://app.org'], 'RS256');
        $tokenClient->setClientIdentifier('client123');

        $this->clientMapper
            ->method('getByUid')
            ->willReturn($tokenClient);

        $result = $this->controller->introspectToken('valid_token');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['active']);
    }

    public function testTokenIntrospectionDeniedWrongAudience() {
        // Setup client credentials for unauthorized client
        $unauthorizedClient = new Client('unauthorized-client', ['https://evil.com'], 'RS256');
        $unauthorizedClient->setSecret('evil-secret');
        $unauthorizedClient->setClientIdentifier('evil-client-id');

        $this->request
            ->method('getHeader')
            ->willReturn('Basic ' . base64_encode('unauthorized-client:evil-secret'));

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($unauthorizedClient);

        // Create a valid token with different resource and client
        $accessToken = new AccessToken();
        $accessToken->setCreated(1000000);
        $accessToken->setUserId('user1');
        $accessToken->setClientId(1);
        $accessToken->setScope('openid profile email');
        $accessToken->setResource('different-resource-server'); // Does not match requesting client

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willReturn($accessToken);

        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('900');

        $this->time
            ->method('getTime')
            ->willReturn(1000500);

        // Mock user
        $user = $this->getMockBuilder(IUser::class)->getMock();
        $user->method('getUID')->willReturn('user1');

        $this->userManager
            ->method('get')
            ->willReturn($user);

        // Mock token client - different from requesting client
        $tokenClient = new Client('token-owner', ['https://app.org'], 'RS256');
        $tokenClient->setClientIdentifier('legitimate-client-id');

        $this->clientMapper
            ->method('getByUid')
            ->willReturn($tokenClient);

        $result = $this->controller->introspectToken('valid_token');

        // Should return inactive to not reveal token exists
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertFalse($result->getData()['active']);
    }

    public function testClientAuthenticationWithPostBody() {
        // Setup client credentials in POST body
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setSecret('test-secret');

        $this->request
            ->method('getHeader')
            ->willReturn('');

        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['client_id', null, 'test-client'],
                ['client_secret', null, 'test-secret'],
                ['token', null, 'some_token']
            ]);

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willThrowException(new \Exception('Token not found'));

        $result = $this->controller->introspectToken('some_token');

        // Should succeed with authentication and return inactive token
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertFalse($result->getData()['active']);
    }

}
