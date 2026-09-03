<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Service\FrontChannelLogoutContext;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class FrontChannelLogoutContextTest extends TestCase {
    public function testNoLogoutReturnsNull(): void {
        $request = $this->createMock(IRequest::class);
        $this->assertNull((new FrontChannelLogoutContext($request))->consumeLogoutUris());
    }

    public function testRealLogoutWithoutTargetsReturnsEmptyArrayOnce(): void {
        $request = $this->createMock(IRequest::class);
        $context = new FrontChannelLogoutContext($request);
        $context->recordLogout([]);
        $this->assertSame([], $context->consumeLogoutUris());
        $this->assertNull($context->consumeLogoutUris());
    }

    public function testMultipleSnapshotsAreMergedAndDeduplicated(): void {
        $request = $this->createMock(IRequest::class);
        $context = new FrontChannelLogoutContext($request);
        $context->recordLogout(['https://rp1.example/logout', 'https://rp2.example/logout']);
        $context->recordLogout(['https://rp2.example/logout', 'https://rp3.example/logout', '']);

        $this->assertSame([
            'https://rp1.example/logout',
            'https://rp2.example/logout',
            'https://rp3.example/logout',
        ], $context->consumeLogoutUris());
    }

    public function testDifferentContainerInstancesShareStateForTheSameRequest(): void {
        $request = $this->createMock(IRequest::class);
        $listenerSide = new FrontChannelLogoutContext($request);
        $middlewareSide = new FrontChannelLogoutContext($request);

        $listenerSide->recordLogout(['https://rp.example/logout']);
        $this->assertSame(['https://rp.example/logout'], $middlewareSide->consumeLogoutUris());
        $this->assertNull($listenerSide->consumeLogoutUris());
    }

    public function testDifferentRequestsDoNotShareState(): void {
        $requestA = $this->createMock(IRequest::class);
        $requestB = $this->createMock(IRequest::class);
        $contextA = new FrontChannelLogoutContext($requestA);
        $contextB = new FrontChannelLogoutContext($requestB);

        $contextA->recordLogout(['https://rp.example/logout']);
        $this->assertNull($contextB->consumeLogoutUris());
        $this->assertSame(['https://rp.example/logout'], $contextA->consumeLogoutUris());
    }
}
