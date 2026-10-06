<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DynamicRedirectPolicyTest extends TestCase {
    #[DataProvider('policies')]
    public function testApplicationAndFlowConstraints(string $uri, string $application, string $clientType, array $grants, bool $allowed): void {
        $service = new RedirectUriService($this->createMock(LoggerInterface::class));
        if (!$allowed) {
            $this->expectException(RedirectUriValidationException::class);
        }
        $service->validateDynamicPolicy($uri, $application, $clientType, $grants);
        if ($allowed) {
            $this->addToAssertionCount(1);
        }
    }

    public static function policies(): array {
        return [
            ['https://rp.example/cb', 'web', 'public', ['authorization_code'], true],
            ['http://rp.example/cb', 'web', 'public', ['authorization_code'], false],
            ['http://rp.example/cb', 'web', 'confidential', ['authorization_code'], true],
            ['http://rp.example/cb', 'web', 'confidential', ['implicit'], false],
            ['https://localhost/cb', 'web', 'confidential', ['implicit'], false],
            ['com.example.app:/cb', 'web', 'public', ['authorization_code'], false],
            ['http://127.0.0.1:2345/cb', 'native', 'public', ['authorization_code'], true],
            ['http://[::1]:2345/cb', 'native', 'public', ['authorization_code'], true],
            ['http://rp.example/cb', 'native', 'public', ['authorization_code'], false],
            ['https://rp.example/cb', 'native', 'public', ['authorization_code'], false],
            ['com.example.app:/cb', 'native', 'public', ['authorization_code'], true],
            ['javascript:alert(1)', 'native', 'public', ['authorization_code'], false],
        ];
    }
}
