<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

use OCP\AppFramework\Http;
use OCP\IRequest;
use OC\Security\Bruteforce\Throttler;
use OC\Security\Ip\BruteforceAllowList;
use OC\Security\Ip\Factory;
use OC\Security\Bruteforce\Backend\IBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\RegistrationTokenService;
use OCP\Security\ISecureRandom;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ILogger;

use OCA\OIDCIdentityProvider\Controller\DynamicRegistrationController;

class DynamicRegistrationControllerTest extends TestCase {
    protected $controller;
    /** @var IRequest */
    protected $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    protected $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper */
    protected $customClaimMapper;
    /** @var ISecureRandom */
    protected $secureRandom;
    /** @var \PHPUnit\Framework\MockObject\MockObject|AccessTokenMapper  */
    protected $accessTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriMapper  */
    protected $redirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|LogoutRedirectUriMapper  */
    protected $logoutRedirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RegistrationTokenService */
    protected $registrationTokenService;
    /** @var ITimeFactory */
    protected $time;
    /** @var IBackend */
    protected $throttlerBackend;
    /** @var Throttler */
    protected $throttler;
    /** @var IURLGenerator */
    protected $urlGenerator;
    /** @var IConfig */
    protected $config;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    protected $appConfig;
    /** @var IDBConnection */
    protected $db;
    /** @var LoggerInterface */
    protected $logger;
    /** @var BruteforceAllowList */
    private $bruteforceAllowList;

    public function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->time = $this->createMock(ITimeFactory::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->throttlerBackend = $this->createMock(IBackend::class);
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $appConfigMock = $this->createMock(\OCP\IAppConfig::class);
        $this->bruteforceAllowList = new BruteforceAllowList($appConfigMock, new Factory());
        
        // Create accessTokenMapper with constructor arguments
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $reflection1 = new \ReflectionClass(AccessTokenMapper::class);
        $constructor1 = $reflection1->getConstructor();
        $constructor1->invoke($this->accessTokenMapper, $this->db, $this->time, $this->appConfig);
        
        // Create redirectUriMapper with constructor arguments
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $reflection2 = new \ReflectionClass(RedirectUriMapper::class);
        $constructor2 = $reflection2->getConstructor();
        $constructor2->invoke($this->redirectUriMapper, $this->db, $this->time, $this->appConfig);
        
        // Create logoutRedirectUriMapper with constructor arguments
        $this->logoutRedirectUriMapper = $this->createMock(LogoutRedirectUriMapper::class);
        $reflection3 = new \ReflectionClass(LogoutRedirectUriMapper::class);
        $constructor3 = $reflection3->getConstructor();
        $constructor3->invoke($this->logoutRedirectUriMapper, $this->db, $this->time, $this->appConfig);
        
        $this->registrationTokenService = $this->createMock(RegistrationTokenService::class);
        
        // Create throttler with constructor arguments
        $this->throttler = $this->createMock(Throttler::class);
        $reflection4 = new \ReflectionClass(Throttler::class);
        $constructor4 = $reflection4->getConstructor();
        $constructor4->invoke($this->throttler, $this->time, $this->logger, $this->config, $this->throttlerBackend, $this->bruteforceAllowList);
        
        $this->customClaimMapper = $this->createMock(CustomClaimMapper::class);
        
        // Create clientMapper with constructor arguments
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $reflection5 = new \ReflectionClass(ClientMapper::class);
        $constructor5 = $reflection5->getConstructor();
        $constructor5->invoke($this->clientMapper, $this->db, $this->time, $this->appConfig, $this->redirectUriMapper, $this->customClaimMapper, $this->secureRandom, $this->logger);


        $this->controller = new DynamicRegistrationController(
            'oidc',
            $this->request,
            $this->clientMapper,
            $this->secureRandom,
            $this->accessTokenMapper,
            $this->redirectUriMapper,
            $this->logoutRedirectUriMapper,
            $this->registrationTokenService,
            $this->time,
            $this->throttler,
            $this->urlGenerator,
            $this->appConfig,
            $this->logger
        );
    }

    public function testDisabled() {
        $result = $this->controller->registerClient();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('dynamic_registration_not_allowed', $result->getData()['error']);
    }

    public function testNoRedirectUris() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('true');

        $result = $this->controller->registerClient();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('no_redirect_uris_provided', $result->getData()['error']);
    }

    public function testEmptyRedirectUris() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('true');

        $result = $this->controller->registerClient([]);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('no_redirect_uris_provided', $result->getData()['error']);
    }

    public function testMaxNumClientsExceeded() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('true');

        // Return max number of clients 1000
        $this->clientMapper
            ->method('getNumDcrClients')
            ->willReturn(101);

        $result = $this->controller->registerClient(['https://test.org/redirect']);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('max_num_clients_exceeded', $result->getData()['error']);
    }

    public function testClientCreated() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnMap([
                ['dynamic_client_registration', 'false', 'true'],
                ['client_expire_time', '3600', '3600'],
                ['default_token_type', 'opaque', 'opaque']
            ]);

        // Return max number of clients 1000
        $this->clientMapper
            ->method('getNumDcrClients')
            ->willReturn(100);

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    // Set ID on the client to simulate database insert
                    $reflection = new \ReflectionClass($arg);
                    $property = $reflection->getProperty('id');
                    $property->setAccessible(true);
                    $property->setValue($arg, 1);
                    return $arg;
                }
            );

        // Create real RegistrationToken object
        $registrationToken = new \OCA\OIDCIdentityProvider\Db\RegistrationToken();
        $registrationToken->setToken('mock_registration_token_12345');
        $this->registrationTokenService
            ->method('generateToken')
            ->willReturn($registrationToken);

        $ts = time();
        $result = $this->controller->registerClient(['https://test.org/redirect'], 'TEST-CLIENT');
        $this->assertEquals(Http::STATUS_CREATED, $result->getStatus());

        $client = $result->getData();
        var_dump($client);

        $this->assertEquals('TEST-CLIENT', $client['client_name']);
        $this->assertEquals('https://test.org/redirect', $client['redirect_uris'][0]);
        $this->assertEquals('client_secret_post', $client['token_endpoint_auth_method']);
        $this->assertEquals('code', $client['response_types'][0]);
        $this->assertEquals('authorization_code', $client['grant_types'][0]);
        $this->assertEquals('web', $client['application_type']);
        $this->assertEquals($ts, $client['client_id_issued_at']);
        $this->assertEquals($ts + 3600, $client['client_secret_expires_at']);
    }

    public function testClientCreatedWithValidScope() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnMap([
                ['dynamic_client_registration', 'false', 'true'],
                ['client_expire_time', '3600', '3600'],
                ['default_token_type', 'opaque', 'opaque']
            ]);

        // Return max number of clients 100
        $this->clientMapper
            ->method('getNumDcrClients')
            ->willReturn(50);

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    // Set ID on the client to simulate database insert
                    $reflection = new \ReflectionClass($arg);
                    $property = $reflection->getProperty('id');
                    $property->setAccessible(true);
                    $property->setValue($arg, 1);
                    return $arg;
                }
            );

        // Create real RegistrationToken object
        $registrationToken = new \OCA\OIDCIdentityProvider\Db\RegistrationToken();
        $registrationToken->setToken('mock_registration_token_12345');
        $this->registrationTokenService
            ->method('generateToken')
            ->willReturn($registrationToken);

        $result = $this->controller->registerClient(
            ['https://test.org/redirect'],
            'TEST-CLIENT',
            'RS256',
            ['code'],
            'web',
            'openid profile email custom:read custom:write'
        );
        $this->assertEquals(Http::STATUS_CREATED, $result->getStatus());

        $client = $result->getData();
        $this->assertEquals('openid profile email custom:read custom:write', $client['scope']);
    }

    public function testClientCreatedWithNoScope() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnMap([
                ['dynamic_client_registration', 'false', 'true'],
                ['client_expire_time', '3600', '3600'],
                ['default_token_type', 'opaque', 'opaque']
            ]);

        // Return max number of clients 100
        $this->clientMapper
            ->method('getNumDcrClients')
            ->willReturn(50);

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    // Set ID on the client to simulate database insert
                    $reflection = new \ReflectionClass($arg);
                    $property = $reflection->getProperty('id');
                    $property->setAccessible(true);
                    $property->setValue($arg, 1);
                    return $arg;
                }
            );

        // Create real RegistrationToken object
        $registrationToken = new \OCA\OIDCIdentityProvider\Db\RegistrationToken();
        $registrationToken->setToken('mock_registration_token_12345');
        $this->registrationTokenService
            ->method('generateToken')
            ->willReturn($registrationToken);

        $result = $this->controller->registerClient(
            ['https://test.org/redirect'],
            'TEST-CLIENT'
        );
        $this->assertEquals(Http::STATUS_CREATED, $result->getStatus());

        $client = $result->getData();
        $this->assertEquals('', $client['scope']);
    }

    public function testScopeWithInvalidCharacters() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturn('true');

        // Return max number of clients 100
        $this->clientMapper
            ->method('getNumDcrClients')
            ->willReturn(50);

        $result = $this->controller->registerClient(
            ['https://test.org/redirect'],
            'TEST-CLIENT',
            'RS256',
            ['code'],
            'web',
            'openid profile email@invalid scope#bad'
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_scope', $result->getData()['error']);
    }

    public function testScopeTruncation() {
        // Return true for getAppValue('dynamic_client_registration', 'false')
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnMap([
                ['dynamic_client_registration', 'false', 'true'],
                ['client_expire_time', '3600', '3600'],
                ['default_token_type', 'opaque', 'opaque']
            ]);

        // Return max number of clients 100
        $this->clientMapper
            ->method('getNumDcrClients')
            ->willReturn(50);

        $this->clientMapper
            ->method('insert')
            ->willReturnCallBack (
                function ($arg) {
                    // Set ID on the client to simulate database insert
                    $reflection = new \ReflectionClass($arg);
                    $property = $reflection->getProperty('id');
                    $property->setAccessible(true);
                    $property->setValue($arg, 1);
                    return $arg;
                }
            );

        // Create real RegistrationToken object
        $registrationToken = new \OCA\OIDCIdentityProvider\Db\RegistrationToken();
        $registrationToken->setToken('mock_registration_token_12345');
        $this->registrationTokenService
            ->method('generateToken')
            ->willReturn($registrationToken);

        // Create a scope longer than 512 characters
        $longScope = str_repeat('scope ', 100); // This creates a 600 character string

        $result = $this->controller->registerClient(
            ['https://test.org/redirect'],
            'TEST-CLIENT',
            'RS256',
            ['code'],
            'web',
            $longScope
        );

        $this->assertEquals(Http::STATUS_CREATED, $result->getStatus());

        $client = $result->getData();
        // Verify scope was truncated to 512 characters (database column size)
        $this->assertEquals(512, strlen($client['scope']));
    }

}
