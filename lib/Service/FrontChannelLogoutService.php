<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class FrontChannelLogoutService {
    public function __construct(
        private ClientMapper $clientMapper,
        private IRequest $request,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /** @param array<string,string> $sessions @return list<string> */
    public function getLogoutUris(array $sessions): array {
        $issuer = $this->request->getServerProtocol() . '://' . $this->request->getServerHost() . $this->urlGenerator->getWebroot();
        $result = [];
        foreach ($sessions as $clientId => $sid) {
            if (!ctype_digit((string)$clientId) || !is_string($sid) || $sid === '') {
                continue;
            }
            try {
                $client = $this->clientMapper->getByUid((int)$clientId);
            } catch (\Throwable $e) {
                continue;
            }
            $uri = $client->getFrontchannelLogoutUri();
            if (!is_string($uri) || $uri === '') {
                continue;
            }
            if (!$this->isValidForClient($client, $uri)) {
                $this->logger->warning('Skipped invalid Front-Channel Logout URI.', ['client_id' => $client->getClientIdentifier()]);
                continue;
            }
            $separator = str_contains($uri, '?') ? '&' : '?';
            $result[] = $uri . $separator . 'iss=' . rawurlencode($issuer) . '&sid=' . rawurlencode($sid);
        }
        return array_values(array_unique($result));
    }

    public function isValidForClient(Client $client, string $uri): bool {
        return self::isValidForClientType($uri, $client->getType());
    }

    public static function isValidForClientType(string $uri, string $clientType): bool {
        if (!self::isStructurallyValid($uri)) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'])) {
            return false;
        }
        return strtolower((string)$parts['scheme']) !== 'http' || $clientType === 'confidential';
    }

    private static function isStructurallyValid(string $uri): bool {
        if ($uri === '' || strlen($uri) > 2000 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($uri);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            && !isset($parts['fragment'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
