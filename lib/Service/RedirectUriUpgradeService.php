<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

/** Preserve known, same-origin variants as separate exact registrations. */
class RedirectUriUpgradeService {
    public function __construct(private RedirectUriService $redirectUris) {
    }

    /** @return list<string> */
    public function compatibilityAliases(string $uri, bool $dcr): array {
        $this->redirectUris->isValidRedirectUri($uri, true, !$dcr);
        if ($dcr || str_contains($uri, '*')) {
            return [];
        }
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['host'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return [];
        }
        $authority = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $canonical = $authority . $path . $query;
        $aliases = [$canonical];
        if ($path === '' || $path === '/') {
            $alternatePath = $path === '' ? '/' : '';
            $aliases[] = $authority . $alternatePath . $query;
            // Keep the originally registered authority's spelling, too.
            $originalAuthority = substr($uri, 0, strlen($uri) - strlen($path . $query));
            $aliases[] = $originalAuthority . $alternatePath . $query;
        }
        $aliases = array_values(array_diff(array_unique($aliases), [$uri]));
        foreach ($aliases as $alias) {
            $this->redirectUris->isValidRedirectUri($alias, false, false);
        }
        return $aliases;
    }
}
