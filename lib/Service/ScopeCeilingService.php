<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\GroupScopeMapper;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Per-group maximum scopes. Every code path that issues scopes (authorize,
 * token generation event, refresh, device grant, token exchange) routes
 * through clamp(), so the policy lives in exactly one place.
 *
 * ceiling(uid) = union of the scopes of the user's groups that have a row;
 * no limit when none of the user's groups has a row. DEFAULT_SCOPE is always
 * allowed so login never breaks.
 */
class ScopeCeilingService {

    public function __construct(
        private GroupScopeMapper $groupScopeMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Narrow a space-separated scope string to the user's group ceiling.
     * Returns the input unchanged when no ceiling applies, and '' when the
     * ceiling removes every requested scope.
     */
    public function clamp(string $uid, string $scopes, string $clientIdentifier = ''): string {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            return $scopes;
        }
        $rows = $this->groupScopeMapper->findByGroupIds($this->groupManager->getUserGroupIds($user));
        if ($rows === []) {
            return $scopes;
        }

        $allowed = self::split(Application::DEFAULT_SCOPE);
        foreach ($rows as $row) {
            $allowed = array_merge($allowed, self::split($row->getScopes() ?? ''));
        }
        // Exact match: OAuth scope values are case-sensitive (RFC 6749 3.3).
        $allowed = array_flip($allowed);

        $kept = [];
        $removed = [];
        foreach (self::split($scopes) as $scope) {
            if (isset($allowed[$scope])) {
                $kept[] = $scope;
            } else {
                $removed[] = $scope;
            }
        }

        if ($removed !== []) {
            $this->logger->info('Group scope ceiling removed scopes', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'client' => $clientIdentifier,
                'removed' => implode(' ', $removed),
            ]);
        }

        // May be '' — the caller decides (authorize falls back to DEFAULT_SCOPE,
        // token exchange rejects), so the ceiling itself never widens a scope.
        return implode(' ', $kept);
    }

    /**
     * Narrow a scope string to a client's allowed_scopes (empty = no limit).
     * Returns '' when nothing remains. Scope names are passed through
     * unchanged, but matched case-insensitively: that is how authorize and
     * the device flow have always checked allowed_scopes (they lowercase the
     * request), so a later re-check must not drop what they granted.
     */
    public function filterByAllowedScopes(string $scopes, string $allowedScopes): string {
        $allowed = array_flip(self::split(strtolower($allowedScopes)));
        $kept = [];
        foreach (array_unique(self::split($scopes)) as $scope) {
            if ($allowed === [] || isset($allowed[strtolower($scope)])) {
                $kept[] = $scope;
            }
        }

        return implode(' ', $kept);
    }

    /**
     * The client's allowed_scopes, then the user's group ceiling. For paths
     * that (re)issue a stored or supplied scope: the token generation event,
     * refresh and the device grant. May return '' -- the caller decides.
     */
    public function narrow(string $uid, string $scopes, string $allowedScopes, string $clientIdentifier = ''): string {
        return $this->clamp($uid, $this->filterByAllowedScopes($scopes, $allowedScopes), $clientIdentifier);
    }

    /**
     * @return string[]
     */
    private static function split(string $scopes): array {
        return preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
