<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Claims;

use OCA\OIDCIdentityProvider\Command\Claims\OIDCCreate;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCCreateTest extends TestCase
{
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private CustomClaimMapper $customClaimMapper;
    private OIDCCreate $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->customClaimMapper = $this->createMock(CustomClaimMapper::class);

        $this->command = new OIDCCreate(
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

        $customClaim = new CustomClaim();
        $customClaim->setId(1);
        $customClaim->setClientId(1);
        $customClaim->setName('custom_claim');
        $customClaim->setScope('profile');
        $customClaim->setFunction('getUserEmail');
        $customClaim->setParameter(null);

        $this->customClaimMapper
            ->method('createOrUpdate')
            ->willReturn($customClaim);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'name' => 'custom_claim',
            'scope' => 'profile',
            'client_id' => $clientId,
            'function' => 'getUserEmail'
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify the custom claim is output as JSON
        $this->assertStringContainsString('custom_claim', $display);
        $this->assertStringContainsString('profile', $display);
        $this->assertStringContainsString('getUserEmail', $display);
    }

    public function testExecuteWithParameter(): void
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

        $customClaim = new CustomClaim();
        $customClaim->setId(1);
        $customClaim->setClientId(1);
        $customClaim->setName('group_check');
        $customClaim->setScope('profile');
        $customClaim->setFunction('isInGroup');
        $customClaim->setParameter('admins');

        $this->customClaimMapper
            ->method('createOrUpdate')
            ->willReturn($customClaim);

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'name' => 'group_check',
            'scope' => 'profile',
            'client_id' => $clientId,
            'function' => 'isInGroup',
            '--parameter' => 'admins'
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        $this->assertStringContainsString('group_check', $display);
        $this->assertStringContainsString('isInGroup', $display);
        $this->assertStringContainsString('admins', $display);
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
            'name' => 'custom_claim',
            'scope' => 'profile',
            'client_id' => $clientId,
            'function' => 'getUserEmail'
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify error message for non-existing client
        $this->assertStringContainsString('does not exist', $display);
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
            ->method('createOrUpdate')
            ->willThrowException(new \Exception('Database error'));

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([
            'name' => 'custom_claim',
            'scope' => 'profile',
            'client_id' => $clientId,
            'function' => 'getUserEmail'
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        $this->assertStringContainsString('Error: Database error', $display);
    }

    public function testConfigure(): void
    {
        // Test that the command is properly configured
        $this->assertEquals('oidc:create-claim', $this->command->getName());
        $this->assertEquals('Create custom claim for oidc client', $this->command->getDescription());
    }

    public function testMissingRequiredArguments(): void
    {
        $tester = new CommandTester($this->command);
        
        // This should throw an exception because required arguments are missing
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testExecuteWithAllFunctionTypes(): void
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

        $customClaim = new CustomClaim();
        $customClaim->setId(1);

        $this->customClaimMapper
            ->method('createOrUpdate')
            ->willReturn($customClaim);

        // Test with different function types
        $functions = ['isAdmin', 'isGroupAdmin', 'hasRole', 'isInGroup', 'getUserEmail', 'getUserGroups', 'getUserGroupsDisplayName'];
        
        foreach ($functions as $function) {
            $tester = new CommandTester($this->command);
            $statusCode = $tester->execute([
                'name' => 'test_claim_' . $function,
                'scope' => 'profile',
                'client_id' => $clientId,
                'function' => $function
            ]);

            $this->assertSame(Command::SUCCESS, $statusCode, "Failed for function: {$function}");
        }
    }
}
