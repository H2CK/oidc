<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Command\LogoutRedirectUris\OIDCCreate;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCCreateTest extends TestCase {
    private LogoutRedirectUriMapper $mapper;
    private RedirectUriService $redirectUriService;
    private ClientMapper $clientMapper;
    private OIDCCreate $command;

    protected function setUp(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getAppValueString')->with(
            Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS,
            Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS,
        )->willReturn(Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS);
        $this->mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $this->redirectUriService = $this->createMock(RedirectUriService::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->command = new OIDCCreate($appConfig, $this->mapper, $this->redirectUriService, $this->clientMapper);
    }

    public function testExecuteCreatesValidatedUri(): void {
        $uri = 'https://rp.example/logout';
        $this->redirectUriService->expects($this->once())->method('isValidRedirectUri')->with($uri, false)->willReturn(true);
        $this->mapper->expects($this->once())->method('insert')->willReturnCallback(static fn (LogoutRedirectUri $entity) => $entity);

        $tester = new CommandTester($this->command);
        $status = $tester->execute(['redirect_uri' => $uri]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString($uri, $tester->getDisplay());
    }

    public function testExecuteCreatesRpSpecificUri(): void {
        $uri = 'https://rp.example/logout';
        $client = new Client('RP');
        $client->setId(42);
        $client->setClientIdentifier('rp-client');

        $this->redirectUriService->expects($this->once())->method('isValidRedirectUri')->with($uri, false)->willReturn(true);
        $this->clientMapper->expects($this->once())->method('getByIdentifier')->with('rp-client')->willReturn($client);
        $this->mapper->expects($this->once())->method('insert')->willReturnCallback(function (LogoutRedirectUri $entity): LogoutRedirectUri {
            $this->assertSame(42, $entity->getClientId());
            return $entity;
        });

        $tester = new CommandTester($this->command);
        $status = $tester->execute(['redirect_uri' => $uri, '--client-id' => 'rp-client']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('rp-client', $tester->getDisplay());
        $this->assertStringContainsString($uri, $tester->getDisplay());
    }

    public function testExecuteRejectsInvalidUri(): void {
        $this->redirectUriService->method('isValidRedirectUri')->willReturn(false);
        $this->mapper->expects($this->never())->method('insert');

        $tester = new CommandTester($this->command);
        $status = $tester->execute(['redirect_uri' => 'invalid']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('not valid', $tester->getDisplay());
    }

    public function testConfigure(): void {
        $this->assertSame('oidc:create-logout-redirect-uri', $this->command->getName());
    }
}
