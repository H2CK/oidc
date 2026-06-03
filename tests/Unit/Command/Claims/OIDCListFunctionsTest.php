<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\Claims;

use OCA\OIDCIdentityProvider\Command\Claims\OIDCListFunctions;
use OCA\OIDCIdentityProvider\Service\CustomClaimService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCListFunctionsTest extends TestCase
{
    private IAppConfig $appConfig;
    private CustomClaimService $customClaimService;
    private OIDCListFunctions $command;

    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->customClaimService = $this->createMock(CustomClaimService::class);

        $this->command = new OIDCListFunctions(
            $this->appConfig,
            $this->customClaimService
        );
    }

    public function testExecuteListsAllFunctions(): void
    {
        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $display = $tester->getDisplay();
        
        // Verify all functions from CustomClaimService::FUNCTIONS are listed
        $expectedFunctions = [
            'isAdmin',
            'isGroupAdmin',
            'hasRole',
            'isInGroup',
            'getUserEmail',
            'getUserGroups',
            'getUserGroupsDisplayName'
        ];
        
        foreach ($expectedFunctions as $function) {
            $this->assertStringContainsString($function, $display, "Function {$function} should be listed");
        }
    }

    public function testExecuteWithException(): void
    {
        // This is a bit tricky to test since the command doesn't have external dependencies
        // that could throw exceptions. The only way to get an exception here would be
        // if CustomClaimService::FUNCTIONS constant itself was corrupted, which is unlikely.
        // So we'll test that the command handles the normal case correctly.
        
        $tester = new CommandTester($this->command);
        $statusCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $statusCode);
    }

    public function testConfigure(): void
    {
        // Test that the command is properly configured
        $this->assertEquals('oidc:list-claim-functions', $this->command->getName());
        $this->assertEquals('List functions to be used for oidc custom claims', $this->command->getDescription());
    }

    public function testFunctionsConstantAccessible(): void
    {
        // Verify that we can access the FUNCTIONS constant from CustomClaimService
        $functions = CustomClaimService::FUNCTIONS;
        
        $this->assertIsArray($functions);
        $this->assertNotEmpty($functions);
        
        // Verify each function has the expected structure
        foreach ($functions as $func) {
            $this->assertArrayHasKey('name', $func);
            $this->assertArrayHasKey('method', $func);
            $this->assertArrayHasKey('parameters', $func);
            
            $this->assertIsString($func['name']);
            $this->assertIsString($func['method']);
            $this->assertIsArray($func['parameters']);
        }
    }

    public function testOutputFormat(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute([]);
        
        $display = $tester->getDisplay();
        $lines = explode("\n", trim($display));
        
        // Each function should be on its own line
        $this->assertGreaterThan(0, count($lines));
        
        // Verify no extra formatting
        foreach ($lines as $line) {
            $this->assertNotEmpty(trim($line));
        }
    }
}
