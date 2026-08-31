<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Service\BackChannelLogoutService;
use PHPUnit\Framework\TestCase;

class BackChannelLogoutServiceTest extends TestCase {
    /**
     * @dataProvider uriValidationProvider
     */
    public function testBackChannelLogoutUriValidation(string $uri, string $clientType, bool $expected): void {
        $this->assertSame(
            $expected,
            BackChannelLogoutService::isValidBackChannelLogoutUri($uri, $clientType)
        );
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
}
