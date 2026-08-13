<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Command\LogoutRedirectUris\OIDCCreate;
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
    private OIDCCreate $command;

    protected function setUp(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getAppValueString')->with(
            Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS,
            Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS,
        )->willReturn(Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS);
        $this->mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $this->redirectUriService = $this->createMock(RedirectUriService::class);
        $this->command = new OIDCCreate($appConfig, $this->mapper, $this->redirectUriService);
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
