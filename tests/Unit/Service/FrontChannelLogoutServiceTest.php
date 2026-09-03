<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutService;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FrontChannelLogoutServiceTest extends TestCase {
    private ClientMapper $clientMapper;
    private RedirectUriMapper $redirectUriMapper;
    private IRequest $request;
    private IURLGenerator $urlGenerator;
    private LoggerInterface $logger;
    private FrontChannelLogoutService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->clientMapper = $this->createMock(ClientMapper::class);
        $this->redirectUriMapper = $this->createMock(RedirectUriMapper::class);
        $this->request = $this->createMock(IRequest::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->request->method('getServerProtocol')->willReturn('https');
        $this->request->method('getServerHost')->willReturn('nextcloud.example');
        $this->urlGenerator->method('getWebroot')->willReturn('/cloud');
        $this->service = new FrontChannelLogoutService(
            $this->clientMapper,
            $this->redirectUriMapper,
            $this->request,
            $this->urlGenerator,
            $this->logger,
        );
    }

    public function testBuildsLogoutUriWithIssuerSidAndExistingQuery(): void {
        $client = $this->client(7, 'client-7', 'confidential', 'https://rp.example/frontchannel?from=op');
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->redirectUriMapper->method('getByClientId')->with(7)->willReturn([$this->redirect(7, 'https://rp.example/callback')]);

        $result = $this->service->getLogoutUris(['7' => 'sid value']);

        $this->assertSame([
            'https://rp.example/frontchannel?from=op&iss=https%3A%2F%2Fnextcloud.example%2Fcloud&sid=sid%20value',
        ], $result);
    }

    public function testRuntimeValidationSkipsLegacyLogoutUriOnUnrelatedOrigin(): void {
        $client = $this->client(7, 'client-7', 'confidential', 'https://attacker.example/frontchannel');
        $this->clientMapper->method('getByUid')->with(7)->willReturn($client);
        $this->redirectUriMapper->method('getByClientId')->with(7)->willReturn([$this->redirect(7, 'https://rp.example/callback')]);
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([], $this->service->getLogoutUris(['7' => 'sid-7']));
    }

    public function testOriginBindingUsesEffectiveDefaultPorts(): void {
        $this->assertTrue(FrontChannelLogoutService::isValidForRedirectUris(
            'https://rp.example/logout',
            'confidential',
            ['https://rp.example:443/callback']
        ));
        $this->assertFalse(FrontChannelLogoutService::isValidForRedirectUris(
            'https://other.example/logout',
            'confidential',
            ['https://rp.example/callback']
        ));
        $this->assertFalse(FrontChannelLogoutService::isValidForRedirectUris(
            'https://rp.example:8443/logout',
            'confidential',
            ['https://rp.example/callback']
        ));
    }

    public function testBrowserLogoutResponseUsesThreeSecondGracePeriodAndOriginCsp(): void {
        $response = $this->service->createBrowserLogoutResponse(
            ['https://rp.example/logout?sid=abc', 'https://rp.example:443/second'],
            'https://nextcloud.example/login'
        );
        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $this->assertStringContainsString('content="3;url=https://nextcloud.example/login"', $response->getData());
        $this->assertStringContainsString('https://rp.example', $response->getHeaders()['Content-Security-Policy']);
        $this->assertStringNotContainsString('https://rp.example:443', $response->getHeaders()['Content-Security-Policy']);
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

    private function redirect(int $clientId, string $uri): RedirectUri {
        $redirect = new RedirectUri();
        $redirect->setClientId($clientId);
        $redirect->setRedirectUri($uri);
        return $redirect;
    }
}
