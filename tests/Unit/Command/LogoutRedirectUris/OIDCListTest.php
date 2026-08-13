<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Command\LogoutRedirectUris\OIDCList;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCListTest extends TestCase {
    public function testExecuteListsUris(): void {
        $uri = new LogoutRedirectUri();
        $uri->setRedirectUri('https://rp.example/logout');
        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->method('getAll')->willReturn([$uri]);
        $tester = new CommandTester(new OIDCList($mapper));

        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('https://rp.example/logout', $tester->getDisplay());
    }
}
