<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Command\LogoutRedirectUris;

use OCA\OIDCIdentityProvider\Command\LogoutRedirectUris\OIDCRemove;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OIDCRemoveTest extends TestCase {
    public function testExecuteRemovesUri(): void {
        $uri = 'https://rp.example/logout';
        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->expects($this->once())->method('deleteByRedirectUri')->with($uri, null)->willReturn(true);
        $clientMapper = $this->createMock(ClientMapper::class);
        $tester = new CommandTester(new OIDCRemove($mapper, $clientMapper));

        $status = $tester->execute(['redirect_uri' => $uri]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('removed', $tester->getDisplay());
    }

    public function testExecuteReportsMissingUri(): void {
        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->method('deleteByRedirectUri')->willReturn(false);
        $clientMapper = $this->createMock(ClientMapper::class);
        $tester = new CommandTester(new OIDCRemove($mapper, $clientMapper));

        $status = $tester->execute(['redirect_uri' => 'https://rp.example/missing']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testExecuteRemovesRpSpecificUri(): void {
        $uri = 'https://rp.example/logout';
        $client = new Client('RP');
        $client->setId(42);
        $client->setClientIdentifier('rp-client');

        $mapper = $this->createMock(LogoutRedirectUriMapper::class);
        $mapper->expects($this->once())->method('deleteByRedirectUri')->with($uri, 42)->willReturn(true);
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->expects($this->once())->method('getByIdentifier')->with('rp-client')->willReturn($client);
        $tester = new CommandTester(new OIDCRemove($mapper, $clientMapper));

        $status = $tester->execute(['redirect_uri' => $uri, '--client-id' => 'rp-client']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('removed', $tester->getDisplay());
    }
}
