<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class FrontChannelLogoutService {
    private const LOGOUT_IFRAME_GRACE_SECONDS = 3;

    public function __construct(
        private ClientMapper $clientMapper,
        private RedirectUriMapper $redirectUriMapper,
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
                // Runtime fail-closed validation also protects installations
                // that already contain legacy metadata created before origin
                // binding was enforced at registration/update time.
                $this->logger->warning('Skipped invalid Front-Channel Logout URI.', ['client_id' => $client->getClientIdentifier()]);
                continue;
            }
            $separator = str_contains($uri, '?') ? '&' : '?';
            $result[] = $uri . $separator . 'iss=' . rawurlencode($issuer) . '&sid=' . rawurlencode($sid);
        }
        return array_values(array_unique($result));
    }

    public function isValidForClient(Client $client, string $uri): bool {
        $redirectUris = array_map(
            static fn ($entry): string => $entry->getRedirectUri(),
            $this->redirectUriMapper->getByClientId($client->getId())
        );
        return self::isValidForRedirectUris($uri, $client->getType(), $redirectUris);
    }

    /** @param list<string> $redirectUris */
    public static function isValidForRedirectUris(string $uri, string $clientType, array $redirectUris): bool {
        if (!self::isValidForClientType($uri, $clientType)) {
            return false;
        }
        $logoutOrigin = SessionManagementService::getOrigin($uri);
        if ($logoutOrigin === null) {
            return false;
        }
        foreach ($redirectUris as $redirectUri) {
            if (is_string($redirectUri) && SessionManagementService::getOrigin($redirectUri) === $logoutOrigin) {
                return true;
            }
        }
        return false;
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

    /**
     * Render the browser fan-out required by Front-Channel Logout. The short
     * grace period is deliberately longer than the former one-second redirect
     * and gives all hidden RP iframes a realistic chance to receive their GET.
     *
     * @param list<string> $frontChannelUris
     */
    public function createBrowserLogoutResponse(array $frontChannelUris, string $redirectUrl): Response {
        if ($frontChannelUris === []) {
            return new RedirectResponse($redirectUrl);
        }

        $frames = '';
        $frameOrigins = [];
        foreach ($frontChannelUris as $uri) {
            $frames .= '<iframe src="' . htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="display:none" aria-hidden="true"></iframe>';
            $origin = SessionManagementService::getOrigin($uri);
            if ($origin !== null) {
                $frameOrigins[$origin] = true;
            }
        }
        if ($frameOrigins === []) {
            return new RedirectResponse($redirectUrl);
        }

        $escapedRedirect = htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="'
            . self::LOGOUT_IFRAME_GRACE_SECONDS . ';url=' . $escapedRedirect
            . '"><title>Logout</title></head><body>' . $frames . '</body></html>';
        $response = new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Content-Security-Policy', "default-src 'none'; frame-src " . implode(' ', array_keys($frameOrigins)) . "; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        return $response;
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
