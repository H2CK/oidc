<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Claims;

use OCA\OIDCIdentityProvider\Command\Claims\OIDCList;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\CustomClaim;
use OCA\OIDCIdentityProvider\Db\CustomClaimMapper;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCListTest extends TestCase
{
    private IAppConfig $appConfig;
    private ClientMapper $clientMapper;
    private CustomClaimMapper $customClaimMapper;
    private OIDCList $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->customClaimMapper = $this->createMock(CustomClaimMapper::class);

        $this->command = new OIDCList(
            $this->appConfig,
            $this->clientMapper,
            $this->customClaimMapper
        );
    }

    public function testExecuteWithClaims(): void
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

        $claim1 = new CustomClaim();
        $claim1->setId(1);
        $claim1->setClientId(1);
        $claim1->setName('email_claim');
        $claim1->setScope('profile');
        $claim1->setFunction('getUserEmail');
        $claim1->setParameter(null);

        $claim2 = new CustomClaim();
        $claim2->setId(2);
        $claim2->setClientId(2);
        $claim2->setName('group_claim');
        $claim2->setScope('profile');
        $claim2->setFunction('isInGroup');
        $claim2->setParameter('admins');

        $this->customClaimMapper
            ->method('findAll')
            ->willReturn([$claim1, $claim2]);

        $this->clientMapper
            ->method('getByUid')
            ->willReturnCallback(function ($clientId) use ($client1, $client2) {
                if ($clientId === 1) {
                    return $client1;
                }
                if ($clientId === 2) {
                    return $client2;
                }
                throw new ClientNotFoundException('Client not found');
            });

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify the output contains the claims
        $this->assertStringContainsString('email_claim', $display);
        $this->assertStringContainsString('group_claim', $display);
        $this->assertStringContainsString('client-1', $display);
        $this->assertStringContainsString('client-2', $display);
        $this->assertStringContainsString('getUserEmail', $display);
        $this->assertStringContainsString('isInGroup', $display);
    }

    public function testExecuteWithEmptyList(): void
    {
        $this->customClaimMapper
            ->method('findAll')
            ->willReturn([]);

        // No need to mock getByUid since no claims means no calls to getByUid
        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        // Should complete without errors even with empty list
    }

    public function testExecuteWithException(): void
    {
        $this->customClaimMapper
            ->method('findAll')
            ->willThrowException(new \Exception('Database error'));

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify error message is displayed
        $this->assertStringContainsString('Error: Database error', $display);
    }

    public function testExecuteWithNullClient(): void
    {
        $claim = new CustomClaim();
        $claim->setId(1);
        $claim->setClientId(999); // Non-existent client ID
        $claim->setName('orphan_claim');
        $claim->setScope('profile');
        $claim->setFunction('getUserEmail');
        $claim->setParameter(null);

        $this->customClaimMapper
            ->method('findAll')
            ->willReturn([$claim]);

        $this->clientMapper
            ->method('getByUid')
            ->with(999)
            ->willThrowException(new ClientNotFoundException('Client not found'));

        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $display = $tester->getDisplay();
        
        // Should handle client not found gracefully
        $this->assertStringContainsString('Error:', $display);
    }

    public function testConfigure(): void
    {
        // Test that the command is properly configured
        $this->assertEquals('oidc:list-claim', $this->command->getName());
        $this->assertEquals('List oidc custom claims', $this->command->getDescription());
    }
}
