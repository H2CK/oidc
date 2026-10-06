<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Service\ClaimPolicyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClaimPolicyServiceTest extends TestCase {
    public function testExplicitClaimsParticipateInConsentAndScopeCeilings(): void {
        $claims = ['email' => null, 'roles' => ['essential' => true], 'name' => null, 'phone_number' => null, 'address' => null];
        $scope = ClaimPolicyService::expandScopes('openid', $claims, ['email_verified' => null]);
        $this->assertSame('openid email roles profile phone address', $scope);
        $this->assertSame(['email' => null], ClaimPolicyService::filterRequests($claims, 'openid email'));
        $this->assertSame(['sub' => 'alice'], ClaimPolicyService::filterReleasedClaims([
            'sub' => 'alice', 'email' => 'private@example.test', 'roles' => ['admin'], 'preferred_username' => 'alice',
        ], 'openid'));
    }

    #[DataProvider('authenticationRequests')]
    public function testMandatoryAuthenticationClaimSemantics(array $requests, bool $expected): void {
        $this->assertSame($expected, ClaimPolicyService::authenticationClaimsSatisfied($requests, 'alice'));
    }

    public static function authenticationRequests(): array {
        return [
            'matching subject' => [['sub' => ['value' => 'alice']], true],
            'wrong subject even voluntary' => [['sub' => ['value' => 'bob', 'essential' => false]], false],
            'subject choice' => [['sub' => ['values' => ['bob', 'alice']]], true],
            'missing subject choice' => [['sub' => ['values' => ['bob']]], false],
            'essential available acr' => [['acr' => ['value' => '0', 'essential' => true]], true],
            'essential unavailable acr' => [['acr' => ['value' => 'urn:mfa', 'essential' => true]], false],
            'essential acr choices' => [['acr' => ['values' => ['urn:mfa', '0'], 'essential' => true]], true],
            'voluntary acr' => [['acr' => ['value' => 'urn:mfa']], true],
            'unknown essential remains optional' => [['unknown' => ['essential' => true]], true],
        ];
    }

    #[DataProvider('malformedRequests')]
    public function testMalformedAuthenticationClaimRequestsAreRejected(array $requests): void {
        $this->expectException(\InvalidArgumentException::class);
        ClaimPolicyService::validateAuthenticationRequests($requests);
    }

    public static function malformedRequests(): array {
        return [
            [['sub' => ['value' => 123]]], [['acr' => ['values' => []]]],
            [['sub' => ['values' => [true]]]], [['acr' => ['values' => ['key' => '0']]]],
            [['sub' => ['value' => 'alice', 'values' => ['alice']]]],
        ];
    }
}
