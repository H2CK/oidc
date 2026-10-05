<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;

use Psr\Log\LoggerInterface;

class RedirectUriService {
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /** Enforce DCR application/flow transport rules in addition to URI syntax. */
    public function validateDynamicPolicy(string $uri, string $applicationType, string $clientType, array $grants): void {
        $this->isValidRedirectUri($uri, false, false);
        $parts = $this->parseUri($uri);
        $scheme = $parts['scheme'];
        $loopback = in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        if ($applicationType === 'web') {
            if (!in_array($scheme, ['http', 'https'], true)
                || ($scheme === 'http' && ($clientType === 'public' || in_array('implicit', $grants, true)))
                || (in_array('implicit', $grants, true) && $loopback)) {
                throw new RedirectUriValidationException('Web redirect URI is incompatible with its application type, authentication method or grant types.');
            }
        } elseif ($applicationType === 'native') {
            if ($scheme === 'https' || ($scheme === 'http' && !$loopback)) {
                throw new RedirectUriValidationException('OIDC native redirects must use a private-use scheme or an HTTP loopback URL.');
            }
            // Additional RFC 8252 constraint on private-use reverse-DNS schemes.
            if (!in_array($scheme, ['http', 'https'], true) && !str_contains($scheme, '.')) {
                throw new RedirectUriValidationException('Native private-use schemes must be based on a domain name under the client owner\'s control.');
            }
        } else {
            throw new RedirectUriValidationException('Unsupported application type.');
        }
    }

    /**
     * Verify redirect uri if it is valid according to OIDC specifications
     *
     * @param string $uri The redirect URI to validate
     * @param bool|null $allowSubdomainWildcards Whether subdomain wildcards are allowed
     * @return bool True if valid, false otherwise
     * @throws RedirectUriValidationException
     */
    public function isValidRedirectUri(
        string $uri,
        bool $allowSubdomainWildcards = false,
        bool $allowWildcards = true
    ): bool {
        if ($uri === '' || trim($uri) !== $uri || strlen($uri) > 2000
            || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            throw new RedirectUriValidationException('Redirect URI is empty, too long, or contains whitespace/control characters');
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $uri) === 1) {
            throw new RedirectUriValidationException('Redirect URI contains invalid percent encoding');
        }
        if (!$allowWildcards && str_contains($uri, '*')) {
            throw new RedirectUriValidationException('Wildcards are not allowed');
        }
        if (str_contains($uri, '#')) {
            throw new RedirectUriValidationException('Redirect URI must not contain a fragment');
        }

        $parts = $this->parseUri($uri);
        if ($parts === null) {
            throw new RedirectUriValidationException('Could not parse absolute redirect URI');
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $path = $parts['path'];
        $port = $parts['port'];
        if (in_array($scheme, ['javascript', 'data', 'file', 'vbscript'], true)) {
            throw new RedirectUriValidationException('Redirect URI scheme is not allowed');
        }
        if ($parts['has_userinfo']) {
            throw new RedirectUriValidationException('Redirect URI must not contain embedded credentials');
        }
        if (in_array($scheme, ['http', 'https'], true) && $host === '') {
            throw new RedirectUriValidationException('HTTP(S) redirect URI requires a host');
        }
        if ($host === '' && $path === '') {
            throw new RedirectUriValidationException('Custom-scheme redirect URI requires a host or path');
        }
        if ($host === 'localhost' && !in_array($scheme, ['http', 'https'], true)) {
            throw new RedirectUriValidationException('localhost redirect URI must use http or https');
        }

        if ($port === '*' && (!in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || !$allowWildcards || !in_array($scheme, ['http', 'https'], true))) {
            throw new RedirectUriValidationException('Port wildcard is allowed only for static HTTP(S) loopback redirect URIs');
        }

        if (str_starts_with($host, '*.')) {
            if (!$allowWildcards || !$allowSubdomainWildcards) {
                throw new RedirectUriValidationException('Subdomain wildcards are not allowed');
            }
            $baseHost = substr($host, 2);
            if (!filter_var($baseHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new RedirectUriValidationException('Invalid domain after subdomain wildcard');
            }
        } elseif (str_contains($host, '*')) {
            throw new RedirectUriValidationException('Invalid wildcard position in host');
        } elseif ($host !== ''
            && $host !== 'localhost'
            && !$this->isValidHost($host)) {
            throw new RedirectUriValidationException('Invalid redirect URI host');
        }

        if (str_contains($path, '*')) {
            if (!$allowWildcards || substr_count($path, '*') !== 1 || !str_ends_with($path, '/*')) {
                throw new RedirectUriValidationException('Path wildcard is allowed only once at the end of a static redirect URI path');
            }
            if ($parts['query'] !== null) {
                throw new RedirectUriValidationException('Path wildcards cannot be combined with a redirect URI query');
            }
        }
        $decodedPath = rawurldecode($path);
        if (in_array($scheme, ['http', 'https'], true) && str_contains($decodedPath, '\\')) {
            throw new RedirectUriValidationException('HTTP(S) redirect URI paths must not contain backslashes');
        }
        foreach (explode('/', str_replace('\\', '/', $decodedPath)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RedirectUriValidationException('Redirect URI paths must not contain dot segments');
            }
        }
        if ($parts['query'] !== null && str_contains($parts['query'], '*')) {
            throw new RedirectUriValidationException('Wildcards are not allowed in a redirect URI query');
        }

        return true;
    }

    /**
     * Match a concrete redirect URI against a stored wildcard pattern.
     *
     * Supported static-registration pattern features:
     *  - Leading host wildcard "*.example.com" requires at least one subdomain (e.g. sub.example.com).
     *  - Port wildcard ":*" allows any port or no port.
     *  - Exact port (e.g. ":8080") requires that port (or default port for http/https if omitted).
     *  - Path trailing wildcard "/*" allows any suffix (e.g. "/app/*").
     *  - App-specific schemes are supported and must match.
     *  - Query strings remain exact; fragments are rejected at registration.
     *
     * @param string $concreteUri The concrete redirect URI to check
     * @param string $wildcardPattern The stored wildcard pattern to match against
     * @return bool True if matches, false otherwise
     * @throws RedirectUriValidationException
     */
    public function matchRedirectUri(string $concreteUri, string $wildcardPattern, bool $nativeLoopback = false): bool {
        try {
            $this->isValidRedirectUri($concreteUri, false, false);
            $this->isValidRedirectUri($wildcardPattern, true, true);
        } catch (RedirectUriValidationException $e) {
            $this->logger->debug('Invalid redirect URI during matching', ['reason' => $e->getMessage()]);
            return false;
        }

        // OAuth's default rule is exact string comparison. Component matching
        // is used only for an explicitly configured static wildcard pattern.
        $loopbackPortException = false;
        if ($nativeLoopback) {
            $concreteParts = $this->parseUri($concreteUri);
            $patternParts = $this->parseUri($wildcardPattern);
            $loopbackPortException = $concreteParts !== null && $patternParts !== null
                && $patternParts['scheme'] === 'http'
                && in_array($patternParts['host'], ['127.0.0.1', '[::1]'], true)
                && $concreteParts['scheme'] === $patternParts['scheme']
                && $concreteParts['host'] === $patternParts['host'];
            if ($loopbackPortException && !str_contains($wildcardPattern, '*')) {
                // RFC 8252 changes only the port, not casing, paths or queries.
                $stripPort = static fn (string $value): string => (string)preg_replace(
                    '#^(http://(?:127\.0\.0\.1|\[::1\])):[0-9]+(?=/|\?|$)#', '$1', $value
                );
                return hash_equals($stripPort($wildcardPattern), $stripPort($concreteUri));
            }
        }
        if (!str_contains($wildcardPattern, '*')) {
            return hash_equals($wildcardPattern, $concreteUri);
        }

        $concrete = $this->parseUri($concreteUri);
        $pattern = $this->parseUri($wildcardPattern);
        if ($concrete === null || $pattern === null || $concrete['scheme'] !== $pattern['scheme']) {
            return false;
        }

        if (str_starts_with($pattern['host'], '*.')) {
            $baseHost = substr($pattern['host'], 2);
            if (!str_ends_with($concrete['host'], '.' . $baseHost)) {
                return false;
            }
            $hostPrefix = substr($concrete['host'], 0, -strlen($baseHost) - 1);
            if ($hostPrefix === '' || preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/i', $hostPrefix) !== 1) {
                return false;
            }
        } elseif ($concrete['host'] !== $pattern['host']) {
            return false;
        }

        if (!$loopbackPortException && $pattern['port'] !== '*'
            && $this->effectivePort($concrete['scheme'], $concrete['port'])
                !== $this->effectivePort($pattern['scheme'], $pattern['port'])) {
            return false;
        }

        if (str_ends_with($pattern['path'], '/*')) {
            $basePath = substr($pattern['path'], 0, -1);
            if (!str_starts_with($concrete['path'], $basePath)) {
                return false;
            }
        } elseif ($concrete['path'] !== $pattern['path']) {
            return false;
        }

        // A path wildcard never implicitly widens the registered query.
        return $concrete['query'] === $pattern['query'];
    }

    /**
     * @return array{scheme:string,host:string,port:int|string|null,path:string,query:?string,has_userinfo:bool}|null
     */
    private function parseUri(string $uri): ?array {
        $normalizedUri = preg_replace('#^([A-Za-z][A-Za-z0-9+.-]*):///#', '$1:/', $uri, 1);
        if (!is_string($normalizedUri)) {
            return null;
        }

        $portWildcard = preg_match('#^([A-Za-z][A-Za-z0-9+.-]*)://(?:localhost|127\.0\.0\.1|\[::1\]):\*(?=/|\?|$)#i', $normalizedUri) === 1;
        $parseableUri = $portWildcard
            ? preg_replace('#^([A-Za-z][A-Za-z0-9+.-]*://(?:localhost|127\.0\.0\.1|\[::1\])):\*#i', '$1:65535', $normalizedUri, 1)
            : $normalizedUri;
        if (!is_string($parseableUri)) {
            return null;
        }

        $parsed = parse_url($parseableUri);
        if ($parsed === false || !isset($parsed['scheme'])
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', (string)$parsed['scheme']) !== 1) {
            return null;
        }

        return [
            'scheme' => strtolower((string)$parsed['scheme']),
            'host' => strtolower((string)($parsed['host'] ?? '')),
            'port' => $portWildcard ? '*' : ($parsed['port'] ?? null),
            'path' => (string)($parsed['path'] ?? ''),
            'query' => array_key_exists('query', $parsed) ? (string)$parsed['query'] : null,
            'has_userinfo' => isset($parsed['user']) || isset($parsed['pass']),
        ];
    }

    private function effectivePort(string $scheme, int|string|null $port): int|string|null {
        if ($port !== null) {
            return $port;
        }
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    private function isValidHost(string $host): bool {
        // parse_url() retains brackets around IPv6 literals. filter_var()
        // expects the address itself, while DNS names must remain untouched.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

}
