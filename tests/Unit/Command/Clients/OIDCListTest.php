<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Clients;

use OCA\OIDCIdentityProvider\Command\Clients\OIDCList;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCListTest extends TestCase
{
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private OIDCList $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);

        $this->command = new OIDCList(
            $this->appConfig,
            $this->clientMapper
        );
    }

    public function testExecuteWithClients(): void
    {
        $client1 = new Client(
            'Test Client 1',
            ['https://client1.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile',
            ''
        );
        $client1->setId(1);
        $client1->setClientIdentifier('client-1');

        $client2 = new Client(
            'Test Client 2',
            ['https://client2.example.com/callback'],
            'RS256',
            'public',
            'code',
            'jwt',
            'openid email',
            ''
        );
        $client2->setId(2);
        $client2->setClientIdentifier('client-2');

        $this->clientMapper
            ->method('getClients')
            ->willReturn([$client1, $client2]);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify the output contains the clients as JSON
        $this->assertStringContainsString('Test Client 1', $display);
        $this->assertStringContainsString('Test Client 2', $display);
        $this->assertStringContainsString('client-1', $display);
        $this->assertStringContainsString('client-2', $display);
    }

    public function testExecuteWithEmptyList(): void
    {
        $this->clientMapper
            ->method('getClients')
            ->willReturn([]);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Should output an empty JSON array
        $this->assertStringContainsString('[]', $display);
    }

    public function testExecuteWithException(): void
    {
        $this->clientMapper
            ->method('getClients')
            ->willThrowException(new \Exception('Database error'));

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify error message is displayed
        $this->assertStringContainsString('Error: Database error', $display);
    }

    public function testExecuteWithInvalidClientData(): void
    {
        $client = new Client(
            'Test Client',
            ['https://client.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid',
            ''
        );
        $client->setId(1);
        // No client identifier set - should still work

        $this->clientMapper
            ->method('getClients')
            ->willReturn([$client]);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Should still output valid JSON even with minimal client data
        $this->assertStringContainsString('Test Client', $display);
    }

    public function testConfigure(): void
    {
        // Test that the command is properly configured
        $this->assertEquals('oidc:list', $this->command->getName());
        $this->assertEquals('List oidc clients', $this->command->getDescription());
    }
}
