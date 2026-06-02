<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use OC\AppFramework\Utility\TimeFactory;
use UnexpectedValueException;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OC\Authentication\Token\IProvider as TokenProvider;
use OC\Group\Group;
use OC\Security\SecureRandom;
use OC\User\User;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\DAV\CardDAV\Converter;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCA\OIDCIdentityProvider\Exceptions\JwtCreationErrorException;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCA\OIDCIdentityProvider\Service\CredentialService;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Group\ISubAdmin;
use OCP\Server;
use OCP\IURLGenerator;
use OCP\Security\ICredentialsManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountManager;
use Psr\Log\LoggerInterface;
use OCP\EventDispatcher\IEventDispatcher;

class JwtGeneratorTest extends TestCase {
        /** @var JwtGenerator */
        protected $generator;
        /** @var \PHPUnit\Framework\MockObject\MockObject|ICrypto */
        private $crypto;
        /** @var \PHPUnit\Framework\MockObject\MockObject|TokenProvider */
        private $tokenProvider;
        /** @var \PHPUnit\Framework\MockObject\MockObject|ISecureRandom */
        private $secureRandom;
        /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
        private $time;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IUserManager */
        private $userManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IGroupManager */
        private $groupManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|ISubAdmin */
        private $subAdminManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IAccountManager */
        private $accountManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|ICredentialsManager */
        private $credentialsManager;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
        private $urlGenerator;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
        private $appConfig;
        /** @var \PHPUnit\Framework\MockObject\MockObject|IConfig */
        private $config;
        /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper  */
        private $customClaimMapper;
        /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimService */
        private $customClaimService;
        /** @var CredentialService */
        private $credentialService;
        /** @var LoggerInterface */
        private $logger;
        /** @var IEventDispatcher */
        private $eventDispatcher;
        /** @var IDBConnection */
        private $db;
        /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriMapper  */
        private $redirectUriMapper;
        /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper  */
        private $clientMapper;

    public function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $this->tokenProvider = Server::get(TokenProvider::class);
        $this->secureRandom = Server::get(SecureRandom::class);
        $this->time = Server::get(TimeFactory::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->subAdminManager = $this->createMock(ISubAdmin::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->config = $this->createMock(IConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        // Create redirectUriMapper mock and call constructor with arguments
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $reflection = new \ReflectionClass(RedirectUriMapper::class);
        $constructor = $reflection->getConstructor();
        $constructor->invoke($this->redirectUriMapper, $this->db, $this->time, $this->appConfig);

        // avoid the circular constructor dependency by creating one mock without running its constructor
        $this->clientMapper = $this->createMock(ClientMapper::class);

        // now construct the customClaimMapper with the clientMapper mock
        // Create customClaimMapper mock and call constructor with arguments
        $this->customClaimMapper = $this->createMock(CustomClaimMapper::class);
        $reflection2 = new \ReflectionClass(CustomClaimMapper::class);
        $constructor2 = $reflection2->getConstructor();
        $constructor2->invoke($this->customClaimMapper, $this->db, $this->logger);
        $this->customClaimService = new CustomClaimService(
            $this->customClaimMapper,
            $this->userManager,
            $this->groupManager,
            $this->subAdminManager,
            $this->accountManager,
            $this->logger
        );
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->credentialService = new CredentialService(
            $this->credentialsManager,
            $this->appConfig,
            $this->logger
        );
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->generator = new JwtGenerator(
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
            $this->credentialService,
            $this->logger
        );
    }

    public function testGenerateIdToken() {
        // Prepare key material for test
        $config = array(
            "digest_alg" => 'sha512',
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA
        );
        $keyPair = openssl_pkey_new($config);
        $privateKey = null;
        openssl_pkey_export($keyPair, $privateKey);
        $keyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $keyDetails['key'];
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
        $kid = $this->guidv4();

        // Mock necessary methods
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnCallback(function($key, $default = '') use ($privateKey, $publicKey, $modulus, $exponent, $kid) {
                $map = [
                    'dynamic_client_registration' => 'true',
                    'expire_time' => '3600',
                    'integrate_avatar' => 'id_token',
                    'overwrite_email_verified' => 'true',
                    'private_key' => $privateKey,
                    'public_key' => $publicKey,
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                    'kid' => $kid,
                ];
                return $map[$key] ?? $default;
            });
        $this->credentialsManager
            ->method('retrieve')
            ->willReturn($privateKey);

        // Create a mock user
        $testEmail = 'testuser@example.com';
        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getEMailAddress')->willReturn($testEmail);
        $mockUser->method('getQuota')->willReturn('1000000');
        $this->userManager
            ->method('get')
            ->willReturn($mockUser);

        $this->groupManager
            ->method('getUserGroups')
            ->willReturn([]);

        // Create mock account and account properties
        $mockAccount = $this->createMock(IAccount::class);
        $mockAccountProperty = $this->createMock(IAccountProperty::class);
        $mockAccountProperty->method('getValue')->willReturn('');

        // Special handling for email property
        $mockEmailProperty = $this->createMock(IAccountProperty::class);
        $mockEmailProperty->method('getValue')->willReturn($testEmail);

        $mockAccount
            ->method('getProperty')
            ->willReturnCallback(function($prop) use ($mockEmailProperty, $mockAccountProperty) {
                if ($prop === IAccountManager::PROPERTY_EMAIL) {
                    return $mockEmailProperty;
                }
                return $mockAccountProperty;
            });
        $this->accountManager
            ->method('getAccount')
            ->willReturn($mockAccount);

        $user_id = '34';
        $protocol = 'https';
        $issuer = 'issuer.url';
        $scope = 'openid profile email roles';

        $client = new Client('TEST', 'http://redirect.uri/callback', 'RS256', 'confidential', 'code', 'jwt', false);
        $client->setClientIdentifier('TESTCLIENTIDENTIFIER');
        $client->setId(1);

        $code = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($user_id);
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr($scope, 0, 128));
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('12345678');

        // Execute test
        $result = $this->generator->generateIdToken(
            $accessToken,
            $client,
            $protocol,
            $issuer,
            false
        );

        // Decode received JWT
        $oidcKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'key_ops' => [ 'verify' ],
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValueString('kid'),
            'n' => $this->appConfig->getAppValueString('public_key_n'),
            'e' => $this->appConfig->getAppValueString('public_key_e'),
        ];

        $jwks = [
            'keys' => [
                $oidcKey,
            ],
        ];

        $decodedStdClass = JWT::decode($result, JWK::parseKeySet($jwks));
        $decodedJwt = (array) $decodedStdClass;

        // Test if decoded JWT contains necessary values
        $this->assertEquals($protocol . "://" . $issuer, $decodedJwt['iss']);
        $this->assertEquals($user_id, $decodedJwt['sub']);
        $this->assertEquals($client->getClientIdentifier(), $decodedJwt['aud']);
        $this->assertEquals($scope, $decodedJwt['scope']);
        $this->assertEquals($client->getClientIdentifier(), $decodedJwt['azp']);
        $this->assertArrayHasKey('email', $decodedJwt);
        $this->assertEquals('testuser@example.com', $decodedJwt['email']);
        $this->assertArrayHasKey('nonce', $decodedJwt);
        $this->assertEquals('12345678', $decodedJwt['nonce']);
    }

    public function testGenerateOpaqueAccessToken() {
        $client = new Client('TEST', 'http://redirect.uri/callback', 'RS256', 'confidential', 'code', 'opaque', false);
        $code = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId('34');
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr('openid profile email roles', 0, 128));
        $accessToken->setResource(substr('http://test.rs.url/', 0, 2000));
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('12345678');

        $result = $this->generator->generateAccessToken(
            $accessToken,
            $client,
            'https',
            'https://issuer.url'
        );

        $this->assertEquals(72, strlen($result));
    }

    public function testGenerateJwtAccessToken() {
        // Prepare key material for test
        $config = array(
            "digest_alg" => 'sha512',
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA
        );
        $keyPair = openssl_pkey_new($config);
        $privateKey = null;
        openssl_pkey_export($keyPair, $privateKey);
        $keyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $keyDetails['key'];
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
        $kid = $this->guidv4();

        // Mock necessary methods
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnCallback(function($key, $default = '') use ($privateKey, $publicKey, $modulus, $exponent, $kid) {
                $map = [
                    'dynamic_client_registration' => 'true',
                    'expire_time' => '3600',
                    'integrate_avatar' => 'id_token',
                    'overwrite_email_verified' => 'true',
                    'private_key' => $privateKey,
                    'public_key' => $publicKey,
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                    'kid' => $kid,
                ];
                return $map[$key] ?? $default;
            });
        $this->credentialsManager
            ->method('retrieve')
            ->willReturn($privateKey);

        // Create a mock user
        $testEmail = 'testuser@example.com';
        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getEMailAddress')->willReturn($testEmail);
        $this->userManager
            ->method('get')
            ->willReturn($mockUser);

        $this->groupManager
            ->method('getUserGroups')
            ->willReturn([]);

        // Create mock account and account properties
        $mockAccount = $this->createMock(IAccount::class);
        $mockAccountProperty = $this->createMock(IAccountProperty::class);
        $mockAccountProperty->method('getValue')->willReturn('');

        // Special handling for email property
        $mockEmailProperty = $this->createMock(IAccountProperty::class);
        $mockEmailProperty->method('getValue')->willReturn($testEmail);

        $mockAccount
            ->method('getProperty')
            ->willReturnCallback(function($prop) use ($mockEmailProperty, $mockAccountProperty) {
                if ($prop === IAccountManager::PROPERTY_EMAIL) {
                    return $mockEmailProperty;
                }
                return $mockAccountProperty;
            });
        $this->accountManager
            ->method('getAccount')
            ->willReturn($mockAccount);

        $user_id = '34';
        $protocol = 'https';
        $issuer = 'issuer.url';
        $resource = 'http://test.rs.url/';
        $scope = 'openid profile email roles';

        $client = new Client('TEST', 'http://redirect.uri/callback', 'RS256', 'confidential', 'code', 'jwt', false);
        $client->setClientIdentifier('TESTCLIENTIDENTIFIER');

        $code = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($user_id);
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr($scope, 0, 128));
        $accessToken->setResource(substr($resource, 0, 2000));
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('12345678');

        // Execute test
        $result = $this->generator->generateAccessToken(
            $accessToken,
            $client,
            $protocol,
            $issuer
        );

        // Decode received JWT
        $oidcKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'key_ops' => [ 'verify' ],
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValueString('kid'),
            'n' => $this->appConfig->getAppValueString('public_key_n'),
            'e' => $this->appConfig->getAppValueString('public_key_e'),
        ];

        $jwks = [
            'keys' => [
                $oidcKey,
            ],
        ];

        $decodedStdClass = JWT::decode($result, JWK::parseKeySet($jwks));
        $decodedJwt = (array) $decodedStdClass;

        // Test if decoded JWT contains necessary values
        $this->assertEquals($protocol . "://" . $issuer, $decodedJwt['iss']);
        $this->assertEquals($user_id, $decodedJwt['sub']);
        $this->assertEquals($resource, $decodedJwt['aud']);
        $this->assertEquals($scope, $decodedJwt['scope']);
        $this->assertEquals($client->getClientIdentifier(), $decodedJwt['client_id']);
        $this->assertArrayHasKey('email', $decodedJwt);
        $this->assertEquals('testuser@example.com', $decodedJwt['email']);
    }

    public function testGenerateJwtAccessTokenException() {
        $this->expectException(JwtCreationErrorException::class);

        // Prepare key material for test
        $config = array(
            "digest_alg" => 'sha512',
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA
        );
        $keyPair = openssl_pkey_new($config);
        $privateKey = null;
        openssl_pkey_export($keyPair, $privateKey);
        $keyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $keyDetails['key'];
        $modulus = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['n']));
        $exponent = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($keyDetails['rsa']['e']));
        $kid = $this->guidv4();

        // Mock necessary methods
        $this->appConfig
            ->method('getAppValueString')
            ->willReturnCallback(function($key, $default = '') use ($privateKey, $publicKey, $modulus, $exponent, $kid) {
                $map = [
                    'dynamic_client_registration' => 'true',
                    'expire_time' => '3600',
                    'integrate_avatar' => 'id_token',
                    'overwrite_email_verified' => 'true',
                    'private_key' => $privateKey,
                    'public_key' => $publicKey,
                    'public_key_n' => $modulus,
                    'public_key_e' => $exponent,
                    'kid' => $kid,
                ];
                return $map[$key] ?? $default;
            });
        $this->credentialsManager
            ->method('retrieve')
            ->willReturn($privateKey);
        $mockUser = $this->createMock(IUser::class);
        $this->userManager
            ->method('get')
            ->willReturn($mockUser);
        $mockAccount = $this->createMock(IAccount::class);
        $mockAccountProperty = $this->createMock(IAccountProperty::class);
        $mockAccountProperty->method('getValue')->willReturn('');
        $mockAccount
            ->method('getProperty')
            ->willReturn($mockAccountProperty);
        $this->accountManager
            ->method('getAccount')
            ->willReturn($mockAccount);
        $this->groupManager
            ->method('getUserGroups')
            ->willReturnCallback(
                function () {
                    $groupsArr = [];
                    for ($i=0; $i < 65535; $i++) {
                        $groupName = 'group' . $i;
                        $groupObj = new Group($groupName, [], $this->eventDispatcher, $this->userManager, null, $groupName);
                        array_push($groupsArr, $groupObj);
                    }
                    return $groupsArr;
                }
            );

        $user_id = '34';
        $protocol = 'https';
        $issuer = 'issuer.url';
        $resource = 'http://test.rs.url/';
        $scope = 'openid profile email roles';

        $client = new Client('TEST', 'http://redirect.uri/callback', 'RS256', 'confidential', 'code', 'jwt', false);
        $client->setClientIdentifier('TESTCLIENTIDENTIFIER');

        $code = $this->secureRandom->generate(128, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
        $accessToken = new AccessToken();
        $accessToken->setClientId($client->getId());
        $accessToken->setUserId($user_id);
        $accessToken->setHashedCode(hash('sha512', $code));
        $accessToken->setScope(substr($scope, 0, 128));
        $accessToken->setResource(substr($resource, 0, 2000));
        $accessToken->setCreated($this->time->getTime());
        $accessToken->setRefreshed($this->time->getTime());
        $accessToken->setNonce('12345678');

        // Execute test
        $result = $this->generator->generateAccessToken(
            $accessToken,
            $client,
            $protocol,
            $issuer
        );

        // Decode received JWT
        $oidcKey = [
            'kty' => 'RSA',
            'use' => 'sig',
            'key_ops' => [ 'verify' ],
            'alg' => 'RS256',
            'kid' => $this->appConfig->getAppValueString('kid'),
            'n' => $this->appConfig->getAppValueString('public_key_n'),
            'e' => $this->appConfig->getAppValueString('public_key_e'),
        ];

        $jwks = [
            'keys' => [
                $oidcKey,
            ],
        ];

        JWT::decode($result, JWK::parseKeySet($jwks));
    }

    private function guidv4($data = null)
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
