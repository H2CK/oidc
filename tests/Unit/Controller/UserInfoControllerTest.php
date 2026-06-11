<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use OCP\IRequest;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IGroup;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\IURLGenerator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Config\IUserConfig;
use OCP\IConfig;

// Dummy Throttler class for testing purposes
if (!class_exists('\\OC\\Security\\Bruteforce\\Throttler')) {
    eval('namespace OC\Security\Bruteforce { class Throttler {} }');
}

use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Controller\UserInfoController;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;

use Psr\Log\LoggerInterface;
use OCA\DAV\CardDAV\Converter;

class UserInfoControllerTest extends TestCase {
    
    /** @var UserInfoController */
    protected $controller;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    protected $request;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper */
    private $accessTokenMapper;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    private $clientMapper;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    
    /** @var \OC\Security\Bruteforce\Throttler|\PHPUnit\Framework\MockObject\MockObject */
    private $throttler;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
    private $userManager;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager */
    private $groupManager;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAccountManager */
    private $accountManager;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IUserConfig */
    private $userConfig;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
    private $config;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimService */
    private $customClaimService;
    
    /** @var LoggerInterface */
    private $logger;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
    private $urlGenerator;
    
    /** @var \PHPUnit\Framework\MockObject\MockObject|Converter */
    private $converter;

    public function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('localhost');
        
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('notice')->willReturnCallback(function() {});
        $this->logger->method('error')->willReturnCallback(function() {});
        $this->logger->method('warning')->willReturnCallback(function() {});
        $this->logger->method('debug')->willReturnCallback(function() {});
        
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->throttler = $this->createMock('\\OC\\Security\\Bruteforce\\Throttler');
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->customClaimService = $this->createMock(CustomClaimService::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('getWebroot')->willReturn('/');
        $this->converter = $this->createMock(Converter::class);
        
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function($key, $default) {
                switch($key) {
                    case 'default_expire_time': return '3600';
                    case 'default_client_expire_time': return '86400';
                    case 'group_claim_type': return 'gid';
                    case 'roles_claim_type': return 'null';
                    case 'restrict_user_information': return '';
                    case 'allow_user_settings': return 'allow_user_settings';
                    case 'overwrite_email_verified': return 'false';
                    default: return $default;
                }
            });
        
        $this->controller = new UserInfoController(
            'oidc',
            $this->request,
            $this->urlGenerator,
            $this->accessTokenMapper,
            $this->clientMapper,
            $this->time,
            $this->throttler,
            $this->userManager,
            $this->groupManager,
            $this->accountManager,
            $this->appConfig,
            $this->userConfig,
            $this->config,
            $this->customClaimService,
            $this->logger
        );
    }

    public function testGetInfoNoBearerToken() {
        // For this test, we'll mock the Authorization header to be absent
        $originalServer = $_SERVER ?? [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['Authorization']);
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertEquals('No bearer token found in request.', $result->getData()['error_description']);
    }

    public function testGetInfoAccessTokenNotFound() {
        $token = 'test-token';
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willThrowException(new AccessTokenNotFoundException());
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertEquals('Could not find provided bearer token.', $result->getData()['error_description']);
    }

    public function testGetInfoClientNotFound() {
        $token = 'test-token';
        
        // Create a real AccessToken entity and set its properties
        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willReturn($accessToken);
        
        $this->clientMapper->method('getByUid')
            ->with(1)
            ->willThrowException(new ClientNotFoundException());
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertEquals('Could not find client for access token.', $result->getData()['error_description']);
    }

    public function testGetInfoClientExpired() {
        $token = 'test-token';
        $now = time();
        
        // Create real Client entity
        $client = new Client();
        $client->id = 1;
        $client->setDcr(true);
        $client->setIssuedAt($now - 100000); // Issued long time ago
        
        // Create real AccessToken entity
        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        $accessToken->setUserId('user1');
        $accessToken->setRefreshed($now);
        $accessToken->setScope('openid profile email');
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willReturn($accessToken);
        
        $this->clientMapper->method('getByUid')
            ->with(1)
            ->willReturn($client);
        
        $this->time->method('getTime')->willReturn($now);
        
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function($key, $default) {
                if ($key === 'default_client_expire_time') {
                    return '3600';
                }
                return $default;
            });
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('expired_client', $result->getData()['error']);
        $this->assertEquals('Client expired.', $result->getData()['error_description']);
    }

    public function testGetInfoAccessTokenExpired() {
        $token = 'test-token';
        $now = time();
        
        // Create real Client entity
        $client = new Client();
        $client->id = 1;
        $client->setDcr(false);
        $client->setClientIdentifier('client1');
        $client->setSecret('secret');
        $client->setEmailRegex('');
        
        // Create real AccessToken entity
        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        $accessToken->setUserId('user1');
        $accessToken->setRefreshed($now - 10000); // Refreshed long time ago
        $accessToken->setScope('openid profile email');
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willReturn($accessToken);
        
        $this->clientMapper->method('getByUid')
            ->with(1)
            ->willReturn($client);
        
        $this->time->method('getTime')->willReturn($now);
        
        $this->accessTokenMapper->expects($this->once())
            ->method('delete')
            ->with($accessToken);
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_grant', $result->getData()['error']);
        $this->assertEquals('Access token already expired.', $result->getData()['error_description']);
    }

    public function testGetInfoSuccess() {
        $token = 'test-token';
        $now = time();
        
        // Create real Client entity
        $client = new Client();
        $client->id = 1;
        $client->setDcr(false);
        $client->setClientIdentifier('client1');
        $client->setSecret('secret');
        $client->setEmailRegex('');
        
        // Create real AccessToken entity
        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        $accessToken->setUserId('user1');
        $accessToken->setRefreshed($now);
        $accessToken->setScope('openid profile email');
        
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('getLastLogin')->willReturn($now);
        $user->method('getQuota')->willReturn('none');
        
        $account = $this->createMock(IAccount::class);
        
        $displayNameProperty = $this->createMock(IAccountProperty::class);
        $displayNameProperty->method('getValue')->willReturn('Test User');
        
        $emailProperty = $this->createMock(IAccountProperty::class);
        $emailProperty->method('getValue')->willReturn('test@example.com');
        $emailProperty->method('getVerified')->willReturn(\OCP\Accounts\IAccountManager::VERIFIED);
        
        $account->method('getProperty')
            ->willReturnCallback(function($property) use ($displayNameProperty, $emailProperty) {
                if ($property === IAccountManager::PROPERTY_DISPLAYNAME) {
                    return $displayNameProperty;
                }
                if ($property === IAccountManager::PROPERTY_EMAIL) {
                    return $emailProperty;
                }
                return $this->createMock(IAccountProperty::class);
            });
        
        $this->userManager->method('get')->with('user1')->willReturn($user);
        $this->groupManager->method('getUserGroups')->with($user)->willReturn([]);
        $this->accountManager->method('getAccount')->with($user)->willReturn($account);
        
        $this->customClaimService->method('provideCustomClaims')
            ->with(1, 'openid profile email', 'user1')
            ->willReturn([]);
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willReturn($accessToken);
        
        $this->clientMapper->method('getByUid')
            ->with(1)
            ->willReturn($client);
        
        $this->time->method('getTime')->willReturn($now);
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('sub', $data);
        $this->assertEquals('user1', $data['sub']);
        $this->assertArrayHasKey('preferred_username', $data);
        $this->assertEquals('user1', $data['preferred_username']);
        $this->assertArrayHasKey('scope', $data);
        $this->assertEquals('openid profile email', $data['scope']);
        $this->assertEquals('Test User', $data['name']);
        $this->assertArrayNotHasKey('middle_name', $data);
    }

    public function testGetInfoPostSuccess() {
        $token = 'test-token';
        $now = time();
        
        // Create real Client entity
        $client = new Client();
        $client->id = 1;
        $client->setDcr(false);
        $client->setClientIdentifier('client1');
        $client->setSecret('secret');
        $client->setEmailRegex('');
        
        // Create real AccessToken entity
        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        $accessToken->setUserId('user1');
        $accessToken->setRefreshed($now);
        $accessToken->setScope('openid profile email');
        
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('getLastLogin')->willReturn($now);
        $user->method('getQuota')->willReturn('none');
        
        $account = $this->createMock(IAccount::class);
        
        $displayNameProperty = $this->createMock(IAccountProperty::class);
        $displayNameProperty->method('getValue')->willReturn('Test User');
        
        $emailProperty = $this->createMock(IAccountProperty::class);
        $emailProperty->method('getValue')->willReturn('test@example.com');
        $emailProperty->method('getVerified')->willReturn(\OCP\Accounts\IAccountManager::VERIFIED);
        
        $account->method('getProperty')
            ->willReturnCallback(function($property) use ($displayNameProperty, $emailProperty) {
                if ($property === IAccountManager::PROPERTY_DISPLAYNAME) {
                    return $displayNameProperty;
                }
                if ($property === IAccountManager::PROPERTY_EMAIL) {
                    return $emailProperty;
                }
                return $this->createMock(IAccountProperty::class);
            });
        
        $this->userManager->method('get')->with('user1')->willReturn($user);
        $this->groupManager->method('getUserGroups')->with($user)->willReturn([]);
        $this->accountManager->method('getAccount')->with($user)->willReturn($account);
        
        $this->customClaimService->method('provideCustomClaims')
            ->with(1, 'openid profile email', 'user1')
            ->willReturn([]);
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willReturn($accessToken);
        
        $this->clientMapper->method('getByUid')
            ->with(1)
            ->willReturn($client);
        
        $this->time->method('getTime')->willReturn($now);
        
        $result = $this->controller->getInfoPost();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('sub', $data);
        $this->assertEquals('user1', $data['sub']);
    }

    public static function bearerTokenProvider(): array {
        return [
            'Bearer token' => ['Bearer abc123def456'],
            'Bearer with spaces' => ['Bearer   abc123def456'],
        ];
    }

    #[DataProvider('bearerTokenProvider')]
    public function testGetBearerTokenExtractsToken(string $authHeader) {
        // Test the token extraction logic
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('getAuthorizationHeader');
        $method->setAccessible(true);
        
        // Mock $_SERVER
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        
        $header = $method->invoke($this->controller);
        
        $_SERVER = $originalServer;
        
        // getAuthorizationHeader trims the header, so expect trimmed value
        $this->assertEquals(trim($authHeader), $header);
        
        // Now test the getBearerToken method
        $method2 = $reflection->getMethod('getBearerToken');
        $method2->setAccessible(true);
        
        $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
        $token = $method2->invoke($this->controller);
        
        $_SERVER = $originalServer;
        
        $this->assertEquals('abc123def456', $token);
    }

    public function testGetBearerTokenReturnsNullWhenNoToken() {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('getBearerToken');
        $method->setAccessible(true);
        
        $originalServer = $_SERVER ?? [];
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_SERVER['Authorization']);
        
        $token = $method->invoke($this->controller);
        
        $_SERVER = $originalServer;
        
        $this->assertNull($token);
    }

    public function testGetInfoWithGroups() {
        $token = 'test-token';
        $now = time();
        
        // Create real Client entity
        $client = new Client();
        $client->id = 1;
        $client->setDcr(false);
        $client->setClientIdentifier('client1');
        $client->setSecret('secret');
        $client->setEmailRegex('');
        
        // Create real AccessToken entity
        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        $accessToken->setUserId('user1');
        $accessToken->setRefreshed($now);
        $accessToken->setScope('openid profile email groups roles');
        
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('getLastLogin')->willReturn($now);
        $user->method('getQuota')->willReturn('none');
        
        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $group1->method('getDisplayName')->willReturn('Group One');
        
        $group2 = $this->createMock(IGroup::class);
        $group2->method('getGID')->willReturn('group2');
        $group2->method('getDisplayName')->willReturn('');
        
        $account = $this->createMock(IAccount::class);
        
        $displayNameProperty = $this->createMock(IAccountProperty::class);
        $displayNameProperty->method('getValue')->willReturn('Test User');
        
        $emailProperty = $this->createMock(IAccountProperty::class);
        $emailProperty->method('getValue')->willReturn('test@example.com');
        $emailProperty->method('getVerified')->willReturn(\OCP\Accounts\IAccountManager::VERIFIED);
        
        $account->method('getProperty')
            ->willReturnCallback(function($property) use ($displayNameProperty, $emailProperty) {
                if ($property === IAccountManager::PROPERTY_DISPLAYNAME) {
                    return $displayNameProperty;
                }
                if ($property === IAccountManager::PROPERTY_EMAIL) {
                    return $emailProperty;
                }
                return $this->createMock(IAccountProperty::class);
            });
        
        $this->userManager->method('get')->with('user1')->willReturn($user);
        $this->groupManager->method('getUserGroups')->with($user)->willReturn([$group1, $group2]);
        $this->accountManager->method('getAccount')->with($user)->willReturn($account);
        
        $this->customClaimService->method('provideCustomClaims')
            ->with(1, 'openid profile email groups roles', 'user1')
            ->willReturn([]);
        
        // Set up $_SERVER for getBearerToken to find the token
        $originalServer = $_SERVER ?? [];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        
        $this->accessTokenMapper->method('getByAccessToken')
            ->with($token)
            ->willReturn($accessToken);
        
        $this->clientMapper->method('getByUid')
            ->with(1)
            ->willReturn($client);
        
        $this->time->method('getTime')->willReturn($now);
        
        $result = $this->controller->getInfo();
        
        $_SERVER = $originalServer;
        
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        
        // Check groups are included
        $this->assertArrayHasKey('groups', $data);
        $this->assertContains('group1', $data['groups']);
        $this->assertContains('group2', $data['groups']);
        
        // Check roles are included (uses GID since group_claim_type is 'gid' and roles_claim_type is 'null')
        $this->assertArrayHasKey('roles', $data);
        $this->assertContains('group1', $data['roles']);
        $this->assertContains('group2', $data['roles']);
    }
}
