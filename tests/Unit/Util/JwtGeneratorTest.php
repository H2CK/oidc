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
use OC\Security\SecureRandom;
use OC\User\User;
use OCA\OIDCIdentityProvider\Db\Group;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\DAV\CardDAV\Converter;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Server;
use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\IAccountManager;
use Psr\Log\LoggerInterface;

class JwtGeneratorTest extends TestCase {
        protected $generator;
        /** @var ICrypto */
        private $crypto;
        /** @var TokenProvider */
        private $tokenProvider;
        /** @var ISecureRandom */
        private $secureRandom;
        /** @var ITimeFactory */
        private $time;
        /** @var IUserManager */
        private $userManager;
        /** @var IGroupManager */
        private $groupManager;
        /** @var IAccountManager */
        private $accountManager;
        /** @var IURLGenerator */
        private $urlGenerator;
        /** @var IAppConfig */
        private $appConfig;
        /** @var LoggerInterface */
        private $logger;
        /** @var Converter */
        private $converter;

    public function setUp(): void {
        $this->crypto = $this->getMockBuilder(ICrypto::class)->getMock();
        $this->tokenProvider = Server::get(TokenProvider::class);
        $this->secureRandom = Server::get(SecureRandom::class);
        $this->time = Server::get(TimeFactory::class);
        $this->userManager = $this->getMockBuilder(IUserManager::class)->getMock();
        $this->groupManager = $this->getMockBuilder(IGroupManager::class)->getMock();
        $this->accountManager = $this->getMockBuilder(IAccountManager::class)->getMock();
        $this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->converter = Server::get(Converter::class);

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
            $this->logger,
            $this->converter
        );
    }

    // public function testGenerateIdToken() {
    // TODO create test
    //     $result = $this->generator->generateIdToken();
    // }

    public function testGenerateOpaqueAccessToken() {
        $client = new Client('TEST', 'http://redirect.uri/callback', 'RS256', 'confidential', 'code', false, false);
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

        // Mock necessary methods
        $this->appConfig
            ->method('getAppValue')
            ->willReturnMap([
                ['dynamic_client_registration', 'false', 'true'],
                ['expire_time', Application::DEFAULT_EXPIRE_TIME, '3600'],
                ['integrate_avatar', 'id_token'],
                ['overwrite_email_verified', 'true'],
                ['private_key', $privateKey],
                ['public_key', $publicKey],
                ['public_key_n', $modulus],
                ['public_key_e', $exponent],
                ['kid', $this->guidv4()],
            ]);

        $this->userManager
            ->method('get')
            ->willReturnCallBack (
                function ($arg) {
                    return null;
                }
            );
        $this->groupManager
            ->method('getUserGroups')
            ->willReturnCallBack (
                function ($arg) {
                    return [];
                }
            );

        $user_id = '34';
        $protocol = 'https';
        $issuer = 'issuer.url';
        $resource = 'http://test.rs.url/';
        $scope = 'openid profile email roles';

        $client = new Client('TEST', 'http://redirect.uri/callback', 'RS256', 'confidential', 'code', true, false);
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
            'kid' => $this->appConfig->getAppValue('kid'),
            'n' => $this->appConfig->getAppValue('public_key_n'),
            'e' => $this->appConfig->getAppValue('public_key_e'),
        ];

        $jwks = [
            'keys' => [
                $oidcKey,
            ],
        ];

        $decodedStdClass = JWT::decode($result, JWK::parseKeySet($jwks));
        $decodedJwt = (array) $decodedStdClass;

        //var_dump($decodedJwt);

        // Test if decoded JWT contains necessary values
        $this->assertEquals($protocol . "://" . $issuer, $decodedJwt['iss']);
        $this->assertEquals($user_id, $decodedJwt['sub']);
        $this->assertEquals($resource, $decodedJwt['aud']);
        $this->assertEquals($scope, $decodedJwt['scope']);
        $this->assertEquals($client->getClientIdentifier(), $decodedJwt['client_id']);
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
