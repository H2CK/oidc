<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Claims;

use OCA\OIDCIdentityProvider\Command\Claims\OIDCRemove;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCRemoveTest extends TestCase
{
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private CustomClaimMapper $customClaimMapper;
    private OIDCRemove $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->customClaimMapper = $this->createMock(CustomClaimMapper::class);

        $this->command = new OIDCRemove(
            $this->appConfig,
            $this->clientMapper,
            $this->customClaimMapper
        );
    }

    public function testExecuteWithValidInput(): void
    {
        $clientId = 'test-client';
        $client = new Client(
            'Test Client',
            ['https://client.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile',
            ''
        );
        $client->setId(1);
        $client->setClientIdentifier($clientId);

        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);

        $this->customClaimMapper
            ->method('deleteByClientAndName')
            ->with(1, 'test_claim');

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId,
            'name' => 'test_claim'
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify success message
        $this->assertStringContainsString("Claim `test_claim` for client `{$clientId}` removed.", $display);
    }

    public function testExecuteWithNonExistingClient(): void
    {
        $clientId = 'non-existent-client';
        
        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn(null);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId,
            'name' => 'test_claim'
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify error message for non-existing client
        $this->assertStringContainsString("Client with identifier '{$clientId}' does not exist", $display);
    }

    public function testExecuteWithException(): void
    {
        $clientId = 'test-client';
        $client = new Client(
            'Test Client',
            ['https://client.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile',
            ''
        );
        $client->setId(1);
        $client->setClientIdentifier($clientId);

        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);

        $this->customClaimMapper
            ->method('deleteByClientAndName')
            ->willThrowException(new \Exception('Database error'));

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId,
            'name' => 'test_claim'
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        $this->assertStringContainsString('Error: Database error', $display);
    }

    public function testExecuteWithClientIdWithSpecialCharacters(): void
    {
        $clientId = 'client-with-special_chars.123';
        $client = new Client(
            'Test Client',
            ['https://client.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile',
            ''
        );
        $client->setId(1);
        $client->setClientIdentifier($clientId);

        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);

        $this->customClaimMapper
            ->method('deleteByClientAndName')
            ->with(1, 'special_claim');

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId,
            'name' => 'special_claim'
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        $this->assertStringContainsString("Claim `special_claim` for client `{$clientId}` removed.", $display);
    }

    public function testExecuteWithClaimNameWithSpecialCharacters(): void
    {
        $clientId = 'test-client';
        $client = new Client(
            'Test Client',
            ['https://client.example.com/callback'],
            'RS256',
            'confidential',
            'code',
            'opaque',
            'openid profile',
            ''
        );
        $client->setId(1);
        $client->setClientIdentifier($clientId);

        $this->clientMapper
            ->method('getByIdentifier')
            ->with($clientId)
            ->willReturn($client);

        $claimName = 'claim-with-special_chars.123';
        $this->customClaimMapper
            ->method('deleteByClientAndName')
            ->with(1, $claimName);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'client_id' => $clientId,
            'name' => $claimName
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        $this->assertStringContainsString("Claim `{$claimName}` for client `{$clientId}` removed.", $display);
    }

    public function testConfigure(): void
    {
        // Test that the command is properly configured
        $this->assertEquals('oidc:remove-claim', $this->command->getName());
        $this->assertEquals('Remove an oidc claim', $this->command->getDescription());
    }

    public function testMissingRequiredArguments(): void
    {
        $tester = new CommandTester($this->command);
        
        // This should throw an exception because client_id and name are required
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testMissingClaimNameArgument(): void
    {
        $tester = new CommandTester($this->command);
        
        // This should throw an exception because name is required
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([
            'client_id' => 'test-client'
        ]);
    }

    public function testMissingClientIdArgument(): void
    {
        $tester = new CommandTester($this->command);
        
        // This should throw an exception because client_id is required
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([
            'name' => 'test_claim'
        ]);
    }
}
