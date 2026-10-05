<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Service\ConsentRequestService;
use OCP\ISession;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class ConsentRequestServiceTest extends TestCase {
    public function testIndependentTabsAreUserBoundSingleUseAndExpire(): void {
        $values = [];
        $now = 1000;
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(static function ($key) use (&$values) { return $values[$key] ?? null; });
        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$values): void { $values[$key] = $value; });
        $clock = $this->createMock(ITimeFactory::class);
        $clock->method('getTime')->willReturnCallback(static function () use (&$now): int { return $now; });
        $service = new ConsentRequestService($session, $clock);
        $first = $service->create('alice', ['client_id' => 'A', 'scope' => 'openid'], 'First', false);
        $second = $service->create('alice', ['client_id' => 'B', 'scope' => 'openid email', 'state' => 'B-state'], 'Second', true);
        $this->assertNotSame($first, $second);
        $this->assertNull($service->consume($first, 'bob'));
        $this->assertSame('A', $service->consume($first, 'alice')['parameters']['client_id']);
        $this->assertNull($service->consume($first, 'alice'));
        $this->assertSame('B-state', $service->get($second, 'alice')['parameters']['state']);
        $this->assertTrue($service->get($second, 'alice')['freshLogin']);
        $now += ConsentRequestService::TTL;
        $this->assertNull($service->consume($second, 'alice'));
        $this->assertNull($service->get(null, 'alice'));
    }
}
