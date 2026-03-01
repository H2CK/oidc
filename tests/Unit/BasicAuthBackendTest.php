<?php

namespace OCA\OIDCIdentityProvider\Tests\Unit;

use PHPUnit\Framework\TestCase;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IDBConnection;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

use OCA\OIDCIdentityProvider\BasicAuthBackend;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;

use Psr\Log\LoggerInterface;

class BasicAuthBackendTest extends TestCase {
    /** @var BasicAuthBackend */
    private $backend;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IRequest */
    private $request;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IURLGenerator */
    private $urlGenerator;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ClientMapper */
    private $clientMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|LoggerInterface */
    private $logger;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IAppConfig */
    private $appConfig;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ITimeFactory */
    private $time;
    /** @var \PHPUnit\Framework\MockObject\MockObject|IDBConnection */
    private $db;
    /** @var \PHPUnit\Framework\MockObject\MockObject|ISecureRandom */
    private $secureRandom;
    /** @var \PHPUnit\Framework\MockObject\MockObject|RedirectUriMapper */
    private $redirectUriMapper;
    /** @var \PHPUnit\Framework\MockObject\MockObject|CustomClaimMapper */
    private $customClaimMapper;

    /** 64-char test credentials (auto-generated length) */
    private $uid64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz012345678901';
    private $secret64 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ01';

    /** 48-char test credentials (user-provided length) */
    private $uid48 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuv';
    private $secret48 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKL';

    /** 32-char test credentials (minimum valid length) */
    private $uid32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef';
    private $secret32 = '0123456789abcdefghijklmnopqrstuv';

    public function setUp(): void {
        $this->request = $this->getMockBuilder(IRequest::class)->getMock();
        $this->urlGenerator = $this->getMockBuilder(IURLGenerator::class)->getMock();
        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        $this->appConfig = $this->getMockBuilder(IAppConfig::class)->getMock();
        $this->secureRandom = $this->getMockBuilder(ISecureRandom::class)->getMock();
        $this->time = $this->getMockBuilder(ITimeFactory::class)->getMock();
        $this->db = $this->getMockBuilder(IDBConnection::class)->getMock();
        $this->redirectUriMapper = $this->getMockBuilder(RedirectUriMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig])->getMock();
        $this->customClaimMapper = $this->getMockBuilder(CustomClaimMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)->setConstructorArgs([
            $this->db,
            $this->time,
            $this->appConfig,
            $this->redirectUriMapper,
            $this->customClaimMapper,
            $this->secureRandom,
            $this->logger])->getMock();

        $this->backend = new BasicAuthBackend(
            $this->request,
            $this->urlGenerator,
            $this->clientMapper,
            $this->logger
        );
    }

    // --- checkPassword: length validation ---

    public function testCheckPasswordRejectsTooShort() {
        $this->assertFalse($this->backend->checkPassword('short', 'short'));
    }

    public function testCheckPasswordRejectsTooLong() {
        $uid = str_repeat('a', 65);
        $secret = str_repeat('b', 65);
        $this->assertFalse($this->backend->checkPassword($uid, $secret));
    }

    public function testCheckPasswordRejectsEmpty() {
        $this->assertFalse($this->backend->checkPassword('', ''));
    }

    // --- checkPassword: accepts valid lengths on token endpoint ---

    private function setupValidClient(string $uid, string $secret): void {
        $this->request->method('getRequestUri')
            ->willReturn('/apps/oidc/token');

        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('getClientIdentifier')->willReturn($uid);
        $client->method('getSecret')->willReturn($secret);
        $client->method('getType')->willReturn('confidential');

        $this->clientMapper->method('getByIdentifier')
            ->with($uid)
            ->willReturn($client);
    }

    public function testCheckPasswordAccepts64CharCredentials() {
        $this->setupValidClient($this->uid64, $this->secret64);
        $this->assertTrue($this->backend->checkPassword($this->uid64, $this->secret64));
    }

    public function testCheckPasswordAccepts48CharCredentials() {
        $this->setupValidClient($this->uid48, $this->secret48);
        $this->assertTrue($this->backend->checkPassword($this->uid48, $this->secret48));
    }

    public function testCheckPasswordAccepts32CharCredentials() {
        $this->setupValidClient($this->uid32, $this->secret32);
        $this->assertTrue($this->backend->checkPassword($this->uid32, $this->secret32));
    }

    // --- checkPassword: endpoint restriction ---

    public function testCheckPasswordRejectsNonTokenEndpoint() {
        $this->request->method('getRequestUri')
            ->willReturn('/apps/files/index.php');

        $this->assertFalse($this->backend->checkPassword($this->uid64, $this->secret64));
    }

    public function testCheckPasswordAcceptsIntrospectEndpoint() {
        $this->request->method('getRequestUri')
            ->willReturn('/apps/oidc/introspect');

        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('getClientIdentifier')->willReturn($this->uid64);
        $client->method('getSecret')->willReturn($this->secret64);
        $client->method('getType')->willReturn('confidential');

        $this->clientMapper->method('getByIdentifier')
            ->with($this->uid64)
            ->willReturn($client);

        $this->assertTrue($this->backend->checkPassword($this->uid64, $this->secret64));
    }

    // --- checkPassword: wrong secret ---

    public function testCheckPasswordRejectsWrongSecret() {
        $this->request->method('getRequestUri')
            ->willReturn('/apps/oidc/token');

        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('getClientIdentifier')->willReturn($this->uid64);
        $client->method('getSecret')->willReturn('WrongSecretThatIsExactlySixtyFourCharactersLongForTestingPurpose1');
        $client->method('getType')->willReturn('confidential');

        $this->clientMapper->method('getByIdentifier')
            ->with($this->uid64)
            ->willReturn($client);

        $this->assertFalse($this->backend->checkPassword($this->uid64, $this->secret64));
    }

    // --- checkPassword: unknown client ---

    public function testCheckPasswordRejectsUnknownClient() {
        $this->request->method('getRequestUri')
            ->willReturn('/apps/oidc/token');

        $this->clientMapper->method('getByIdentifier')
            ->willThrowException(new ClientNotFoundException());

        $this->assertFalse($this->backend->checkPassword($this->uid64, $this->secret64));
    }

    // --- userExists: length validation ---

    public function testUserExistsRejectsTooShort() {
        $this->assertFalse($this->backend->userExists('short'));
    }

    public function testUserExistsRejectsTooLong() {
        $this->assertFalse($this->backend->userExists(str_repeat('a', 65)));
    }

    public function testUserExistsAccepts64Char() {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('getClientIdentifier')->willReturn($this->uid64);

        $this->clientMapper->method('getByIdentifier')
            ->with($this->uid64)
            ->willReturn($client);

        $this->assertTrue($this->backend->userExists($this->uid64));
    }

    public function testUserExistsAccepts48Char() {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('getClientIdentifier')->willReturn($this->uid48);

        $this->clientMapper->method('getByIdentifier')
            ->with($this->uid48)
            ->willReturn($client);

        $this->assertTrue($this->backend->userExists($this->uid48));
    }

    public function testUserExistsAccepts32Char() {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->method('getClientIdentifier')->willReturn($this->uid32);

        $this->clientMapper->method('getByIdentifier')
            ->with($this->uid32)
            ->willReturn($client);

        $this->assertTrue($this->backend->userExists($this->uid32));
    }

    public function testUserExistsReturnsFalseForUnknownClient() {
        $this->clientMapper->method('getByIdentifier')
            ->willThrowException(new ClientNotFoundException());

        $this->assertFalse($this->backend->userExists($this->uid64));
    }
}
