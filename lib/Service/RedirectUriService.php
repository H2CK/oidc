<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;

use Psr\Log\LoggerInterface;

class RedirectUriService {

    private const INVALID_REDIRECT_URI = 'Invalid redirect URI retrieved ';

    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Verify redirect uri if it is valid according to OIDC specifications
     *
     * @param string $uri The redirect URI to validate
     * @param bool|null $allowSubdomainWildcards Whether subdomain wildcards are allowed
     * @return bool True if valid, false otherwise
     * @throws RedirectUriValidationException
     */
    public function isValidRedirectUri(string $uri, bool $allowSubdomainWildcards = false): bool {
        if (strpos($uri, 'localhost:*/') !== false) {
            $host = 'localhost';
            $path = substr($uri, strpos($uri, 'localhost:*/') + 11);
            $port = '*';
            if (strpos($uri, 'https://') === 0) {
                $scheme = 'https';
            } elseif (strpos($uri, 'http://') === 0) {
                $scheme = 'http';
            } else {
                $scheme = null;
                $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                        'app' => 'oidc',
                        'redirect_uri' => $uri,
                        'reason' => 'Invalid or missing scheme for localhost with port wildcard',
                    ]);
                throw new RedirectUriValidationException('Invalid or missing scheme for localhost');
            }
        } else {
            if (strpos($uri, ':///') !== false) {
                // Handle scheme-only URIs like "app.immich:///oauth-callback"
                $parts = explode(':///', $uri, 2);
                $scheme = $parts[0];
                $host = '';
                $path = '/' . ltrim($parts[1], '/');
                $port = null;
            } else {
                $parsed = parse_url($uri);
                if ($parsed === false) {
                    $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                        'app' => 'oidc',
                        'redirect_uri' => $uri,
                        'reason' => 'Could not parse URL',
                    ]);
                    throw new RedirectUriValidationException('Could not parse URL or missing host');
                }

                $scheme = $parsed['scheme'] ?? null;
                $host = $parsed['host'] ?? '';
                $path = $parsed['path'] ?? '';
                $port = $parsed['port'] ?? null;
            }

            // Accept app-specific schemes like "app.immich:///oauth-callback" (no host, path-only)
            if ($host === '' && ($scheme === null || $path === '')) {
                $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                    'app' => 'oidc',
                    'redirect_uri' => $uri,
                    'reason' => 'Could not parse URL or missing host/path for scheme-only URI',
                ]);
                throw new RedirectUriValidationException('Could not parse URL or missing host/path for scheme-only URI');
            }
        }

        if ($host === 'localhost' && !($scheme === 'http' || $scheme === 'https')) {
            $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                'app' => 'oidc',
                'redirect_uri' => $uri,
                'reason' => 'Invalid scheme for localhost, must be http or https',
            ]);
            throw new RedirectUriValidationException('Invalid scheme for localhost, must be http or https');
        }

        // Check for Port-Wildcard (only for localhost allowed)
        if ($port !== null && $host !== 'localhost' && $port === '*') {
            $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                'app' => 'oidc',
                'redirect_uri' => $uri,
                'reason' => 'Wildcard port only allowed for localhost',
            ]);
            throw new RedirectUriValidationException('Wildcard port only allowed for localhost');
        }

        // Check for Subdomain-Wildcard (optional)
        if (strpos($host, '*.') === 0) {
            if (!$allowSubdomainWildcards) {
                $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                    'app' => 'oidc',
                    'redirect_uri' => $uri,
                    'reason' => 'Not allowed to use subdomain wildcards',
                ]);
                throw new RedirectUriValidationException('Not allowed to use subdomain wildcards');
            }
            // Check if rest of domain is valid (e.g. *.example.com)
            $subdomainWildcardPart = substr($host, 2);
            if (!filter_var($subdomainWildcardPart, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                    'app' => 'oidc',
                    'redirect_uri' => $uri,
                    'reason' => 'Invalid domain part after subdomain wildcard',
                ]);
                throw new RedirectUriValidationException('Invalid domain part after subdomain wildcard');
            }
        }

        // Check for path-Wildcard (only at the end allowed)
        if (strpos($path, '*') !== false) {
            if (substr($path, -2) !== '/*') {
                $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                    'app' => 'oidc',
                    'redirect_uri' => $uri,
                    'reason' => 'Wildcard only allowed at the end of the path',
                ]);
                throw new RedirectUriValidationException('Wildcard only allowed at the end of the path');
            }
            $pathWithoutWildcard = rtrim($path, '*');
            if (!preg_match('#^[/a-zA-Z0-9\-_]*$#', $pathWithoutWildcard)) {
                $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                    'app' => 'oidc',
                    'redirect_uri' => $uri,
                    'reason' => 'Invalid characters in path before wildcard',
                ]);
                throw new RedirectUriValidationException('Invalid characters in path before wildcard');

            }
        }

        // Check if domain is valid (no wildcards except the ones allowed above)
        if (strpos($host, '*') !== false && strpos($host, '*.') !== 0) {
            $this->logger->info(RedirectUriService::INVALID_REDIRECT_URI, [
                'app' => 'oidc',
                'redirect_uri' => $uri,
                'reason' => 'Invalid wildcard position in domain',
            ]);
            throw new RedirectUriValidationException('Invalid wildcard position in domain');
        }

        return true;
    }

    /**
     * Match a concrete redirect URI against a stored wildcard pattern.
     *
     * Supported pattern features:
     *  - Leading host wildcard "*.example.com" requires at least one subdomain (e.g. sub.example.com).
     *  - Host "*" matches any host.
     *  - Port wildcard ":*" allows any port or no port.
     *  - Exact port (e.g. ":8080") requires that port (or default port for http/https if omitted).
     *  - Path trailing wildcard "/*" allows any suffix (e.g. "/app/*").
     *  - Scheme may be any string (app-specific schemes allowed). If the pattern defines a scheme
     *    and the concrete URI defines a scheme they must match (case-insensitive). If the pattern
     *    omits a scheme any concrete scheme is accepted.
     *
     * Notes:
     *  - Query and fragment are ignored for matching.
     *
     * @param string $concreteUri The concrete redirect URI to check
     * @param string $wildcardPattern The stored wildcard pattern to match against
     * @return bool True if matches, false otherwise
     * @throws RedirectUriValidationException
     */
    public function matchRedirectUri(string $concreteUri, string $wildcardPattern): bool {
        if (strpos($concreteUri, ':///') !== false) {
            // Handle scheme-only URIs like "app.immich:///oauth-callback"
            $parts = explode(':///', $concreteUri, 2);
            $concreteScheme = isset($parts[0]) ? strtolower($parts[0]) : null;
            $concreteHost = '';
            $concretePath = '/' . ltrim($parts[1], '/');
            $concretePort = null;
        } else {
            // Parse concrete URI
            $concrete = parse_url($concreteUri);
            if ($concrete === false || !isset($concrete['host'])) {
                $this->logger->debug('Invalid concrete redirect URI', ['concreteUri' => $concreteUri]);
                return false;
            }
            $concreteScheme = isset($concrete['scheme']) ? strtolower($concrete['scheme']) : null;
            $concreteHost = strtolower($concrete['host']);
            $concretePort = isset($concrete['port']) ? (int)$concrete['port'] : null;
            $concretePath = $concrete['path'] ?? '/';
            $concretePath = '/' . ltrim(preg_replace('#/+#', '/', $concretePath), '/');
            if (isset($concrete['query'])) {
                $concretePath = rtrim($concretePath, '/') . '?'. $concrete['query'];
            }
            if (isset($concrete['fragment'])) {
                $concretePath = rtrim($concretePath, '/') . '#'. $concrete['fragment'];
            }
        }

        // Parse wildcard pattern: allow optional scheme
        if (!preg_match('#^(?:([a-zA-Z][a-zA-Z0-9+\-.]*):\/\/)?([^\/]*)(\/.*)?$#', $wildcardPattern, $m)) {
            $this->logger->debug('Invalid wildcard pattern format', ['pattern' => $wildcardPattern]);
            return false;
        }
        $patternScheme = isset($m[1]) ? strtolower($m[1]) : null;
        $authority = $m[2];
        $patternPath = $m[3] ?? '/';
        $patternPath = '/' . ltrim(preg_replace('#/+#', '/', $patternPath), '/');

        // remove userinfo if present
        $authority = preg_replace('/^.*@/', '', $authority);

        // Extract host and port from authority (support IPv6 in brackets)
        $patternPort = null;
        $patternHostRaw = $authority;
        if (preg_match('/^\[([^\]]+)\](?::(.+))?$/', $authority, $pm)) {
            // IPv6
            $patternHostRaw = $pm[1];
            $patternPort = $pm[2] ?? null;
        } else {
            // split last ":" only if it's a numeric port or "*" wildcard
            $pos = strrpos($authority, ':');
            if ($pos !== false) {
                $maybePort = substr($authority, $pos + 1);
                if ($maybePort === '*' || preg_match('/^\d+$/', $maybePort)) {
                    $patternHostRaw = substr($authority, 0, $pos);
                    $patternPort = $maybePort;
                }
            }
        }
        $patternHost = strtolower((string) $patternHostRaw);

        // Scheme check: if both defined, require equality
        if ($patternScheme !== null && $concreteScheme !== null && $patternScheme !== $concreteScheme) {
            $this->logger->debug('Schemes do not match', ['pattern' => $patternScheme, 'concrete' => $concreteScheme]);
            return false;
        }

        // Host matching
        $hostMatches = false;
        if ($patternHost === '*') {
            $hostMatches = true;
        } elseif ($patternHost === '') {
            // pattern has no host (scheme-only URI)
            if ($concreteHost === '') {
                $hostMatches = true;
            }
        } elseif (strpos($patternHost, '*.') === 0) {
            $base = substr($patternHost, 2);
            if ($base === '') {
                return false; // invalid pattern
            }
            // must end with ".base" and have at least one label before it
            if (str_ends_with($concreteHost, '.' . $base)) {
                $prefix = substr($concreteHost, 0, -strlen($base) - 1);
                if ($prefix !== '' && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)*$#i', $prefix) === 1) {
                    $hostMatches = true;
                }
            }
        } else {
            // exact match
            if ($concreteHost === $patternHost) {
                $hostMatches = true;
            }
        }
        if (!$hostMatches) {
            $this->logger->debug('Hosts do not match', ['patternHost' => $patternHost, 'concreteHost' => $concreteHost]);
            return false;
        }

        // Port matching
        if ($patternPort !== null) {
            if ($patternPort === '*') {
                // any port or none is allowed
            } else {
                if (!ctype_digit((string)$patternPort)) {
                    $this->logger->debug('Invalid pattern port', ['patternPort' => $patternPort]);
                    return false;
                }
                $expectedPort = (int)$patternPort;
                if ($concretePort === null) {
                    // consider default ports for http/https only
                    if ($concreteScheme === 'http' && $expectedPort === 80) {
                        // ok
                    } elseif ($concreteScheme === 'https' && $expectedPort === 443) {
                        // ok
                    } else {
                        $this->logger->debug('Concrete URI missing port and default does not match', [
                            'expected' => $expectedPort,
                            'scheme' => $concreteScheme,
                        ]);
                        return false;
                    }
                } else {
                    if ($concretePort !== $expectedPort) {
                        $this->logger->debug('Ports do not match', ['expected' => $expectedPort, 'concrete' => $concretePort]);
                        return false;
                    }
                }
            }
        }
        // If pattern has no port -> accept any concrete port (including none)

        // Path matching
        if (str_ends_with($patternPath, '/*')) {
            $basePath = substr($patternPath, 0, -1); // keep trailing slash
            if ($basePath === '') {
                $basePath = '/';
            }
            if (!str_starts_with($concretePath, $basePath)) {
                $this->logger->debug('Path prefix does not match', ['base' => $basePath, 'concrete' => $concretePath]);
                return false;
            }
        } else {
            // exact path match (trailing slash significant)
            if ($concretePath !== $patternPath) {
                $this->logger->debug('Paths do not match', ['patternPath' => $patternPath, 'concretePath' => $concretePath]);
                return false;
            }
        }

        return true;
    }

}
