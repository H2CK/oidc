<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCP\AppFramework\Services\IAppConfig;

/** RFC 8707 audiences must be explicitly authorized by the administrator. */
class ResourcePolicyService {
    public function __construct(private IAppConfig $appConfig) {
    }

    public static function isValid(string $resource, int $maxLength = 2000): bool {
        if ($resource === '' || strlen($resource) > $maxLength
            || preg_match('/[^A-Za-z0-9._~:\/?\[\]@!$&\x27()*+,;=%-]/', $resource) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $resource) === 1 || str_contains($resource, '#')) {
            return false;
        }
        $parts = parse_url($resource);
        return $parts !== false && isset($parts['scheme'])
            && preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*\z/D', $parts['scheme']) === 1
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!in_array(strtolower($parts['scheme']), ['http', 'https'], true) || filter_var($resource, FILTER_VALIDATE_URL) !== false)
            && preg_match('/[\[\]]/', ($parts['path'] ?? '') . ($parts['query'] ?? '')) !== 1
            && (isset($parts['host']) || !empty($parts['path']));
    }

    public function resolve(Client $client, ?string $resource): ?string {
        $resource ??= $client->getResourceUrl();
        if ($resource === null) {
            return null;
        }
        if (!self::isValid($resource) || !$this->isAllowed($client, $resource)) {
            throw new \InvalidArgumentException('The requested resource is not an approved absolute URI.');
        }
        return $resource;
    }

    public function isAllowed(Client $client, string $resource): bool {
        $approved = json_decode($this->appConfig->getAppValueString('approved_resources_' . $client->getId(), '[]'), true);
        if (!is_array($approved)) {
            $approved = [];
        }
        // Static registration is administrator-owned; DCR metadata is not.
        if (!$client->isDcr() && $client->getResourceUrl() !== null) {
            $approved[] = $client->getResourceUrl();
        }
        return in_array($resource, $approved, true);
    }

    /** Called only from an administrator-protected settings action. */
    public function approveDefault(Client $client): void {
        $resource = $client->getResourceUrl();
        $this->appConfig->setAppValueString('approved_resources_' . $client->getId(),
            json_encode($resource === null ? [] : [$resource], JSON_THROW_ON_ERROR));
    }
}
