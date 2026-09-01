<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Command\LogoutRedirectUris\OIDCList;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
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
        $mapper->method('getGlobal')->willReturn([$uri]);
        $clientMapper = $this->createMock(ClientMapper::class);
        $tester = new CommandTester(new OIDCList($mapper, $clientMapper));

        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('https://rp.example/logout', $tester->getDisplay());
    }

    public function testExecuteListsRpSpecificUris(): void {
        $uri = new LogoutRedirectUri();
        $uri->setRedirectUri('https://rp.example/logout');
        $client = new Client('RP');
        $client->setId(42);
        $client->setClientIdentifier('rp-client');

        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->expects($this->once())->method('getByClientId')->with(42)->willReturn([$uri]);
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->expects($this->once())->method('getByIdentifier')->with('rp-client')->willReturn($client);
        $tester = new CommandTester(new OIDCList($mapper, $clientMapper));

        $status = $tester->execute(['--client-id' => 'rp-client']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('rp-client', $tester->getDisplay());
        $this->assertStringContainsString('https://rp.example/logout', $tester->getDisplay());
    }
}
