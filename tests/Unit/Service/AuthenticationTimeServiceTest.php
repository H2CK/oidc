<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Service\AuthenticationTimeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class AuthenticationTimeServiceTest extends TestCase {
    private array $values = [];
    private int $now = 1000;
    private string $uid = 'alice';
    private AuthenticationTimeService $service;

    protected function setUp(): void {
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(fn (string $key) => $this->values[$key] ?? null);
        $session->method('set')->willReturnCallback(function (string $key, $value): void { $this->values[$key] = $value; });
        $session->method('remove')->willReturnCallback(function (string $key): void { unset($this->values[$key]); });
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturnCallback(fn (): string => $this->uid);
        $users = $this->createMock(IUserSession::class);
        $users->method('getUser')->willReturn($user);
        $this->service = new AuthenticationTimeService($session, $clock, $users);
    }

    public function testReadingAnOldSessionDoesNotInventALoginTime(): void {
        $this->values['oidc_auth_time'] = 1000;
        $this->assertNull($this->service->getAuthenticationTime());
        $this->assertArrayNotHasKey(AuthenticationTimeService::TIME_KEY, $this->values);
    }

    public function testAuthorizationAndContinuationDoNotAdvanceAuthenticationTime(): void {
        $this->service->recordLogin('alice');
        $this->now = 8000;
        $this->assertSame(1000, $this->service->getAuthenticationTime());
        $this->assertFalse($this->service->authenticatedSince(7000));
    }

    public function testAnotherAccountCannotReuseAuthenticationEvidence(): void {
        $this->service->recordLogin('alice');
        $this->uid = 'bob';
        $this->assertNull($this->service->getAuthenticationTime());
    }

    public function testCookieRestorationClearsEvidenceAndNewLoginRecordsNewTime(): void {
        $this->service->recordLogin('alice');
        $this->service->clear();
        $this->assertNull($this->service->getAuthenticationTime());
        $this->now = 8000;
        $this->service->recordLogin('alice');
        $this->assertTrue($this->service->authenticatedSince(7999));
        $this->assertSame(8000, $this->service->getAuthenticationTime());
    }

    public function testFutureSessionTimeCannotBypassMaxAge(): void {
        $this->service->recordLogin('alice');
        $this->now = 999;
        $this->assertNull($this->service->getAuthenticationTime());
    }
}
