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
 * consent update, token generation event, refresh, token exchange) routes
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
        $allowed = array_flip(array_map('strtolower', $allowed));

        $kept = [];
        $removed = [];
        foreach (self::split($scopes) as $scope) {
            if (isset($allowed[strtolower($scope)])) {
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
     * Narrow a scope string to a client's allowed_scopes. An empty
     * allowed_scopes means no limit, and an empty result falls back to
     * DEFAULT_SCOPE. Shared by the authorize endpoint and the token
     * generation event so the rule exists once.
     */
    public function filterByAllowedScopes(string $scopes, string $allowedScopes): string {
        $allowed = self::split(strtolower($allowedScopes));
        $requested = array_unique(self::split(strtolower($scopes)));
        $kept = $allowed === [] ? $requested : array_intersect($requested, $allowed);

        return $kept === [] ? Application::DEFAULT_SCOPE : implode(' ', $kept);
    }

    /**
     * @return string[]
     */
    private static function split(string $scopes): array {
        return preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
