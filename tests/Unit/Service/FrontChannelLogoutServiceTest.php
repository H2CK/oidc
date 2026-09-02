<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FrontChannelLogoutServiceTest extends TestCase {
    private ClientMapper $clientMapper;
    private IRequest $request;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private FrontChannelLogoutService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->request = $this->createMock(IRequest::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('nextcloud.example');
        $this->urlGenerator->method('getWebroot')->willReturn('/cloud');
        $this->service = new FrontChannelLogoutService(
            $this->clientMapper,
            $this->request,
            $this->urlGenerator,
            $this->logger,
        );
    }

    public function testBuildsLogoutUriWithIssuerSidAndExistingQuery(): void {
        $client = $this->client(7, 'client-7', 'confidential', 'https://rp.example/frontchannel?from=op');
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);

        $result = $this->service->getLogoutUris(['7' => 'sid value']);

        $this->assertSame([
            'https://rp.example/frontchannel?from=op&iss=https%3A%2F%2Fnextcloud.example%2Fcloud&sid=sid%20value',
        ], $result);
    }

    public function testSkipsMissingInvalidAndDuplicateEntries(): void {
        $valid = $this->client(7, 'client-7', 'confidential', 'https://rp.example/frontchannel');
        $invalid = $this->client(8, 'client-8', 'public', 'http://rp.example/frontchannel');
        $this->clientMapper->method('getByUid')->willReturnCallback(static fn (int $id): Client => match ($id) {
            7 => $valid,
            8 => $invalid,
            default => throw new \RuntimeException('missing'),
        });
        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->getLogoutUris([
            '7' => 'sid-7',
            '8' => 'sid-8',
            '9' => 'sid-9',
            'not-a-number' => 'sid-x',
            '10' => '',
        ]);

        $this->assertSame([
            'https://rp.example/frontchannel?iss=https%3A%2F%2Fnextcloud.example%2Fcloud&sid=sid-7',
        ], $result);
    }

    #[DataProvider('validationProvider')]
    public function testUriValidation(string $uri, string $clientType, bool $expected): void {
        $this->assertSame($expected, FrontChannelLogoutService::isValidForClientType($uri, $clientType));
    }

    public static function validationProvider(): array {
        return [
            'https confidential' => ['https://rp.example/logout', 'confidential', true],
            'https public' => ['https://rp.example/logout', 'public', true],
            'http confidential' => ['http://rp.example/logout', 'confidential', true],
            'http public' => ['http://rp.example/logout', 'public', false],
            'fragment' => ['https://rp.example/logout#fragment', 'confidential', false],
            'credentials' => ['https://user:pass@rp.example/logout', 'confidential', false],
            'custom scheme' => ['com.example.app:/logout', 'public', false],
            'relative' => ['/logout', 'confidential', false],
        ];
    }

    private function client(int $id, string $identifier, string $type, ?string $frontChannelUri): Client {
        $client = new Client();
        $client->setId($id);
        $client->setClientIdentifier($identifier);
        $client->setType($type);
        $client->setFrontchannelLogoutUri($frontChannelUri);
        return $client;
    }
}
