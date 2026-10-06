<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Service\ResourcePolicyService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResourcePolicyServiceTest extends TestCase {
    #[DataProvider('resourceUris')]
    public function testAbsoluteResourceUriSyntax(string $uri, bool $expected): void {
        $this->assertSame($expected, ResourcePolicyService::isValid($uri));
    }

    public static function resourceUris(): array {
        return [
            ['https://api.example/path', true], ['urn:example:service', true],
            ['/relative', false], [' https://api.example', false], ['https://api.example#part', false],
            ['https://user:password@api.example/', false], ['https://api.example/%ZZ', false],
            ['https://', false], ["https://api.example/\n", false], ['https://api.example/\\x', false],
            ['https://api.example/' . str_repeat('a', 2000), false],
        ];
    }

    public function testDcrResourceMetadataDoesNotGrantAudienceAuthority(): void {
        $values = [];
        $config = $this->createMock(IAppConfig::class);
        $config->method('getAppValueString')->willReturnCallback(static function ($key, $default) use (&$values): string { return $values[$key] ?? $default; });
        $config->method('setAppValueString')->willReturnCallback(static function ($key, $value) use (&$values): bool { $values[$key] = $value; return true; });
        $policy = new ResourcePolicyService($config);
        $client = new Client();
        $client->setId(1);
        $client->setDcr(true);
        $client->setResourceUrl('https://api.example/');
        $this->assertFalse($policy->isAllowed($client, 'https://api.example/'));
        $policy->approveDefault($client);
        $this->assertSame('https://api.example/', $policy->resolve($client, null));
        $this->assertFalse($policy->isAllowed($client, 'https://attacker.example/'));
        $this->expectException(\InvalidArgumentException::class);
        $policy->resolve($client, 'https://attacker.example/');
    }

    public function testStaticDefaultIsTrustedButCannotAuthorizeAnotherAudience(): void {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getAppValueString')->willReturn('[]');
        $client = new Client();
        $client->setId(1);
        $client->setResourceUrl('urn:example:service');
        $policy = new ResourcePolicyService($config);
        $this->assertSame('urn:example:service', $policy->resolve($client, null));
        $this->expectException(\InvalidArgumentException::class);
        $policy->resolve($client, 'urn:example:other');
    }
}
