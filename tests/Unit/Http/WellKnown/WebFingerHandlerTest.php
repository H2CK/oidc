<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Http\WellKnown;

use OCA\OIDCIdentityProvider\Http\WellKnown\WebFingerHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\JrdResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class WebFingerHandlerTest extends TestCase {
    private string $resource = 'acct:alice@cloud.example';
    private WebFingerHandler $handler;
    private IRequestContext $context;

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $name === 'resource' ? $this->resource : null);
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('cloud.example');
        $this->context = $this->createMock(IRequestContext::class);
        $this->context->method('getHttpRequest')->willReturn($request);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('getWebroot')->willReturn('/nextcloud');
        $users = $this->createMock(IUserManager::class);
        $user = $this->createMock(IUser::class);
        $users->method('get')->willReturnCallback(static fn (string $uid) => in_array($uid, ['alice', 'alice@example.net'], true) ? $user : null);
        $this->handler = new WebFingerHandler($urls, $users);
    }

    public function testKnownAccountPreservesTheCompleteResourceSubject(): void {
        $response = $this->handler->handle('webfinger', $this->context, null);
        $this->assertInstanceOf(JrdResponse::class, $response);
        $this->assertSame($this->resource, $response->toHttpResponse()->getData()['subject']);
    }

    public function testUnrelatedAndUnknownResourcesKeepThePreviousResponse(): void {
        $previous = new JrdResponse('acct:previous@cloud.example');
        foreach (['acct:alice@foreign.example', 'acct:unknown@cloud.example', 'https://foreign.example/', 'alice@cloud.example'] as $resource) {
            $this->resource = $resource;
            $this->assertSame($previous, $this->handler->handle('webfinger', $this->context, $previous));
        }
    }

    public function testPercentEncodedLocalAccountNameStillPreservesSubject(): void {
        $this->resource = 'acct:alice%40example.net@cloud.example';
        $response = $this->handler->handle('webfinger', $this->context, null);
        $this->assertInstanceOf(JrdResponse::class, $response);
        $this->assertSame($this->resource, $response->toHttpResponse()->getData()['subject']);
    }
}
