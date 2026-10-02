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
use OCP\DB\Exception as DatabaseException;
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
use OCA\OIDCIdentityProvider\Db\RefreshTokenMapper;
use OCA\OIDCIdentityProvider\Db\RefreshToken;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\TexTargets;
use OCA\OIDCIdentityProvider\Db\TexSubjectClientMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCode;
use OCA\OIDCIdentityProvider\Db\GroupScope;
use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use OCA\OIDCIdentityProvider\Service\ScopeCeilingService;
use OCA\OIDCIdentityProvider\Service\ClientAuthorizationService;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;

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
    /** @var \PHPUnit\Framework\MockObject\MockObject|RefreshTokenMapper */
    protected $refreshTokenMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|GroupMapper */
    protected $groupMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|UserConsentMapper */
    protected $userConsentMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|TexTargetMapper */
    protected $texTargetMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|TexSubjectClientMapper */
    protected $texSubjectClientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|DeviceCodeMapper */
    protected $deviceCodeMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|GroupScopeMapper */
    protected $groupScopeMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientAuthorizationService */
    protected $clientAuthorizationService;
    protected bool $clientAuthorizationAllowed = true;
    protected string $refreshReplayGraceSeconds = '5';
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
    /** @var \PHPUnit\Framework\MockObject\MockObject|FormUrlencodedParameterParser */
    protected $formUrlencodedParameterParser;
    protected string $tokenExchangeContentType = 'application/x-www-form-urlencoded';
    protected string $authorizationHeader = '';
    protected ?array $tokenExchangeRawParameters = [];

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
        $this->formUrlencodedParameterParser = $this->createMock(FormUrlencodedParameterParser::class);
        $this->request->method('getHeader')->willReturnCallback(function(string $name): string {
            return match ($name) {
                'Content-Type' => $this->tokenExchangeContentType,
                'Authorization' => $this->authorizationHeader,
                default => '',
            };
        });
        $this->formUrlencodedParameterParser->method('readSelectedParameters')
            ->willReturnCallback(function(array $names): ?array {
                if ($this->tokenExchangeRawParameters === null) {
                    return null;
                }
                $result = [];
                foreach ($names as $name) {
                    $result[$name] = $this->tokenExchangeRawParameters[$name] ?? [];
                }
                return $result;
            });

        // Create accessTokenMapper with constructor arguments
        $this->accessTokenMapper = $this->createMock(AccessTokenMapper::class);
        $reflection = new \ReflectionClass(AccessTokenMapper::class);
        $constructor = $reflection->getConstructor();
        $constructor->invoke($this->accessTokenMapper, $this->db, $this->time, $this->appConfig);

        $this->authorizationCodeMapper = $this->createMock(AuthorizationCodeMapper::class);
        $this->refreshTokenMapper = $this->createMock(RefreshTokenMapper::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->groupMapper = $this->createMock(GroupMapper::class);
        $this->userConsentMapper = $this->createMock(UserConsentMapper::class);
        $this->texTargetMapper = $this->createMock(TexTargetMapper::class);
        $this->texSubjectClientMapper = $this->createMock(TexSubjectClientMapper::class);
        $this->deviceCodeMapper = $this->createMock(DeviceCodeMapper::class);
        $this->groupScopeMapper = $this->createMock(GroupScopeMapper::class);
        $this->clientAuthorizationService = $this->createMock(ClientAuthorizationService::class);
        $this->clientAuthorizationService->method('isUserAllowedForClient')->willReturnCallback(fn (): bool => $this->clientAuthorizationAllowed);

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
            $this->logger,
            $this->deviceCodeMapper,
            $this->texTargetMapper,
            $this->formUrlencodedParameterParser,
            $this->texSubjectClientMapper,
            null,
            new ScopeCeilingService($this->groupScopeMapper, $this->groupManager, $this->userManager, $this->logger),
            $this->clientAuthorizationService,
            $this->refreshTokenMapper
        );

        // Default configuration
        $this->appConfig->method('getAppValueString')
            ->willReturnCallback(function($key, $default) {
                switch ($key) {
                    case Application::APP_CONFIG_DEFAULT_EXPIRE_TIME:
                        return '900';
                    case Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME:
                        return '900';
                    case Application::APP_CONFIG_REFRESH_REPLAY_GRACE_SECONDS:
                        return $this->refreshReplayGraceSeconds;
                    default:
                        return $default;
                }
            });
    }

    /**
     * RFC 8693 subject_token_type=access_token must only use the access-token
     * lookup path. Authorization-code lookup here would reintroduce the token
     * type confusion that the Token Exchange implementation explicitly avoids.
     */
    private function expectTokenExchangeNeverUsesAuthorizationCodeLookup(): void {
        $this->accessTokenMapper
            ->expects($this->never())
            ->method('getByCode');
    }

    private function setTokenExchangeForm(array $parameters): void {
        $raw = [
            'grant_type' => ['urn:ietf:params:oauth:grant-type:token-exchange'],
        ];
        foreach ($parameters as $name => $value) {
            if ($value === null) {
                continue;
            }
            $raw[$name] = is_array($value) ? array_values($value) : [(string)$value];
        }
        $this->tokenExchangeRawParameters = $raw;
    }

    private function useBasicClient(string $clientId, string $clientSecret): void {
        $this->authorizationHeader = 'Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($clientSecret));
    }

    private function setDeviceGrantForm(string $deviceCode = 'device-code', string $clientId = 'device-client', ?string $clientSecret = null): void {
        $this->tokenExchangeRawParameters = [
            'grant_type' => ['urn:ietf:params:oauth:grant-type:device_code'],
            'device_code' => [$deviceCode],
            'client_id' => [$clientId],
            'client_secret' => $clientSecret === null ? [] : [$clientSecret],
        ];
    }

    private function createTexClient(string $identifier = 'test-client', bool $enabled = true, string $type = 'confidential'): Client {
        $client = new Client($identifier, ['https://test.org'], 'RS256');
        $client->setClientIdentifier($identifier);
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setType($type);
        $client->setTexEnabled($enabled);
        $client->setTexAllowedScopes('openid profile');
        return $client;
    }

    private function createSubjectToken(int $clientId = 2, string $resource = 'https://resource.example/api'): AccessToken {
        $subjectToken = new AccessToken();
        $subjectToken->setId(99);
        $subjectToken->setClientId($clientId);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setCreated(900);
        $subjectToken->setRefreshed(999);
        $subjectToken->setExpiresAt(1899);
        $subjectToken->setAccessToken('old_access_token');
        $subjectToken->setResource($resource);
        return $subjectToken;
    }

    private function createDeviceClient(string $type = 'public'): Client {
        $client = new Client('Device client', [], 'RS256', $type);
        $client->setId(1);
        $client->setClientIdentifier('device-client');
        $client->setSecret('device-secret');
        $client->setAllowedScopes('openid profile email offline_access');
        return $client;
    }

    private function createDeviceAuthorization(string $status = DeviceCode::STATUS_PENDING): DeviceCode {
        $authorization = new DeviceCode();
        $authorization->setId(7);
        $authorization->setClientId(1);
        $authorization->setHashedDeviceCode(hash('sha512', 'device-code'));
        $authorization->setHashedUserCode(hash('sha512', 'ABCD2345'));
        $authorization->setScope('openid profile email offline_access');
        $authorization->setCreatedAt(900);
        $authorization->setExpiresAt(1600);
        $authorization->setIntervalSeconds(5);
        $authorization->setLastPolledAt(0);
        $authorization->setStatus($status);
        $authorization->setUserId($status === DeviceCode::STATUS_APPROVED ? 'alice' : null);
        $authorization->setConsumedAt(0);
        return $authorization;
    }

    public function testDeviceGrantReturnsAuthorizationPending(): void {
        $this->setDeviceGrantForm(clientSecret: 'device-secret');
        $client = $this->createDeviceClient('confidential');
        $authorization = $this->createDeviceAuthorization();
        $this->time->method('getTime')->willReturn(1000);
        $this->clientMapper->method('getByIdentifier')->with('device-client')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->with('device-code')->willReturn($authorization);
        $this->deviceCodeMapper->method('recordPoll')->with($authorization, 1000)->willReturn(true);

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
            client_secret: 'device-secret',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('authorization_pending', $response->getData()['error']);
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
    }

    public function testDeviceGrantReturnsSlowDownWhenClientPollsEarly(): void {
        $this->setDeviceGrantForm();
        $client = $this->createDeviceClient();
        $authorization = $this->createDeviceAuthorization();
        $this->time->method('getTime')->willReturn(1001);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->willReturn($authorization);
        $this->deviceCodeMapper->method('recordPoll')->with($authorization, 1001)->willReturn(false);

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
        );

        $this->assertSame('slow_down', $response->getData()['error']);
    }

    public function testDeviceGrantRejectsDuplicateDeviceCode(): void {
        $this->setDeviceGrantForm();
        $this->tokenExchangeRawParameters['device_code'] = ['device-code', 'other-code'];
        $this->clientMapper->expects($this->never())->method('getByIdentifier');

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'other-code',
            client_id: 'device-client',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_request', $response->getData()['error']);
        $this->assertStringContainsString('device_code', $response->getData()['error_description']);
    }

    public function testDeviceGrantReturnsAccessDenied(): void {
        $this->setDeviceGrantForm();
        $client = $this->createDeviceClient();
        $authorization = $this->createDeviceAuthorization(DeviceCode::STATUS_DENIED);
        $this->time->method('getTime')->willReturn(1000);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->willReturn($authorization);
        $this->deviceCodeMapper->expects($this->never())->method('recordPoll');

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
        );

        $this->assertSame('access_denied', $response->getData()['error']);
    }

    public function testConsumedDeviceCodeCannotBeReplayedEvenWhenPollingTooFast(): void {
        $this->setDeviceGrantForm();
        $client = $this->createDeviceClient();
        $authorization = $this->createDeviceAuthorization(DeviceCode::STATUS_CONSUMED);
        $this->time->method('getTime')->willReturn(1001);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->willReturn($authorization);
        $this->deviceCodeMapper->expects($this->never())->method('recordPoll');

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
        );

        $this->assertSame('invalid_grant', $response->getData()['error']);
    }

    public function testDeviceGrantIssuesTokensForApprovedUser(): void {
        $this->setDeviceGrantForm();
        $client = $this->createDeviceClient();
        $authorization = $this->createDeviceAuthorization(DeviceCode::STATUS_APPROVED);
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $this->time->method('getTime')->willReturn(1000);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->willReturn($authorization);
        // RFC 8628 scopes slow_down to a request that is still pending, so an
        // approved code must not be delayed by the poll throttle.
        $this->deviceCodeMapper->expects($this->never())->method('recordPoll');
        $this->deviceCodeMapper->expects($this->once())->method('markConsumed')->with($authorization, 1000)->willReturn(true);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        $this->clientAuthorizationService->expects($this->once())->method('isUserAllowedForClient')->with($user, $client)->willReturn(true);
        $this->secureRandom->method('generate')->willReturn('refresh-code');
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('idp.example.com');
        $this->jwtGenerator->method('generateAccessToken')->willReturn('access-token');
        $this->jwtGenerator->method('generateIdToken')->willReturn('id-token');
        $this->accessTokenMapper->method('insert')->willReturnCallback(static function (AccessToken $token): AccessToken {
            $token->setId(17);
            return $token;
        });

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
        );
        $data = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('access-token', $data['access_token']);
        $this->assertSame('id-token', $data['id_token']);
        $this->assertSame('refresh-code', $data['refresh_token']);
        $this->assertSame('openid profile email offline_access', $data['scope']);
    }

    /**
     * The device grant must re-check current group membership after approval.
     * If membership changed in the meantime, no token may be issued.
     */
    public function testDeviceGrantRechecksGroupAuthorizationAtTokenRequest(): void {
        $this->setDeviceGrantForm();
        $client = $this->createDeviceClient();
        $authorization = $this->createDeviceAuthorization(DeviceCode::STATUS_APPROVED);
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $this->time->method('getTime')->willReturn(1000);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->willReturn($authorization);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        $this->clientAuthorizationAllowed = false;
        $this->clientAuthorizationService->expects($this->once())
            ->method('isUserAllowedForClient')
            ->with($user, $client);
        $this->deviceCodeMapper->expects($this->never())->method('markConsumed');
        $this->accessTokenMapper->expects($this->never())->method('insert');

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('access_denied', $response->getData()['error']);
        $this->assertSame('The user is no longer allowed to use this client.', $response->getData()['error_description']);
    }

    /**
     * allowed_scopes and the group ceiling are re-applied when the device code
     * is redeemed, not only when it was requested.
     */
    public function testDeviceGrantNarrowsScopeToAllowedScopesAndGroupCeiling(): void {
        $this->setDeviceGrantForm();
        $client = $this->createDeviceClient();
        $client->setAllowedScopes('openid profile offline_access notes.read notes.write');
        $authorization = $this->createDeviceAuthorization(DeviceCode::STATUS_APPROVED);
        $authorization->setScope('openid profile email offline_access notes.read notes.write');
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $ceiling = new GroupScope();
        $ceiling->setGroupId('readers');
        $ceiling->setScopes('offline_access notes.read');
        $this->time->method('getTime')->willReturn(1000);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->deviceCodeMapper->method('findByDeviceCode')->willReturn($authorization);
        $this->deviceCodeMapper->method('markConsumed')->willReturn(true);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->groupManager->method('getUserGroupIds')->willReturn(['readers']);
        $this->groupScopeMapper->method('findByGroupIds')->with(['readers'])->willReturn([$ceiling]);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);
        $this->secureRandom->method('generate')->willReturn('refresh-code');
        $this->jwtGenerator->method('generateAccessToken')->willReturn('access-token');
        $this->jwtGenerator->method('generateIdToken')->willReturn('id-token');
        $this->accessTokenMapper->method('insert')->willReturnCallback(function (AccessToken $accessToken): AccessToken {
            $accessToken->id = 23;
            return $accessToken;
        });

        $response = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:device_code',
            device_code: 'device-code',
            client_id: 'device-client',
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('openid profile offline_access notes.read', $response->getData()['scope']);
    }

    private function createTexTarget(string $resource = 'https://resource.example/api'): TexTargets {
        $target = new TexTargets();
        $target->setClientId(1);
        $target->setResourceUrl($resource);
        return $target;
    }

    private function configureValidExchange(
        Client $client,
        AccessToken $subjectToken,
        string $resource = 'https://resource.example/api',
        ?AccessToken $lockedSubjectToken = null,
        bool $configureSubjectLock = true
    ): void {
        $this->useBasicClient($client->getClientIdentifier(), 'test-secret');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        if ($configureSubjectLock) {
            $this->accessTokenMapper->method('lockTokenExchangeSubject')->willReturn($lockedSubjectToken ?? $subjectToken);
        }
        $this->texSubjectClientMapper->method('isAllowed')->willReturn(true);
        $this->texTargetMapper->method('getByClientId')->willReturn([$this->createTexTarget($resource)]);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->time->method('getTime')->willReturn(1000);
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('example.com');
    }

    // ==================== Token Exchange Tests ====================

    public function testTokenExchangeRequiresFormUrlencodedContentType(): void {
        $this->tokenExchangeContentType = 'application/json';
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('application/x-www-form-urlencoded', $result->getData()['error_description']);
    }

    public function testTokenExchangeRejectsUnreadableRawFormBody(): void {
        $this->tokenExchangeRawParameters = null;
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
    }

    public function testMixedDuplicateGrantTypeCannotBypassTokenExchangeValidation(): void {
        // Simulate PHP/Nextcloud exposing the last grant_type value while the raw
        // body still contains a Token Exchange grant as well.
        $this->tokenExchangeRawParameters = [
            'grant_type' => [
                'urn:ietf:params:oauth:grant-type:token-exchange',
                'authorization_code',
            ],
        ];

        $this->accessTokenMapper->expects($this->never())->method('getByCode');
        $result = $this->controller->getToken('authorization_code', 'some-code');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('grant_type', $result->getData()['error_description']);
    }

    public function testTokenExchangeRequiresSubjectTokenExactlyOnce(): void {
        $this->setTokenExchangeForm([
            'subject_token' => ['a', 'b'],
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('subject_token', $result->getData()['error_description']);
    }

    public function testTokenExchangeRequiresSubjectTokenTypeExactlyOnce(): void {
        $this->setTokenExchangeForm(['subject_token' => 'token']);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('subject_token_type', $result->getData()['error_description']);
    }

    public function testTokenExchangeRejectsDuplicateSingletonParameter(): void {
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'scope' => ['profile', 'openid'],
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('scope', $result->getData()['error_description']);
    }

    public function testTokenExchangeRejectsRepeatedResource(): void {
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => ['https://a.example/', 'https://b.example/'],
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsAudience(): void {
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'audience' => ['a', 'b'],
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsUnsupportedTokenTypesAndActors(): void {
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'access_token',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);

        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'requested_token_type' => 'urn:ietf:params:oauth:token-type:refresh_token',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);

        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'actor_token' => 'actor',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsResourceFragment(): void {
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api#fragment',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsMultipleClientAuthenticationMethods(): void {
        $this->useBasicClient('test-client', 'test-secret');
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeInvalidBasicCredentialsReturn401AndChallenge(): void {
        $client = $this->createTexClient();
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->useBasicClient('test-client', 'wrong-secret');
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
        ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        $this->assertSame('invalid_client', $result->getData()['error']);
        $this->assertSame('Basic realm="token"', $result->getHeaders()['WWW-Authenticate'] ?? null);
    }

    public function testTokenExchangePublicAndDisabledClientsAreUnauthorized(): void {
        $publicClient = $this->createTexClient('public-client', true, 'public');
        $this->clientMapper->method('getByIdentifier')->willReturn($publicClient);
        $this->useBasicClient('public-client', 'test-secret');
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('unauthorized_client', $result->getData()['error']);
    }

    public function testTokenExchangeDisabledClientIsUnauthorized(): void {
        $client = $this->createTexClient('test-client', false);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->useBasicClient('test-client', 'test-secret');
        $this->setTokenExchangeForm([
            'subject_token' => 'token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('unauthorized_client', $result->getData()['error']);
    }

    public function testTokenExchangeInvalidSubjectTokenUsesAccessTokenLookupOnly(): void {
        $client = $this->createTexClient();
        $this->useBasicClient('test-client', 'test-secret');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willThrowException(new AccessTokenNotFoundException());
        $this->setTokenExchangeForm([
            'subject_token' => 'invalid',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeRequiresEffectiveAllowListedResource(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2, '');
        $this->configureValidExchange($client, $subject);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'scope' => 'profile',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsSubjectClientThatIsNotExplicitlyAllowed(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2);
        $this->useBasicClient('test-client', 'test-secret');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subject);
        $this->texSubjectClientMapper->expects($this->once())
            ->method('isAllowed')
            ->with(1, 2)
            ->willReturn(false);
        $this->texTargetMapper->expects($this->never())->method('getByClientId');
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'profile',
        ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('not authorized for Token Exchange', $result->getData()['error_description']);
    }

    public function testTokenExchangeSameClientAlsoRequiresExplicitPolicy(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(1);
        $this->useBasicClient('test-client', 'test-secret');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subject);
        $this->texSubjectClientMapper->expects($this->once())
            ->method('isAllowed')
            ->with(1, 1)
            ->willReturn(false);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
        ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeInheritedResourceIsRevalidated(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2, 'https://not-allowed.example/api');
        $this->useBasicClient('test-client', 'test-secret');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subject);
        $this->texSubjectClientMapper->method('isAllowed')->willReturn(true);
        $this->texTargetMapper->method('getByClientId')->willReturn([$this->createTexTarget('https://allowed.example/api')]);
        $this->time->method('getTime')->willReturn(1000);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'scope' => 'profile',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeCannotEscalateScope(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken();
        $this->configureValidExchange($client, $subject);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'openid profile admin',
        ]);
        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');
        $this->assertSame('invalid_scope', $result->getData()['error']);
    }

    public function testTokenExchangeFailsClosedWithoutAllowedScopes(): void {
        $client = $this->createTexClient();
        $client->setTexAllowedScopes(null);
        $subject = $this->createSubjectToken();
        $this->configureValidExchange($client, $subject);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'profile',
        ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_scope', $result->getData()['error']);
        $this->assertStringContainsString('No Token Exchange scopes', $result->getData()['error_description']);
    }

    public function testTokenExchangeRejectsConcurrentSubjectRevocationDuringChildInsert(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2);
        $subject->setId(99);
        $this->configureValidExchange($client, $subject);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'profile',
        ]);

        $this->secureRandom->method('generate')->willReturn('new-code');
        $foreignKeyViolation = new class('Concurrent subject-token revocation') extends DatabaseException {
            public function getReason(): ?int {
                return DatabaseException::REASON_FOREIGN_KEY_VIOLATION;
            }
        };
        $this->accessTokenMapper->expects($this->once())
            ->method('insert')
            ->willThrowException($foreignKeyViolation);
        $this->jwtGenerator->expects($this->never())->method('generateAccessToken');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('revoked', strtolower($result->getData()['error_description']));
    }

    public function testTokenExchangeFailsClosedWhenSubjectWasRevokedBeforeLock(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2);
        $this->configureValidExchange($client, $subject, 'https://resource.example/api', null, false);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'profile',
        ]);

        $this->accessTokenMapper->expects($this->once())->method('beginTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->once())
            ->method('lockTokenExchangeSubject')
            ->with(99)
            ->willThrowException(new AccessTokenNotFoundException());
        $this->accessTokenMapper->expects($this->once())->method('rollBackTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->never())->method('insert');
        $this->accessTokenMapper->expects($this->never())->method('commitTokenExchangeTransaction');
        $this->jwtGenerator->expects($this->never())->method('generateAccessToken');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('revoked', strtolower($result->getData()['error_description']));
    }

    public function testTokenExchangeFailsClosedWhenLockedSubjectChangedAfterInitialValidation(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2);
        $lockedSubject = $this->createSubjectToken(2);
        $lockedSubject->setScope('openid');
        $this->configureValidExchange($client, $subject, 'https://resource.example/api', $lockedSubject);
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'profile',
        ]);

        $this->accessTokenMapper->expects($this->once())->method('beginTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->once())->method('rollBackTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->never())->method('insert');
        $this->accessTokenMapper->expects($this->never())->method('commitTokenExchangeTransaction');
        $this->jwtGenerator->expects($this->never())->method('generateAccessToken');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertSame('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('changed', strtolower($result->getData()['error_description']));
    }

    public function testTokenExchangeCrossClientSuccessUsesAbsoluteExpiryAndNoIdToken(): void {
        $client = $this->createTexClient();
        $subject = $this->createSubjectToken(2);
        $this->configureValidExchange($client, $subject);
        $this->accessTokenMapper->expects($this->once())->method('beginTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->once())->method('commitTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->never())->method('rollBackTokenExchangeTransaction');
        $this->setTokenExchangeForm([
            'subject_token' => 'old_access_token',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'resource' => 'https://resource.example/api',
            'scope' => 'profile',
        ]);

        $this->secureRandom->method('generate')->willReturn('new-code');
        $this->accessTokenMapper->method('insert')->willReturnCallback(function(AccessToken $token) {
            $this->assertSame(1, $token->getClientId());
            $this->assertSame(1000, $token->getRefreshed());
            $this->assertSame(1899, $token->getExpiresAt());
            $this->assertSame('https://resource.example/api', $token->getResource());
            $token->setId(42);
            return $token;
        });
        $this->jwtGenerator->expects($this->once())
            ->method('generateAccessToken')
            ->with(
                $this->isInstanceOf(AccessToken::class),
                $this->identicalTo($client),
                'https',
                'example.com',
                899,
                false
            )
            ->willReturn('new_access_token');
        $this->jwtGenerator->expects($this->never())->method('generateIdToken');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertSame('new_access_token', $result->getData()['access_token']);
        $this->assertSame(899, $result->getData()['expires_in']);
        $this->assertSame('profile', $result->getData()['scope']);
        $this->assertArrayNotHasKey('id_token', $result->getData());
    }

    // ==================== Authorization Code Flow Tests ====================

    public function testTokenEndpointRejectsRepeatedSingletonParameter(): void {
        $this->tokenExchangeRawParameters = [
            'grant_type' => ['authorization_code'],
            'code' => ['first-code', 'second-code'],
        ];
        $this->clientMapper->expects($this->never())->method('getByIdentifier');

        $response = $this->controller->getToken('authorization_code');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_request', $response->getData()['error']);
        $this->assertStringContainsString('code', $response->getData()['error_description']);
    }

    public function testAuthorizationCodeGrantRequiresDedicatedCodeRecord(): void {
        $client = new Client('public-client', [], 'RS256', 'public');
        $client->setId(1);
        $client->setClientIdentifier('public-client');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->authorizationCodeMapper->method('findByCode')->with('credential')->willReturn(null);
        $this->accessTokenMapper->expects($this->never())->method('getByCode');

        $response = $this->controller->getToken(
            'authorization_code',
            code: 'credential',
            client_id: 'public-client',
            redirect_uri: 'https://client.example/callback',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_grant', $response->getData()['error']);
    }

    public function testAuthorizationCodeCannotBePresentedAsRefreshToken(): void {
        $client = new Client('public-client', [], 'RS256', 'public');
        $client->setId(1);
        $client->setClientIdentifier('public-client');
        $authorizationCode = new AuthorizationCode();
        $authorizationCode->setAccessTokenId(10);
        $authorizationCode->setHashedCode(hash('sha512', 'credential'));
        $authorizationCode->setUsedAt(0);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->refreshTokenMapper->method('findByToken')->with('credential')->willReturn(null);
        $this->authorizationCodeMapper->method('findByCode')->with('credential')->willReturn($authorizationCode);
        $this->accessTokenMapper->expects($this->never())->method('getByCode');

        $response = $this->controller->getToken(
            'refresh_token',
            refresh_token: 'credential',
            client_id: 'public-client',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_grant', $response->getData()['error']);
    }

    public function testNewCredentialWithoutCodeRecordIsNotTreatedAsLegacyRefreshToken(): void {
        $client = new Client('public-client', [], 'RS256', 'public');
        $client->setId(1);
        $client->setClientIdentifier('public-client');
        $accessToken = new AccessToken();
        $accessToken->setId(10);
        $accessToken->setClientId(1);
        $accessToken->setLegacyRefreshToken(false);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->refreshTokenMapper->method('findByToken')->with('credential')->willReturn(null);
        $this->authorizationCodeMapper->method('findByCode')->with('credential')->willReturn(null);
        $this->accessTokenMapper->method('getByCode')->with('credential')->willReturn($accessToken);

        $response = $this->controller->getToken(
            'refresh_token',
            refresh_token: 'credential',
            client_id: 'public-client',
        );

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_grant', $response->getData()['error']);
    }

    public function testAuthorizationCodeGrantRequiresExactBoundRedirectUri(): void {
        $client = new Client('public-client', [], 'RS256', 'public');
        $client->setId(1);
        $client->setClientIdentifier('public-client');
        $authorizationCode = new AuthorizationCode();
        $authorizationCode->setAccessTokenId(10);
        $authorizationCode->setUsedAt(0);
        $authorizationCode->setRedirectUri('https://client.example/callback');
        $accessToken = new AccessToken();
        $accessToken->setId(10);
        $accessToken->setClientId(1);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->authorizationCodeMapper->method('findByCode')->willReturn($authorizationCode);
        $this->accessTokenMapper->method('getById')->with(10)->willReturn($accessToken);

        foreach ([null, 'https://client.example/other'] as $redirectUri) {
            $response = $this->controller->getToken(
                'authorization_code',
                code: 'credential',
                client_id: 'public-client',
                redirect_uri: $redirectUri,
            );

            $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
            $this->assertSame('invalid_grant', $response->getData()['error']);
            $this->assertStringContainsString('Redirect URI', $response->getData()['error_description']);
        }
    }

    public function testAuthorizationCodeGrantRemainsSupported(): void {
        $result = $this->controller->getToken('authorization_code');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testRefreshTokenGrantRemainsSupported(): void {
        $result = $this->controller->getToken('refresh_token');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testAuthorizationCodeInvalidBasicCredentialsReturn401AndChallenge(): void {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
        $client->setSecret('correct-secret');
        $client->setId(1);

        $accessToken = new AccessToken();
        $accessToken->setClientId(1);
        $accessToken->setUserId('user1');
        $accessToken->setCreated(1000);
        $accessToken->setRefreshed(1000);

        $this->authorizationHeader = 'Basic ' . base64_encode(rawurlencode('test-client') . ':' . rawurlencode('wrong-secret'));
        $this->authorizationCodeMapper->method('findByCode')->willReturn(null);
        $this->accessTokenMapper->method('getByCode')->willReturn($accessToken);
        $this->clientMapper->method('getByIdentifier')->willReturn($client);

        $result = $this->controller->getToken('authorization_code', 'authorization-code');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
        $this->assertSame('invalid_client', $result->getData()['error']);
        $this->assertSame('Basic realm="token"', $result->getHeaders()['WWW-Authenticate'] ?? null);
    }

    public function testLegacyPublicClientRejectsBasicAuthentication(): void {
        $client = new Client('public-client', [], 'RS256', 'public');
        $client->setClientIdentifier('public-client');
        $client->setSecret('unused-secret');
        $client->setId(1);

        $this->useBasicClient('public-client', 'unused-secret');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->authorizationCodeMapper->expects($this->never())->method('findByCode');

        $response = $this->controller->getToken('authorization_code', code: 'credential');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('invalid_client', $response->getData()['error']);
    }

    public function testGetTokenWithInvalidGrantType() {
        $result = $this->controller->getToken('invalid_grant_type');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('unsupported_grant_type', $result->getData()['error']);
        $this->assertFalse($result->isThrottled());
    }

    private function prepareEventRefresh(?bool $enabled = true): array {
        $client = new Client('client', [], 'RS256', 'confidential', 'code', 'opaque', 'openid Files:Read');
        $client->setId(1);
        $client->setClientIdentifier('client');
        $client->setSecret('secret');
        $token = new AccessToken();
        $token->setId(10);
        $token->setClientId(1);
        $token->setUserId('alice');
        $token->setScope('openid Files:Read');
        $token->setEventGenerated(true);
        $token->setCreated(900);
        $token->setRefreshed(990);
        $token->setExpiresAt(1890);
        $record = new RefreshToken();
        $record->setId(20);
        $record->setAccessTokenId(10);
        $record->setCreated(990);
        $record->setUsedAt(0);
        $this->tokenExchangeRawParameters = ['grant_type' => ['refresh_token'], 'refresh_token' => ['first'],
            'client_id' => ['client'], 'client_secret' => ['secret']];
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->refreshTokenMapper->method('findByToken')->willReturn($record);
        $this->accessTokenMapper->method('getById')->willReturn($token);
        $this->accessTokenMapper->method('lockTokenExchangeSubject')->willReturn($token);
        $this->time->method('getTime')->willReturn(1000);
        $user = $enabled === null ? null : $this->createMock(IUser::class);
        if ($user !== null) {
            $user->method('isEnabled')->willReturn($enabled);
        }
        $this->userManager->method('get')->willReturn($user);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->secureRandom->method('generate')->willReturn('second');
        return [$token, $record];
    }

    public function testEventRefreshRetainsCaseAndIssuesReplacementWithoutOfflineScope(): void {
        [$token, $record] = $this->prepareEventRefresh();
        $this->userConsentMapper->expects($this->never())->method('findByUserAndClient');
        $this->refreshTokenMapper->expects($this->once())->method('markUsed')->with($record, 1000)->willReturn(true);
        $this->refreshTokenMapper->expects($this->once())->method('createForAccessToken')->with(10, 'second', 1000);
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new-access');
        $this->jwtGenerator->method('generateIdToken')->willReturn('new-id');
        $this->accessTokenMapper->expects($this->once())->method('commitTokenExchangeTransaction');
        $response = $this->controller->getToken();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('openid Files:Read', $response->getData()['scope']);
        $this->assertSame('second', $response->getData()['refresh_token']);
        $this->assertFalse($response->isThrottled());
    }

    public function testJwtFailureDoesNotConsumeRefreshCredential(): void {
        $this->prepareEventRefresh();
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new-access');
        $this->jwtGenerator->method('generateIdToken')->willThrowException(new JwtCreationErrorException('test failure'));
        $this->refreshTokenMapper->expects($this->never())->method('markUsed');
        $this->accessTokenMapper->expects($this->never())->method('beginTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->never())->method('update');
        $this->assertSame(500, $this->controller->getToken()->getStatus());
    }

    public function testFailedRotationRollsBackAllDatabaseChanges(): void {
        $this->prepareEventRefresh();
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new-access');
        $this->jwtGenerator->method('generateIdToken')->willReturn('new-id');
        $this->refreshTokenMapper->method('markUsed')->willReturn(true);
        $this->refreshTokenMapper->method('createForAccessToken')->willThrowException(new \RuntimeException('test persistence failure'));
        $this->accessTokenMapper->expects($this->once())->method('rollBackTokenExchangeTransaction');
        $this->accessTokenMapper->expects($this->never())->method('commitTokenExchangeTransaction');
        $this->assertSame(500, $this->controller->getToken()->getStatus());
    }

    private function prepareRevocation(string $token = 'credential'): Client {
        $client = new Client('public', [], 'RS256', 'public');
        $client->setId(1);
        $client->setClientIdentifier('public');
        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->tokenExchangeRawParameters = ['client_id' => ['public'], 'token' => [$token]];
        return $client;
    }

    public function testRevocationDeletesOwnedAccessTokenFamily(): void {
        $this->prepareRevocation();
        $token = new AccessToken();
        $token->setId(10);
        $token->setClientId(1);
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($token);
        $this->accessTokenMapper->method('lockTokenExchangeSubject')->willReturn($token);
        $this->accessTokenMapper->expects($this->once())->method('delete')->with($token);
        $this->accessTokenMapper->expects($this->once())->method('commitTokenExchangeTransaction');
        $this->assertSame(200, $this->controller->revokeToken()->getStatus());
    }

    public function testRevocationFindsRefreshTokenEvenWithWrongHint(): void {
        $this->prepareRevocation();
        $this->tokenExchangeRawParameters['token_type_hint'] = ['access_token'];
        $this->accessTokenMapper->method('getByAccessToken')->willThrowException(new AccessTokenNotFoundException());
        $record = new RefreshToken();
        $record->setAccessTokenId(10);
        $token = new AccessToken();
        $token->setId(10);
        $token->setClientId(1);
        $this->refreshTokenMapper->method('findByToken')->willReturn($record);
        $this->accessTokenMapper->method('getById')->willReturn($token);
        $this->accessTokenMapper->method('lockTokenExchangeSubject')->willReturn($token);
        $this->accessTokenMapper->expects($this->once())->method('delete')->with($token);
        $this->assertSame(200, $this->controller->revokeToken()->getStatus());
    }

    public function testUnknownTokenRevocationReturns200(): void {
        $this->prepareRevocation();
        $this->accessTokenMapper->method('getByAccessToken')->willThrowException(new AccessTokenNotFoundException());
        $this->accessTokenMapper->method('getByCode')->willThrowException(new AccessTokenNotFoundException());
        $this->accessTokenMapper->expects($this->never())->method('delete');
        $this->assertSame(200, $this->controller->revokeToken()->getStatus());
    }

    public function testRevocationCannotDeleteAnotherClientsToken(): void {
        $this->prepareRevocation();
        $token = new AccessToken();
        $token->setClientId(2);
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($token);
        $this->accessTokenMapper->expects($this->never())->method('delete');
        $response = $this->controller->revokeToken();
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_grant', $response->getData()['error']);
        $this->assertFalse($response->isThrottled());
    }

    public function testRevocationRejectsDuplicateTokenWithoutThrottling(): void {
        $this->tokenExchangeRawParameters = ['token' => ['a', 'b']];
        $this->accessTokenMapper->expects($this->never())->method('getByAccessToken');
        $response = $this->controller->revokeToken();
        $this->assertSame(400, $response->getStatus());
        $this->assertFalse($response->isThrottled());
    }

    public function testTokenEndpointAuthenticationFailureSignalsThrottle(): void {
        $response = $this->controller->getToken('refresh_token');
        $this->assertTrue($response->isThrottled());
    }
    public function testRefreshReplayWithinGraceDoesNotRevokeFamily(): void {
        [, $record] = $this->prepareEventRefresh();
        $record->setUsedAt(998);
        $this->accessTokenMapper->expects($this->never())->method('delete');
        $this->refreshTokenMapper->expects($this->never())->method('markUsed');
        $response = $this->controller->getToken();
        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_grant', $response->getData()['error']);
        $this->assertArrayNotHasKey('access_token', $response->getData());
        $this->assertFalse($response->isThrottled());
    }

    public function testRefreshReplayOutsideGraceRevokesFamily(): void {
        [$token, $record] = $this->prepareEventRefresh();
        $record->setUsedAt(994);
        $this->accessTokenMapper->expects($this->once())->method('delete')->with($token);
        $this->assertSame(400, $this->controller->getToken()->getStatus());
    }

    public function testForeignRefreshReplayCannotRevokeFamily(): void {
        [$token, $record] = $this->prepareEventRefresh();
        $token->setClientId(2);
        $record->setUsedAt(900);
        $this->accessTokenMapper->expects($this->never())->method('delete');
        $this->assertSame(400, $this->controller->getToken()->getStatus());
    }

    public function testConcurrentRefreshLoserKeepsCommittedFamily(): void {
        [, $record] = $this->prepareEventRefresh();
        $this->jwtGenerator->method('generateAccessToken')->willReturn('discarded-access');
        $this->jwtGenerator->method('generateIdToken')->willReturn('discarded-id');
        $this->refreshTokenMapper->expects($this->once())->method('markUsed')
            ->willReturnCallback(static function () use ($record): bool {
                // Simulate the other request committing while this request waits.
                $record->setUsedAt(1000);
                return false;
            });
        $this->accessTokenMapper->expects($this->never())->method('delete');
        $this->accessTokenMapper->expects($this->never())->method('update');
        $this->refreshTokenMapper->expects($this->never())->method('createForAccessToken');
        $this->accessTokenMapper->expects($this->once())->method('commitTokenExchangeTransaction');
        $response = $this->controller->getToken();
        $this->assertSame('invalid_grant', $response->getData()['error']);
        $this->assertArrayNotHasKey('refresh_token', $response->getData());
    }

    public function testRefreshRejectsDisabledUserBeforeIssuance(): void {
        [$token] = $this->prepareEventRefresh(false);
        $this->accessTokenMapper->expects($this->once())->method('delete')->with($token);
        $this->jwtGenerator->expects($this->never())->method('generateAccessToken');
        $response = $this->controller->getToken();
        $this->assertSame('invalid_grant', $response->getData()['error']);
    }

    public function testRefreshRejectsDeletedUserBeforeIssuance(): void {
        [$token] = $this->prepareEventRefresh(null);
        $this->accessTokenMapper->expects($this->once())->method('delete')->with($token);
        $this->jwtGenerator->expects($this->never())->method('generateAccessToken');
        $this->assertSame(400, $this->controller->getToken()->getStatus());
    }

    public function testStrictRefreshModeRevokesImmediateReplay(): void {
        [$token, $record] = $this->prepareEventRefresh();
        $record->setUsedAt(1000);
        $this->refreshReplayGraceSeconds = '0';
        $this->accessTokenMapper->expects($this->once())->method('delete')->with($token);
        $this->assertSame(400, $this->controller->getToken()->getStatus());
    }

}
