<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use OCP\BackgroundJob\IJobList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BackChannelLogoutServiceTest extends TestCase {
    /** @dataProvider uriValidationProvider */
    public function testBackChannelLogoutUriValidation(string $uri, string $clientType, bool $expected): void {
        $this->assertSame(
            $expected,
            BackChannelLogoutService::isValidBackChannelLogoutUri($uri, $clientType)
        );
    }

    public function testRegisterClientSessionIsStablePerClientAndDifferentAcrossClients(): void {
        $state = [];
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$state) {
            return $state[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$state): void {
            $state[$key] = $value;
        });
        $session->method('remove')->willReturnCallback(static function (string $key) use (&$state): void {
            unset($state[$key]);
        });

        $secureRandom = $this->createMock(ISecureRandom::class);
        $secureRandom->expects($this->exactly(2))->method('generate')->willReturnOnConsecutiveCalls('sid-one', 'sid-two');
        $service = new BackChannelLogoutService(
            $session,
            $secureRandom,
            $this->createMock(ClientMapper::class),
            $this->createMock(JwtGenerator::class),
            $this->createMock(IClientService::class),
            $this->createMock(IRequest::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IJobList::class),
            $this->createMock(ITimeFactory::class),
        );

        $clientOne = $this->newClient(1, 'client-1', 'https://rp1.example/logout');
        $clientTwo = $this->newClient(2, 'client-2', 'https://rp2.example/logout');

        $this->assertSame('sid-one', $service->registerClientSession($clientOne));
        $this->assertSame('sid-one', $service->registerClientSession($clientOne));
        $this->assertSame('sid-two', $service->registerClientSession($clientTwo));
    }

    public function testCurrentClientSessionRequiresSidFromCurrentVersionedSessionState(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'oidc_backchannel_sessions_v2' ? ['7' => 'current-sid'] : ['7' => 'legacy-sid']
        );
        $client = $this->newClient(7, 'client-7', 'https://rp.example/logout');
        $service = $this->newService($session);

        $this->assertTrue($service->isCurrentClientSession($client, 'current-sid'));
        $this->assertFalse($service->isCurrentClientSession($client, 'legacy-sid'));
        $this->assertFalse($service->isCurrentClientSession($client, null));
    }

    public function testLogoutStartsAllRequestsAsynchronouslyBeforeWaiting(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'oidc_backchannel_sessions_v2'
                ? ['1' => 'sid-1', '2' => 'sid-2']
                : null
        );
        $session->expects($this->exactly(2))->method('remove');

        $clientOne = $this->newClient(1, 'client-1', 'https://rp1.example/logout');
        $clientTwo = $this->newClient(2, 'client-2', 'https://rp2.example/logout');

        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->willReturnCallback(
            static fn (int $id): Client => $id === 1 ? $clientOne : $clientTwo
        );

        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->expects($this->exactly(2))
            ->method('generateLogoutToken')
            ->willReturnCallback(static fn (Client $client): string => 'logout-token-' . $client->getClientIdentifier());

        $promiseOne = $this->createMock(IPromise::class);
        $promiseTwo = $this->createMock(IPromise::class);
        $promiseOne->method('then')->willReturnSelf();
        $promiseTwo->method('then')->willReturnSelf();
        $promiseOne->expects($this->once())->method('wait')->with(false);
        $promiseTwo->expects($this->once())->method('wait')->with(false);

        $requestedUris = [];
        $httpClient = $this->createMock(IClient::class);
        $httpClient->expects($this->never())->method('post');
        $httpClient->expects($this->exactly(2))
            ->method('postAsync')
            ->willReturnCallback(function (string $uri, array $options) use (&$requestedUris, $promiseOne, $promiseTwo): IPromise {
                $requestedUris[] = $uri;
                $this->assertSame('application/x-www-form-urlencoded', $options['headers']['Content-Type']);
                $this->assertFalse($options['allow_redirects']);
                $this->assertSame(5, $options['timeout']);
                $this->assertArrayHasKey('logout_token', $options['body']);
                return count($requestedUris) === 1 ? $promiseOne : $promiseTwo;
            });

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');

        $service = new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IJobList::class),
            $this->createMock(ITimeFactory::class),
        );

        $service->logout('user1');

        $this->assertSame([
            'https://rp1.example/logout',
            'https://rp2.example/logout',
        ], $requestedUris);
    }

    /** @dataProvider dynamicUriPolicyProvider */
    public function testDynamicBackChannelLogoutUriPolicy(string $uri, bool $expected): void {
        $this->assertSame(
            $expected,
            BackChannelLogoutService::isAllowedDynamicBackChannelLogoutUri($uri, 'confidential')
        );
    }

    public function testDynamicClientPrivateTargetIsBlockedAgainAtDeliveryTime(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'oidc_backchannel_sessions_v2' ? ['1' => 'sid-1'] : null
        );

        $client = $this->newClient(1, 'client-1', 'https://127.0.0.1/logout');
        $client->setDcr(true);
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->with(1)->willReturn($client);

        $httpClient = $this->createMock(IClient::class);
        $httpClient->expects($this->never())->method('postAsync');
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $service = new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $this->createMock(JwtGenerator::class),
            $clientService,
            $this->createMock(IRequest::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IJobList::class),
            $this->createMock(ITimeFactory::class),
        );

        $service->logout('user1');
    }

    public function testDynamicClientForcesNextcloudLocalAddressProtectionOnRequest(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'oidc_backchannel_sessions_v2' ? ['1' => 'sid-1'] : null
        );
        $client = $this->newClient(1, 'client-1', 'https://8.8.8.8/logout');
        $client->setDcr(true);
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->willReturn($client);
        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->method('generateLogoutToken')->willReturn('token');

        $promise = $this->createMock(IPromise::class);
        $promise->method('then')->willReturnSelf();
        $promise->method('wait')->with(false);
        $httpClient = $this->createMock(IClient::class);
        $httpClient->expects($this->once())->method('postAsync')->with(
            'https://8.8.8.8/logout',
            $this->callback(function (array $options): bool {
                $this->assertFalse($options['nextcloud']['allow_local_address']);
                $this->assertFalse($options['allow_redirects']);
                return true;
            })
        )->willReturn($promise);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);
        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');

        $service = new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IJobList::class),
            $this->createMock(ITimeFactory::class),
        );

        $service->logout('user1');
    }

    public function testOneRpStartFailureQueuesRetryAndDoesNotPreventOtherRpDelivery(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'oidc_backchannel_sessions_v2'
                ? ['1' => 'sid-1', '2' => 'sid-2']
                : null
        );
        $clientOne = $this->newClient(1, 'client-1', 'https://rp1.example/logout');
        $clientTwo = $this->newClient(2, 'client-2', 'https://rp2.example/logout');
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->willReturnCallback(
            static fn (int $id): Client => $id === 1 ? $clientOne : $clientTwo
        );
        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->method('generateLogoutToken')->willReturn('token');

        $promise = $this->createMock(IPromise::class);
        $promise->method('then')->willReturnSelf();
        $promise->expects($this->once())->method('wait')->with(false);
        $httpClient = $this->createMock(IClient::class);
        $httpClient->expects($this->exactly(2))->method('postAsync')->willReturnCallback(
            static function (string $uri) use ($promise): IPromise {
                if ($uri === 'https://rp1.example/logout') {
                    throw new \RuntimeException('connection failed');
                }
                return $promise;
            }
        );
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);
        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())->method('scheduleAfter')->with(
            \OCA\OIDCIdentityProvider\BackgroundJob\BackChannelLogoutRetryJob::class,
            1030,
            $this->callback(static fn (array $arg): bool => $arg === [
                'client_db_id' => 1,
                'sid' => 'sid-1',
                'attempt' => 1,
            ])
        );

        $service = new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $jobList,
            $time,
        );

        $service->logout('user1');
    }

    public function testInitialRetryableStatusQueuesFirstRetry(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key) => $key === 'oidc_backchannel_sessions_v2' ? ['1' => 'sid-1'] : null
        );
        $client = $this->newClient(1, 'client-1', 'https://rp.example/logout');
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->willReturn($client);
        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->method('generateLogoutToken')->willReturn('token');

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(503);
        $promise = $this->createMock(IPromise::class);
        $promise->method('then')->willReturnCallback(static function (callable $success) use ($response, $promise): IPromise {
            $success($response);
            return $promise;
        });
        $promise->method('wait')->with(false);
        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('postAsync')->willReturn($promise);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);
        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())->method('scheduleAfter')->with(
            \OCA\OIDCIdentityProvider\BackgroundJob\BackChannelLogoutRetryJob::class,
            1030,
            $this->callback(static fn (array $arg): bool => $arg['attempt'] === 1 && $arg['client_db_id'] === 1 && !array_key_exists('user_id', $arg))
        );

        $service = new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $jobList,
            $time,
        );
        $service->logout('user1');
    }

    /** @dataProvider successfulRetryStatusProvider */
    public function testSuccessfulRetryStatusDoesNotQueueRetry(int $status): void {
        $client = $this->newClient(1, 'client-1', 'https://8.8.8.8/logout');
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->willReturn($client);
        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->method('generateLogoutToken')->willReturn('token');
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('post')->willReturn($response);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->never())->method('scheduleAfter');
        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');

        $service = new BackChannelLogoutService(
            $this->createMock(ISession::class),
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $jobList,
            $this->createMock(ITimeFactory::class),
        );
        $service->retry(['client_db_id' => 1, 'sid' => 'sid-1', 'attempt' => 1]);
    }

    public function testRetryableFailureQueuesDelayedRetryAndStopsAfterLimit(): void {
        $session = $this->createMock(ISession::class);
        $client = $this->newClient(1, 'client-1', 'https://8.8.8.8/logout');
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->with(1)->willReturn($client);

        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->expects($this->exactly(2))
            ->method('generateLogoutToken')
            ->with($client, null, 'sid-1', 'https', 'nextcloud.example')
            ->willReturn('fresh-logout-token');

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(503);
        $httpClient = $this->createMock(IClient::class);
        $httpClient->expects($this->exactly(2))->method('post')->willReturn($response);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000);

        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())
            ->method('scheduleAfter')
            ->with(
                \OCA\OIDCIdentityProvider\BackgroundJob\BackChannelLogoutRetryJob::class,
                1120,
                $this->callback(static fn (array $arg): bool => $arg['attempt'] === 2 && $arg['sid'] === 'sid-1' && !array_key_exists('user_id', $arg))
            );

        $service = new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $jobList,
            $time,
        );

        $service->retry(['client_db_id' => 1, 'sid' => 'sid-1', 'attempt' => 1]);
        $service->retry(['client_db_id' => 1, 'sid' => 'sid-1', 'attempt' => 2]);
    }

    public function testNonRetryableClientErrorDoesNotQueueRetry(): void {
        $client = $this->newClient(1, 'client-1', 'https://8.8.8.8/logout');
        $clientMapper = $this->createMock(ClientMapper::class);
        $clientMapper->method('getByUid')->willReturn($client);
        $jwtGenerator = $this->createMock(JwtGenerator::class);
        $jwtGenerator->method('generateLogoutToken')->willReturn('token');
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(400);
        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('post')->willReturn($response);
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->never())->method('scheduleAfter');
        $request = $this->createMock(IRequest::class);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nextcloud.example');

        $service = new BackChannelLogoutService(
            $this->createMock(ISession::class),
            $this->createMock(ISecureRandom::class),
            $clientMapper,
            $jwtGenerator,
            $clientService,
            $request,
            $this->createMock(LoggerInterface::class),
            $jobList,
            $this->createMock(ITimeFactory::class),
        );

        $service->retry(['client_db_id' => 1, 'sid' => 'sid-1', 'attempt' => 1]);
    }

    public static function successfulRetryStatusProvider(): array {
        return [
            'HTTP 200' => [200],
            'HTTP 204' => [204],
        ];
    }

    public function testReauthenticationPreservesRpSidStateAndSuppressesFanout(): void {
        $state = ['oidc_backchannel_sessions_v2' => ['7' => 'sid-7']];
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$state) { return $state[$key] ?? null; });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$state): void {
            $state[$key] = $value;
        });
        $session->method('remove')->willReturnCallback(static function (string $key) use (&$state): void {
            unset($state[$key]);
        });

        $service = $this->newService($session);
        $snapshot = $service->prepareReauthentication('user-1');
        $this->assertSame(['user_id' => 'user-1', 'sessions' => ['7' => 'sid-7']], $snapshot);

        // Listener invocation during IUserSession::logout() must not fan out.
        $service->logout('user-1');
        $this->assertSame(['7' => 'sid-7'], $state['oidc_backchannel_sessions_v2']);

        // Simulate Nextcloud clearing the PHP session. Keep only pending state;
        // the active sid map is restored only after the same user authenticates.
        $state = [];
        $service->storePendingReauthentication($snapshot);
        $this->assertArrayNotHasKey('oidc_backchannel_sessions_v2', $state);
        $service->resumeAfterReauthentication('user-1');
        $this->assertSame(['7' => 'sid-7'], $state['oidc_backchannel_sessions_v2']);
        $this->assertArrayNotHasKey('oidc_backchannel_reauthentication_pending', $state);
    }

    public function testReauthenticationStateIsDiscardedWhenUserChanges(): void {
        $state = [];
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$state) { return $state[$key] ?? null; });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$state): void { $state[$key] = $value; });
        $session->method('remove')->willReturnCallback(static function (string $key) use (&$state): void { unset($state[$key]); });
        $service = $this->newService($session);

        $service->storePendingReauthentication(['user_id' => 'user-1', 'sessions' => ['7' => 'sid-7']]);
        $service->resumeAfterReauthentication('user-2');

        $this->assertArrayNotHasKey('oidc_backchannel_sessions_v2', $state);
        $this->assertArrayNotHasKey('oidc_backchannel_reauthentication_pending', $state);
    }

    public static function dynamicUriPolicyProvider(): array {
        return [
            'public IPv4 accepted' => ['https://8.8.8.8/logout', true],
            'public IPv6 accepted' => ['https://[2606:4700:4700::1111]/logout', true],
            'IPv4 loopback rejected' => ['https://127.0.0.1/logout', false],
            'IPv6 loopback rejected' => ['https://[::1]/logout', false],
            'RFC1918 10/8 rejected' => ['https://10.1.2.3/logout', false],
            'RFC1918 172.16/12 rejected' => ['https://172.20.1.2/logout', false],
            'RFC1918 192.168/16 rejected' => ['https://192.168.1.2/logout', false],
            'IPv4 link-local rejected' => ['https://169.254.1.2/logout', false],
            'IPv6 link-local rejected' => ['https://[fe80::1]/logout', false],
            'IPv6 ULA rejected' => ['https://[fd12:3456::1]/logout', false],
            'AWS/Azure/GCP metadata rejected' => ['https://169.254.169.254/latest/meta-data', false],
            'AWS IPv6 metadata rejected' => ['https://[fd00:ec2::254]/latest/meta-data', false],
            'Alibaba metadata rejected' => ['https://100.100.100.200/latest/meta-data', false],
            'Azure platform IP rejected' => ['https://168.63.129.16/metadata', false],
            'GCP metadata hostname rejected' => ['https://metadata.google.internal/computeMetadata/v1', false],
            'localhost rejected' => ['https://localhost/logout', false],
        ];
    }

    public static function uriValidationProvider(): array {
        return [
            'https confidential' => ['https://rp.example.com/backchannel-logout', 'confidential', true],
            'https public' => ['https://rp.example.com/backchannel-logout', 'public', true],
            'http confidential' => ['http://rp.example.com/backchannel-logout', 'confidential', true],
            'http public rejected' => ['http://rp.example.com/backchannel-logout', 'public', false],
            'fragment rejected' => ['https://rp.example.com/backchannel-logout#fragment', 'confidential', false],
            'userinfo rejected' => ['https://user:pass@rp.example.com/backchannel-logout', 'confidential', false],
            'relative rejected' => ['/backchannel-logout', 'confidential', false],
            'unsupported scheme rejected' => ['ftp://rp.example.com/backchannel-logout', 'confidential', false],
        ];
    }

    private function newClient(int $id, string $identifier, string $logoutUri): Client {
        $client = new Client('', [], 'RS256', 'confidential', 'code', 'opaque', '', '', false, false, null, $logoutUri, true);
        $client->setId($id);
        $client->setClientIdentifier($identifier);
        return $client;
    }

    private function newService(ISession $session): BackChannelLogoutService {
        return new BackChannelLogoutService(
            $session,
            $this->createMock(ISecureRandom::class),
            $this->createMock(ClientMapper::class),
            $this->createMock(JwtGenerator::class),
            $this->createMock(IClientService::class),
            $this->createMock(IRequest::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IJobList::class),
            $this->createMock(ITimeFactory::class),
        );
    }
}
