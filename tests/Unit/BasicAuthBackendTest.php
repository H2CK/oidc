<?php

declare(strict_types=1);

namespace {
    if (!interface_exists('OCP\\IRequest', false)) {
        eval('namespace OCP; interface IRequest { public function getRequestUri(); }');
    }

    if (!interface_exists('OCP\\IURLGenerator', false)) {
        eval('namespace OCP; interface IURLGenerator {}');
    }

    if (!class_exists('OC\\User\\Backend', false)) {
        eval('namespace OC\\User; class Backend { public const CHECK_PASSWORD = 1; }');
    }

    if (!class_exists('OCA\\OIDCIdentityProvider\\Exceptions\\ClientNotFoundException', false)) {
        eval('namespace OCA\\OIDCIdentityProvider\\Exceptions; class ClientNotFoundException extends \\Exception {}');
    }

    if (!class_exists('OCA\\OIDCIdentityProvider\\Db\\Client', false)) {
        eval('namespace OCA\\OIDCIdentityProvider\\Db; class Client { private string $type = "confidential"; private string $clientIdentifier = ""; private string $secret = ""; public function __construct(string $name = "", array $redirectUris = [], string $jwtAlg = "RS256") {} public function setType(string $type): void { $this->type = $type; } public function getType(): string { return $this->type; } public function setClientIdentifier(string $clientIdentifier): void { $this->clientIdentifier = $clientIdentifier; } public function getClientIdentifier(): string { return $this->clientIdentifier; } public function setSecret(string $secret): void { $this->secret = $secret; } public function getSecret(): string { return $this->secret; } }');
    }

    if (!class_exists('OCA\\OIDCIdentityProvider\\Db\\ClientMapper', false)) {
        eval('namespace OCA\\OIDCIdentityProvider\\Db; class ClientMapper { public function getByIdentifier(string $identifier) {} }');
    }

    if (!interface_exists('Psr\\Log\\LoggerInterface', false)) {
        eval('namespace Psr\\Log; interface LoggerInterface { public function emergency(string|\\Stringable $message, array $context = []): void; public function alert(string|\\Stringable $message, array $context = []): void; public function critical(string|\\Stringable $message, array $context = []): void; public function error(string|\\Stringable $message, array $context = []): void; public function warning(string|\\Stringable $message, array $context = []): void; public function notice(string|\\Stringable $message, array $context = []): void; public function info(string|\\Stringable $message, array $context = []): void; public function debug(string|\\Stringable $message, array $context = []): void; public function log($level, string|\\Stringable $message, array $context = []): void; }');
    }
}

namespace OCA\OIDCIdentityProvider\Tests\Unit {

use OCA\OIDCIdentityProvider\BasicAuthBackend;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BasicAuthBackendTest extends TestCase {
    private IRequest&MockObject $request;
    private IURLGenerator&MockObject $urlGenerator;
    private ClientMapper&MockObject $clientMapper;
    private LoggerInterface&MockObject $logger;
    private BasicAuthBackend $backend;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->clientMapper = $this->getMockBuilder(ClientMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->backend = new BasicAuthBackend(
            $this->request,
            $this->urlGenerator,
            $this->clientMapper,
            $this->logger,
        );
    }

    public function testCheckPasswordAllows32CharacterClientCredentials(): void {
        $clientId = str_repeat('a', 32);
        $clientSecret = str_repeat('b', 32);
        $client = $this->createConfidentialClient($clientId, $clientSecret);

        $this->request->method('getRequestUri')->willReturn('/apps/oidc/token');
        $this->clientMapper
            ->expects($this->once())
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);

        $this->assertTrue($this->backend->checkPassword($clientId, $clientSecret));
    }

    public function testCheckPasswordRejectsCredentialWithWhitespace(): void {
        $this->request->expects($this->never())->method('getRequestUri');
        $this->clientMapper->expects($this->never())->method('getByIdentifier');

        $this->assertFalse($this->backend->checkPassword('client id', 'secret'));
    }

    public function testUserExistsAllows32CharacterClientIdentifier(): void {
        $clientId = str_repeat('c', 32);
        $client = $this->createConfidentialClient($clientId, str_repeat('d', 32));

        $this->clientMapper
            ->expects($this->once())
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);

        $this->assertTrue($this->backend->userExists($clientId));
    }

    public function testUserExistsRejectsUnknownClient(): void {
        $clientId = str_repeat('e', 32);

        $this->clientMapper
            ->expects($this->once())
            ->method('getByIdentifier')
            ->with($clientId)
            ->willThrowException(new ClientNotFoundException());

        $this->assertFalse($this->backend->userExists($clientId));
    }

    private function createConfidentialClient(string $clientId, string $clientSecret): Client {
        $client = new Client('test-client', ['https://example.com/callback'], 'RS256');
        $client->setType('confidential');
        $client->setClientIdentifier($clientId);
        $client->setSecret($clientSecret);

        return $client;
    }
}
}
