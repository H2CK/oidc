<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Command\LogoutRedirectUris\OIDCRemove;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCRemoveTest extends TestCase {
    public function testExecuteRemovesUri(): void {
        $uri = 'https://rp.example/logout';
        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->expects($this->once())->method('deleteByRedirectUri')->with($uri)->willReturn(true);
        $tester = new CommandTester(new OIDCRemove($mapper));

        $status = $tester->execute(['redirect_uri' => $uri]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('removed', $tester->getDisplay());
    }

    public function testExecuteReportsMissingUri(): void {
        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->method('deleteByRedirectUri')->willReturn(false);
        $tester = new CommandTester(new OIDCRemove($mapper));

        $status = $tester->execute(['redirect_uri' => 'https://rp.example/missing']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }
}
