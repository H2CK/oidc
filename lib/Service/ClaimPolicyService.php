<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Service;

/** Explicit claim requests use the same authorization and consent as scopes. */
class ClaimPolicyService {
    private const SCOPES = [
        'email' => 'email', 'email_verified' => 'email',
        'roles' => 'roles', 'groups' => 'groups',
        'name' => 'profile', 'given_name' => 'profile', 'family_name' => 'profile',
        'middle_name' => 'profile', 'nickname' => 'profile', 'preferred_username' => 'profile',
        'profile' => 'profile', 'picture' => 'profile', 'website' => 'profile',
        'gender' => 'profile', 'birthdate' => 'profile', 'zoneinfo' => 'profile',
        'locale' => 'profile', 'updated_at' => 'profile', 'quota' => 'profile',
        'phone_number' => 'phone', 'phone_number_verified' => 'phone', 'address' => 'address',
    ];

    public static function expandScopes(string $scope, array ...$requests): string {
        $scopes = preg_split('/ +/', trim($scope), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($requests as $claims) {
            foreach (array_keys($claims) as $name) {
                if (isset(self::SCOPES[$name])) {
                    $scopes[] = self::SCOPES[$name];
                }
            }
        }
        return implode(' ', array_values(array_unique($scopes)));
    }

    public static function filterRequests(array $requests, string $scope): array {
        $scopes = explode(' ', $scope);
        return array_filter($requests, static fn ($name): bool =>
            !isset(self::SCOPES[$name]) || in_array(self::SCOPES[$name], $scopes, true), ARRAY_FILTER_USE_KEY);
    }

    public static function filterReleasedClaims(array $claims, string $scope): array {
        return self::filterRequests($claims, $scope);
    }

    /** Validate the two OIDC authentication claims with special mandatory semantics. */
    public static function validateAuthenticationRequests(array $requests): void {
        foreach (['sub', 'acr'] as $name) {
            $request = $requests[$name] ?? null;
            if (!is_array($request)) {
                continue;
            }
            if (array_key_exists('value', $request) && !is_string($request['value'])) {
                throw new \InvalidArgumentException($name . '.value must be a string');
            }
            if (array_key_exists('values', $request) && (!is_array($request['values'])
                || !array_is_list($request['values']) || $request['values'] === []
                || array_filter($request['values'], static fn ($value): bool => !is_string($value)) !== [])) {
                throw new \InvalidArgumentException($name . '.values must be a nonempty list of strings');
            }
            if (array_key_exists('value', $request) && array_key_exists('values', $request)) {
                throw new \InvalidArgumentException('Use value or values, not both');
            }
        }
    }

    public static function authenticationClaimsSatisfied(array $requests, string $subject, string $acr = '0'): bool {
        self::validateAuthenticationRequests($requests);
        foreach (['sub' => $subject, 'acr' => $acr] as $name => $actual) {
            $request = $requests[$name] ?? null;
            if (!is_array($request) || ($name === 'acr' && ($request['essential'] ?? false) !== true)) {
                continue;
            }
            if (array_key_exists('value', $request) && $request['value'] !== $actual) {
                return false;
            }
            if (isset($request['values']) && !in_array($actual, $request['values'], true)) {
                return false;
            }
        }
        return true;
    }
}
