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
    /** @var \PHPUnit\Framework\MockObject\MockObject|FormUrlencodedParameterParser */
    protected $formUrlencodedParameterParser;

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
            $this->texTargetMapper,
            $this->formUrlencodedParameterParser
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

    // ==================== Token Exchange Tests ====================

    public function testTokenExchangeMissingSubjectTokenType() {
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return $key === 'subject_token' ? 'some_token' : null;
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('subject_token_type', $result->getData()['error_description']);
    }

    public function testTokenExchangeRejectsShortSubjectTokenType() {
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'access_token',
                default => null,
            };
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsUnsupportedRequestedTokenType() {
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'requested_token_type' => 'urn:ietf:params:oauth:token-type:refresh_token',
                default => null,
            };
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsActorToken() {
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'actor_token' => 'actor-token',
                'actor_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                default => null,
            };
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsUnsupportedAudience() {
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'audience' => 'backend-service',
                default => null,
            };
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsRepeatedResourceParametersFromRawFormBody() {
        $this->request->method('getHeader')->willReturnCallback(function($key) {
            return $key === 'Content-Type' ? 'application/x-www-form-urlencoded' : '';
        });
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                // Simulate the value PHP/Nextcloud might expose after collapsing
                // repeated form fields. The raw body parser must remain authoritative.
                'resource' => 'https://api-b.example/',
                default => null,
            };
        });
        $this->formUrlencodedParameterParser->expects($this->once())
            ->method('readSelectedParameters')
            ->with(['resource', 'audience'])
            ->willReturn([
                'resource' => ['https://api-a.example/', 'https://api-b.example/'],
                'audience' => [],
            ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
        $this->assertStringContainsString('Multiple resource', $result->getData()['error_description']);
    }

    public function testTokenExchangeRejectsRepeatedIdenticalResourceParameters() {
        $this->request->method('getHeader')->willReturnCallback(function($key) {
            return $key === 'Content-Type' ? 'application/x-www-form-urlencoded; charset=UTF-8' : '';
        });
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'resource' => 'https://api.example/',
                default => null,
            };
        });
        $this->formUrlencodedParameterParser->method('readSelectedParameters')
            ->willReturn([
                'resource' => ['https://api.example/', 'https://api.example/'],
                'audience' => [],
            ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsRepeatedAudienceParametersFromRawFormBody() {
        $this->request->method('getHeader')->willReturnCallback(function($key) {
            return $key === 'Content-Type' ? 'application/x-www-form-urlencoded' : '';
        });
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'audience' => 'backend-b',
                default => null,
            };
        });
        $this->formUrlencodedParameterParser->method('readSelectedParameters')
            ->willReturn([
                'resource' => [],
                'audience' => ['backend-a', 'backend-b'],
            ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeRawResourceIsAuthoritativeOverCollapsedRequestParameter() {
        $this->request->method('getHeader')->willReturnCallback(function($key) {
            return $key === 'Content-Type' ? 'application/x-www-form-urlencoded' : '';
        });
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'resource' => 'https://collapsed.example/',
                default => null,
            };
        });
        $this->formUrlencodedParameterParser->method('readSelectedParameters')
            ->willReturn([
                'resource' => ['https://raw.example/api#fragment'],
                'audience' => [],
            ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
        $this->assertStringContainsString('absolute URI without a fragment', $result->getData()['error_description']);
    }

    public function testTokenExchangeFormBodyDoesNotFallBackToMergedTargetParameter() {
        $this->request->method('getHeader')->willReturnCallback(function($key) {
            return $key === 'Content-Type' ? 'application/x-www-form-urlencoded' : '';
        });
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                // A target exposed only by IRequest's merged parameter map (for
                // example from the query string) must not become a Token Exchange
                // target when it was not present in the form entity body.
                'resource' => 'https://merged-only.example/api#fragment',
                default => null,
            };
        });
        $this->formUrlencodedParameterParser->method('readSelectedParameters')
            ->willReturn([
                'resource' => [],
                'audience' => [],
            ]);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_client', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsUnreadableRawFormBody() {
        $this->request->method('getHeader')->willReturnCallback(function($key) {
            return $key === 'Content-Type' ? 'application/x-www-form-urlencoded' : '';
        });
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                default => null,
            };
        });
        $this->formUrlencodedParameterParser->method('readSelectedParameters')
            ->willReturn(null);

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeRejectsResourceWithFragment() {
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'some_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'resource' => 'https://resource.example/api#fragment',
                default => null,
            };
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeMissingSubjectToken() {
        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return null;
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
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
                        return 'urn:ietf:params:oauth:token-type:access_token';
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
                        return 'urn:ietf:params:oauth:token-type:access_token';
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
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setTexEnabled(true);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'some_token';
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
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
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setTexEnabled(false); // TEX not enabled

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'some_token';
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
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

    public function testTokenExchangeIsNotAllowedForPublicClient() {
        $client = new Client('public-client', ['https://test.org'], 'RS256', 'public');
        $client->setClientIdentifier('public-client');
        $client->setTexEnabled(true);

        $this->request
            ->method('getParam')
            ->willReturnMap([
                ['subject_token', null, 'some-token'],
                ['subject_token_type', null, 'urn:ietf:params:oauth:token-type:access_token'],
            ]);

        $this->clientMapper
            ->method('getByIdentifier')
            ->willReturn($client);

        $result = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            null,
            null,
            'public-client'
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('not allowed for public', $result->getData()['error_description']);
    }

    public function testTokenExchangeInvalidSubjectToken() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
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
                        return 'urn:ietf:params:oauth:token-type:access_token';
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

        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();

        $this->accessTokenMapper
            ->method('getByAccessToken')
            ->willThrowException(new AccessTokenNotFoundException('Token not found'));

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
    }

    public function testTokenExchangeCrossClientSubjectTokenAllowed() {
        $client = new Client('requesting-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('requesting-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);
        $client->setTexAllowedScopes('profile');

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(2); // Issued to a different client
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setAccessToken('subject_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $target = new TexTargets();
        $target->setResourceUrl('https://resource-server.example/');
        $target->setId(1);
        $target->setUsedAt(0);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);
        $this->texTargetMapper
            ->expects($this->once())
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$target]);

        $this->time->method('getTime')->willReturn(1000);
        $this->secureRandom->method('generate')->willReturn('new_refresh_token');
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new_access_token');
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('example.com');
        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'subject_token';
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
                    case 'resource':
                        return 'https://resource-server.example/';
                    case 'scope':
                        return 'profile';
                    default:
                        return null;
                }
            });

        $insertedToken = null;
        $this->accessTokenMapper->method('insert')->willReturnCallback(function (AccessToken $token) use (&$insertedToken) {
            $token->setId(42);
            $insertedToken = $token;
            return $token;
        });

        $result = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            null,
            null,
            'requesting-client',
            'test-secret'
        );

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertEquals('new_access_token', $result->getData()['access_token']);
        $this->assertEquals('profile', $result->getData()['scope']);
        $this->assertNotNull($insertedToken);
        $this->assertEquals(1, $insertedToken->getClientId(), 'Output token must belong to requesting client');
        $this->assertEquals('user1', $insertedToken->getUserId());
        $this->assertEquals('https://resource-server.example/', $insertedToken->getResource());
    }

    public function testTokenExchangeCrossClientInheritedResourceMustBeAllowedForRequestingClient() {
        $client = new Client('requesting-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('requesting-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(2); // Issued to a different client
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setResource('https://subject-resource.example/');
        $subjectToken->setAccessToken('subject_token');

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->texTargetMapper
            ->expects($this->once())
            ->method('getByClientId')
            ->with(1)
            ->willReturn([]); // Requesting client is not allowed to target inherited resource
        $this->time->method('getTime')->willReturn(1000);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'subject_token';
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
                    case 'resource':
                    case 'scope':
                        return null;
                    default:
                        return null;
                }
            });

        $result = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            null,
            null,
            'requesting-client',
            'test-secret'
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_target', $result->getData()['error']);
    }

    public function testTokenExchangeInheritedResourceIsRevalidatedAndAcceptedWhenWhitelisted() {
        $client = new Client('requesting-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('requesting-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(2);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setResource('https://allowed-resource.example/');
        $subjectToken->setAccessToken('subject_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]);

        $target = new TexTargets();
        $target->setResourceUrl('https://allowed-resource.example/');
        $target->setId(1);
        $target->setUsedAt(0);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->texTargetMapper->expects($this->once())
            ->method('getByClientId')
            ->with(1)
            ->willReturn([$target]);
        $this->texTargetMapper->expects($this->once())
            ->method('markUsed')
            ->with($target, 1000)
            ->willReturn(true);

        $this->time->method('getTime')->willReturn(1000);
        $this->secureRandom->method('generate')->willReturn('new_code');
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new_access_token');
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('example.com');
        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'subject_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'resource' => null,
                'scope' => null,
                default => null,
            };
        });

        $this->accessTokenMapper->method('insert')->willReturnCallback(function (AccessToken $token) {
            $this->assertSame('https://allowed-resource.example/', $token->getResource());
            $token->setId(44);
            return $token;
        });

        $result = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            null,
            null,
            'requesting-client',
            'test-secret'
        );

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertEquals('new_access_token', $result->getData()['access_token']);
        $this->assertEquals(899, $result->getData()['expires_in']);
    }

    public function testTokenExchangeSuccess() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(999); // Set to just before current time
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]); // No group restrictions
        $this->texTargetMapper->method('getByClientId')->willReturn([]);

        $this->secureRandom->method('generate')->willReturn('new_refresh_token');
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
            ->willReturn('new_jwt_token');
        $this->jwtGenerator->expects($this->never())
            ->method('generateIdToken');

        // Mock time
        $this->time->method('getTime')->willReturn(1000);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'old_jwt_token';
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
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

        $this->accessTokenMapper->method('insert')->willReturnCallback(function (AccessToken $token) {
            $this->assertSame(1000, $token->getCreated());
            // With 899 seconds remaining on the subject token and a configured
            // lifetime of 900 seconds, the expiry anchor is shifted to 999 so
            // existing refreshed + lifetime validation expires both at 1899.
            $this->assertSame(999, $token->getRefreshed());
            $token->setId(42);
            return $token;
        });

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertArrayHasKey('access_token', $result->getData());
        $this->assertArrayHasKey('issued_token_type', $result->getData());
        $this->assertArrayHasKey('token_type', $result->getData());
        $this->assertArrayHasKey('scope', $result->getData());
        $this->assertEquals('urn:ietf:params:oauth:token-type:access_token', $result->getData()['issued_token_type']);
        $this->assertEquals('Bearer', $result->getData()['token_type']);
        $this->assertEquals(899, $result->getData()['expires_in']);
        $this->assertEquals('openid profile', $result->getData()['scope']);
        $this->assertArrayNotHasKey('id_token', $result->getData());
    }

    public function testTokenExchangeWithResource() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->groupMapper->method('getGroupsByClientId')->willReturn([]); // No group restrictions

        // Create actual TEX target
        $texTarget = new TexTargets();
        $texTarget->setResourceUrl('https://resource-server.example/');
        $texTarget->setId(1);
        $texTarget->setUsedAt(0);

        $this->texTargetMapper->method('getByClientId')->willReturn([$texTarget]);

        // Mock new token generation. Token Exchange never issues an ID token.
        $this->secureRandom->method('generate')->willReturn('new_refresh_token');
        $this->jwtGenerator->method('generateAccessToken')->willReturn('new_jwt_token');
        $this->jwtGenerator->expects($this->never())->method('generateIdToken');

        // Mock time
        $this->time->method('getTime')->willReturn(1000);

        $this->request
            ->method('getParam')
            ->willReturnCallback(function($key) {
                switch ($key) {
                    case 'subject_token':
                        return 'old_jwt_token';
                    case 'subject_token_type':
                        return 'urn:ietf:params:oauth:token-type:access_token';
                    case 'resource':
                        return 'https://resource-server.example/';
                    case 'scope':
                        return 'profile'; // No openid, so no id_token
                    default:
                        return null;
                }
            });

        $this->accessTokenMapper->method('insert')->willReturnCallback(function (AccessToken $token) {
            $this->assertSame('https://resource-server.example/', $token->getResource());
            $token->setId(43);
            return $token;
        });
        $this->texTargetMapper->method('markUsed')->willReturn(true);

        // Mock server protocol and host
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('example.com');

        $result = $this->controller->getToken('urn:ietf:params:oauth:grant-type:token-exchange', null, null, 'test-client', 'test-secret');

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $this->assertArrayHasKey('access_token', $result->getData());
    }

    public function testTokenExchangeWithInvalidResource() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
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
                        return 'urn:ietf:params:oauth:token-type:access_token';
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

    public function testTokenExchangeCannotEscalateScopeWithoutTexScopeLimit() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);
        $client->setTexAllowedScopes(null);

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setAccessToken('old_token');

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
        $this->accessTokenMapper->method('getByAccessToken')->willReturn($subjectToken);
        $this->time->method('getTime')->willReturn(1000);

        $this->request->method('getParam')->willReturnCallback(function($key) {
            return match ($key) {
                'subject_token' => 'old_token',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'scope' => 'openid profile admin',
                default => null,
            };
        });

        $result = $this->controller->getToken(
            'urn:ietf:params:oauth:grant-type:token-exchange',
            null,
            null,
            'test-client',
            'test-secret'
        );

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_scope', $result->getData()['error']);
        $this->assertStringContainsString('subject token scope', $result->getData()['error_description']);
    }

    public function testTokenExchangeWithInvalidScope() {
        $client = new Client('test-client', ['https://test.org'], 'RS256');
        $client->setClientIdentifier('test-client');
        $client->setSecret('test-secret');
        $client->setId(1);
        $client->setTexEnabled(true);
        $client->setTexAllowedScopes('openid profile'); // Only openid and profile allowed

        $subjectToken = new AccessToken();
        $subjectToken->setClientId(1);
        $subjectToken->setUserId('user1');
        $subjectToken->setScope('openid profile');
        $subjectToken->setRefreshed(999);
        $subjectToken->setAccessToken('old_jwt_token');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userManager->method('get')->willReturn($user);

        $group1 = $this->createMock(IGroup::class);
        $group1->method('getGID')->willReturn('group1');
        $this->groupManager->method('getUserGroups')->willReturn([$group1]);

        $this->clientMapper->method('getByIdentifier')->willReturn($client);
        $this->expectTokenExchangeNeverUsesAuthorizationCodeLookup();
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
                        return 'urn:ietf:params:oauth:token-type:access_token';
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

    public function testAuthorizationCodeGrantRemainsSupported(): void {
        $result = $this->controller->getToken('authorization_code');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('code', $result->getData()['error_description']);
    }

    public function testRefreshTokenGrantRemainsSupported(): void {
        $result = $this->controller->getToken('refresh_token');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('invalid_request', $result->getData()['error']);
        $this->assertStringContainsString('refresh_token', $result->getData()['error_description']);
    }

    public function testGetTokenWithInvalidGrantType() {
        $result = $this->controller->getToken('invalid_grant_type');

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $this->assertEquals('unsupported_grant_type', $result->getData()['error']);
    }
}
