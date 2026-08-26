<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Clients;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Command\Clients\OIDCCreate;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\TexTargetMapper;
use OCA\OIDCIdentityProvider\Db\TexTargets;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCCreateTest extends TestCase
{
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private RedirectUriService $redirectUriService;
    private TexTargetMapper $texTargetMapper;
    private OIDCCreate $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->redirectUriService = $this->createMock(RedirectUriService::class);
        $this->texTargetMapper = $this->createMock(TexTargetMapper::class);
        $this->texTargetMapper->method('insert')->willReturnCallback(static fn (TexTargets $target) => $target);

        $this->appConfig
            ->method('getAppValueString')
            ->willReturnMap([
                [Application::APP_CONFIG_DEFAULT_TOKEN_TYPE, Application::DEFAULT_TOKEN_TYPE, Application::DEFAULT_TOKEN_TYPE],
                [Application::APP_CONFIG_ALLOW_SUBDOMAIN_WILDCARDS, Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS, Application::DEFAULT_ALLOW_SUBDOMAIN_WILDCARDS],
            ]);

        $this->redirectUriService
            ->method('isValidRedirectUri')
            ->willReturn(true);

        $this->clientMapper
            ->method('insert')
            ->willReturnCallback(static fn (Client $client) => $client);

        $this->command = $this->getMockBuilder(OIDCCreate::class)
            ->onlyMethods(['getTexTargetMapper'])
            ->setConstructorArgs([$this->appConfig, $this->clientMapper, $this->redirectUriService])
            ->getMock();
        $this->command->method('getTexTargetMapper')->willReturn($this->texTargetMapper);
    }

    #[DataProvider('validCredentialProvider')]
    public function testExecuteAcceptsValidCredentials(string $clientId, string $clientSecret): void
    {
        $tester = new CommandTester($this->command);

        $statusCode = $tester->execute([
            'name' => 'Test Client',
            'redirect_uris' => ['https://local.lo/callback'],
            '--client_id' => $clientId,
            '--client_secret' => $clientSecret,
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString($clientId, $display);
        $this->assertStringContainsString($clientSecret, $display);
    }

    public function testExecuteRejectsColonInClientId(): void
    {
        $tester = new CommandTester($this->command);

        $statusCode = $tester->execute([
            'name' => 'Test Client',
            'redirect_uris' => ['https://local.lo/callback'],
            '--client_id' => 'client:id-with-colon-01234567890123',
            '--client_secret' => '0582bb51ac974f318c4fe11779c439a0',
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString(
            'Your clientId must comply with the following rules: printable ASCII except : and length 32-64',
            $tester->getDisplay()
        );
    }

    public function testExecuteSetsTexOptionsAndCreatesTargets(): void
    {
        $client = null;
        $this->clientMapper
            ->method('insert')
            ->willReturnCallback(static function (Client $value) use (&$client): Client {
                $value->setId(7);
                $client = $value;
                return $value;
            });

        $this->texTargetMapper->expects($this->exactly(2))->method('insert');

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'name' => 'TEX Client',
            'redirect_uris' => ['https://local.lo/callback'],
            '--tex_enabled' => true,
            '--tex_allowed_scopes' => 'openid profile',
            '--tex_target_resource' => ['https://resource.example/one', 'https://resource.example/two'],
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertTrue($client->getTexEnabled());
        $this->assertSame('openid profile', $client->getTexAllowedScopes());
    }

    public function testExecuteRejectsTokenExchangeForPublicClient(): void
    {
        $tester = new CommandTester($this->command);

        $statusCode = $tester->execute([
            'name' => 'Public Client',
            'redirect_uris' => ['https://local.lo/callback'],
            '--type' => 'public',
            '--tex_enabled' => true,
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString(
            'Token Exchange cannot be enabled for public clients.',
            $tester->getDisplay()
        );
    }

    public static function validCredentialProvider(): array
    {
        return [
            'alphanumeric credentials' => [
                '0582bb51ac974f318c4fe11779c439a0',
                '0582bb51ac974f318c4fe11779c439a0',
            ],
            'hyphen in client id' => [
                'client-id-with-hyphen-012345678901',
                '0582bb51ac974f318c4fe11779c439a0',
            ],
            'underscore in client id' => [
                'client_id_with_underscores_0123456',
                '0582bb51ac974f318c4fe11779c439a0',
            ],
            'dot in client id' => [
                'client.id.with.dots.01234567890123',
                '0582bb51ac974f318c4fe11779c439a0',
            ],
        ];
    }
}
