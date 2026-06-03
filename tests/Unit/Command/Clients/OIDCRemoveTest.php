<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Clients;

use OCA\OIDCIdentityProvider\Command\Clients\OIDCRemove;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCRemoveTest extends TestCase
{
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private OIDCRemove $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);

        $this->command = new OIDCRemove(
            $this->appConfig,
            $this->clientMapper
        );
    }

    public function testExecuteWithExistingClient(): void
    {
        $clientId = 'test-client-id';
        
        $this->clientMapper
            ->method('deleteByIdentifier')
            ->with($clientId)
            ->willReturn(true);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify success message
        $this->assertStringContainsString("Client `{$clientId}` removed.", $display);
    }

    public function testExecuteWithNonExistingClient(): void
    {
        $clientId = 'non-existent-client';
        
        $this->clientMapper
            ->method('deleteByIdentifier')
            ->with($clientId)
            ->willReturn(false);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify not found message
        $this->assertStringContainsString("Client `{$clientId}` not found.", $display);
    }

    public function testExecuteWithException(): void
    {
        $clientId = 'error-client';
        
        $this->clientMapper
            ->method('deleteByIdentifier')
            ->with($clientId)
            ->willThrowException(new \Exception('Database connection failed'));

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify error message
        $this->assertStringContainsString('Error: Database connection failed', $display);
    }

    public function testExecuteWithEmptyClientId(): void
    {
        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => ''
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Empty string should result in not found
        $this->assertStringContainsString("Client `` not found.", $display);
    }

    public function testExecuteWithSpecialCharactersInClientId(): void
    {
        $clientId = 'client-with-special_chars.123';
        
        $this->clientMapper
            ->method('deleteByIdentifier')
            ->with($clientId)
            ->willReturn(true);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        $this->assertStringContainsString("Client `{$clientId}` removed.", $display);
    }

    public function testConfigure(): void
    {
        // Test that the command is properly configured
        $this->assertEquals('oidc:remove', $this->command->getName());
        $this->assertEquals('Remove an oidc client', $this->command->getDescription());
    }

    public function testMissingClientIdArgument(): void
    {
        $tester = new CommandTester($this->command);
        
        // This should throw an exception because client_id is required
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }
}
