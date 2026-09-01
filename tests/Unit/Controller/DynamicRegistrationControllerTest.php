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

    /** @dataProvider blockedDynamicBackChannelUriProvider */
    public function testDynamicRegistrationRejectsUnsafeBackChannelLogoutUri(string $uri): void {
        $this->appConfig->method('getAppValueString')->willReturn('true');
        $this->clientMapper->method('getNumDcrClients')->willReturn(0);
        $this->clientMapper->expects($this->never())->method('insert');

        $result = $this->controller->registerClient(
            redirect_uris: ['https://rp.example/callback'],
            client_name: 'TEST-CLIENT',
            backchannel_logout_uri: $uri,
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client_metadata', $result->getData()['error']);
    }

    public static function blockedDynamicBackChannelUriProvider(): array {
        return [
            'HTTP even for public address' => ['http://8.8.8.8/logout'],
            'loopback' => ['https://127.0.0.1/logout'],
            'RFC1918' => ['https://10.0.0.1/logout'],
            'link-local' => ['https://169.254.169.254/logout'],
            'IPv6 ULA' => ['https://[fd00:ec2::254]/logout'],
            'cloud metadata hostname' => ['https://metadata.google.internal/computeMetadata/v1'],
            'Alibaba metadata' => ['https://100.100.100.200/latest/meta-data'],
        ];
    }

    public function testDynamicClientConfigurationUpdateRejectsUnsafeBackChannelLogoutUri(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService
            ->method('validateToken')
            ->with('registration-token')
            ->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client();
        $reflection = new \ReflectionClass($client);
        $id = $reflection->getProperty('id');
        $id->setAccessible(true);
        $id->setValue($client, 7);
        $client->setClientIdentifier('client-1');
        $client->setDcr(true);
        $client->setType('confidential');

        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'client-1',
            backchannel_logout_uri: 'https://192.168.10.20/backchannel-logout',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
    }

    public function testDynamicClientConfigurationUpdateRejectsHttpBackChannelLogoutUri(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService
            ->method('validateToken')
            ->with('registration-token')
            ->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client();
        $reflection = new \ReflectionClass($client);
        $id = $reflection->getProperty('id');
        $id->setAccessible(true);
        $id->setValue($client, 7);
        $client->setClientIdentifier('client-1');
        $client->setDcr(true);
        $client->setType('confidential');

        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'client-1',
            backchannel_logout_uri: 'http://8.8.8.8/backchannel-logout',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
    }

    public function testDynamicRegistrationRejectsUnsupportedIdTokenSigningAlgorithm(): void {
        $this->appConfig->method('getAppValueString')->willReturn('true');
        $this->clientMapper->method('getNumDcrClients')->willReturn(0);
        $this->clientMapper->expects($this->never())->method('insert');

        $result = $this->controller->registerClient(
            redirect_uris: ['https://rp.example/callback'],
            id_token_signed_response_alg: 'ES256',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
    }

    public function testDynamicClientConfigurationUpdateRejectsUnsupportedIdTokenSigningAlgorithm(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService->method('validateToken')->with('registration-token')->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client('RP', [], 'RS256', 'confidential');
        $client->setId(7);
        $client->setClientIdentifier('client-1');
        $client->setDcr(true);
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'client-1',
            id_token_signed_response_alg: 'none',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
        $this->assertSame('RS256', $client->getSigningAlg());
    }

    public function testDynamicRegistrationStoresAndReturnsPostLogoutRedirectUris(): void {
        $this->appConfig->method('getAppValueString')->willReturnMap([
            ['dynamic_client_registration', 'false', 'true'],
            ['client_expire_time', '3600', '3600'],
            ['default_token_type', 'opaque', 'opaque'],
        ]);
        $this->clientMapper->method('getNumDcrClients')->willReturn(0);
        $this->clientMapper->method('insert')->willReturnCallback(static function ($client) {
            $client->setId(7);
            return $client;
        });

        $stored = new \OCA\OIDCIdentityProvider\Db\LogoutRedirectUri();
        $stored->setClientId(7);
        $stored->setRedirectUri('https://rp.example/logout/callback');
        $this->logoutRedirectUriMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(static function ($entry): bool {
                return $entry->getClientId() === 7
                    && $entry->getRedirectUri() === 'https://rp.example/logout/callback';
            }))
            ->willReturn($stored);
        $this->logoutRedirectUriMapper->method('getByClientId')->with(7)->willReturn([$stored]);

        $registrationToken = new \OCA\OIDCIdentityProvider\Db\RegistrationToken();
        $registrationToken->setToken('registration-token');
        $this->registrationTokenService->method('generateToken')->willReturn($registrationToken);

        $result = $this->controller->registerClient(
            redirect_uris: ['https://rp.example/callback'],
            client_name: 'RP',
            post_logout_redirect_uris: ['https://rp.example/logout/callback'],
        );

        $this->assertSame(Http::STATUS_CREATED, $result->getStatus());
        $this->assertSame(
            ['https://rp.example/logout/callback'],
            $result->getData()['post_logout_redirect_uris']
        );
    }

    public function testDynamicRegistrationRejectsInvalidPostLogoutRedirectUri(): void {
        $this->appConfig->method('getAppValueString')->willReturn('true');
        $this->clientMapper->method('getNumDcrClients')->willReturn(0);
        $this->clientMapper->expects($this->never())->method('insert');

        $result = $this->controller->registerClient(
            redirect_uris: ['https://rp.example/callback'],
            post_logout_redirect_uris: ['https://user:password@rp.example/logout#fragment'],
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
    }

    public function testDynamicRegistrationRejectsForbiddenPostLogoutRedirectUriSchemes(): void {
        $this->appConfig->method('getAppValueString')->willReturn('true');
        $this->clientMapper->method('getNumDcrClients')->willReturn(0);
        $this->clientMapper->expects($this->never())->method('insert');

        foreach ([
            'javascript:alert(1)',
            'DATA:text/html,<h1>logout</h1>',
            'file:///tmp/logout',
            'VbScRiPt:msgbox(1)',
        ] as $uri) {
            $result = $this->controller->registerClient(
                redirect_uris: ['https://rp.example/callback'],
                post_logout_redirect_uris: [$uri],
            );

            $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus(), $uri);
            $this->assertSame('invalid_client_metadata', $result->getData()['error'], $uri);
        }
    }

    public function testDynamicClientConfigurationUpdateRequiresBodyClientId(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService->method('validateToken')->with('registration-token')->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client('RP', [], 'RS256', 'confidential');
        $client->setId(7);
        $client->setClientIdentifier('client-1');
        $client->setDcr(true);
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');
        $this->registrationTokenService->expects($this->never())->method('rotateToken');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_name: 'Changed RP',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
        $this->assertSame('RP', $client->getName());
    }

    public function testDynamicClientConfigurationUpdateRejectsMismatchingBodyClientId(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService->method('validateToken')->with('registration-token')->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client('RP', [], 'RS256', 'confidential');
        $client->setId(7);
        $client->setClientIdentifier('client-1');
        $client->setDcr(true);
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');
        $this->registrationTokenService->expects($this->never())->method('rotateToken');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'different-client',
            client_name: 'Changed RP',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
        $this->assertSame('RP', $client->getName());
    }

    public function testDynamicClientConfigurationUpdateRejectsMismatchingClientSecret(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService->method('validateToken')->with('registration-token')->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client('RP', [], 'RS256', 'confidential');
        $client->setId(7);
        $client->setClientIdentifier('client-1');
        $client->setSecret('current-secret');
        $client->setDcr(true);
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');
        $this->registrationTokenService->expects($this->never())->method('rotateToken');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'client-1',
            client_secret: 'attacker-chosen-secret',
            client_name: 'Changed RP',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
        $this->assertSame('current-secret', $client->getSecret());
        $this->assertSame('RP', $client->getName());
    }

    public function testDynamicClientConfigurationUpdateReplacesPostLogoutRedirectUris(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService->method('validateToken')->with('registration-token')->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client('RP', [], 'RS256', 'confidential');
        $client->setId(7);
        $client->setClientIdentifier('client-1');
        $client->setSecret('current-secret');
        $client->setDcr(true);
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->method('update')->willReturn($client);

        $newEntry = new \OCA\OIDCIdentityProvider\Db\LogoutRedirectUri();
        $newEntry->setClientId(7);
        $newEntry->setRedirectUri('https://rp.example/logout/new');
        $this->logoutRedirectUriMapper->expects($this->once())->method('deleteByClientId')->with(7);
        $this->logoutRedirectUriMapper->expects($this->once())->method('insert')->willReturn($newEntry);
        $this->logoutRedirectUriMapper->method('getByClientId')->with(7)->willReturn([$newEntry]);

        $newToken = new \OCA\OIDCIdentityProvider\Db\RegistrationToken();
        $newToken->setToken('rotated-token');
        $this->registrationTokenService->method('rotateToken')->with(7)->willReturn($newToken);

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'client-1',
            client_secret: 'current-secret',
            post_logout_redirect_uris: ['https://rp.example/logout/new'],
        );

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame(['https://rp.example/logout/new'], $result->getData()['post_logout_redirect_uris']);
    }

    public function testDynamicClientConfigurationUpdateRejectsForbiddenPostLogoutRedirectUriScheme(): void {
        $this->request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Authorization' ? 'Bearer registration-token' : ''
        );
        $this->registrationTokenService->method('validateToken')->with('registration-token')->willReturn(7);

        $client = new \OCA\OIDCIdentityProvider\Db\Client('RP', [], 'RS256', 'confidential');
        $client->setId(7);
        $client->setClientIdentifier('client-1');
        $client->setDcr(true);
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->clientMapper->expects($this->never())->method('update');
        $this->logoutRedirectUriMapper->expects($this->never())->method('deleteByClientId');
        $this->logoutRedirectUriMapper->expects($this->never())->method('insert');

        $result = $this->controller->updateClientConfiguration(
            clientId: 'client-1',
            client_id: 'client-1',
            post_logout_redirect_uris: ['javascript:alert(1)'],
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_client_metadata', $result->getData()['error']);
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
