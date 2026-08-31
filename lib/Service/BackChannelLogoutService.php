<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class BackChannelLogoutService {
    private const SESSION_KEY = 'oidc_backchannel_sessions';

    public function __construct(
        private ISession $session,
        private ISecureRandom $secureRandom,
        private ClientMapper $clientMapper,
        private JwtGenerator $jwtGenerator,
        private IClientService $clientService,
        private IRequest $request,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Register an RP as participating in the current OP browser session and
     * return the stable, RP-visible sid for this OP-session/client pair.
     */
    public function registerClientSession(Client $client): string {
        $sessions = $this->session->get(self::SESSION_KEY);
        if (!is_array($sessions)) {
            $sessions = [];
        }

        $key = (string)$client->getId();
        if (isset($sessions[$key]) && is_string($sessions[$key]) && $sessions[$key] !== '') {
            return $sessions[$key];
        }

        $sid = $this->secureRandom->generate(
            64,
            ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS
        );
        $sessions[$key] = $sid;
        $this->session->set(self::SESSION_KEY, $sessions);

        return $sid;
    }

    /**
     * Send one Back-Channel Logout request to every participating RP that
     * currently has a backchannel_logout_uri configured.
     */
    public function logout(?string $userId): void {
        $sessions = $this->session->get(self::SESSION_KEY);
        if (!is_array($sessions) || $sessions === []) {
            return;
        }

        // Clear before doing network I/O to prevent duplicate sends if logout
        // is re-entered or triggered again while the session is being torn down.
        $this->session->remove(self::SESSION_KEY);
        $httpClient = $this->clientService->newClient();

        foreach ($sessions as $clientId => $sid) {
            if (!is_string($sid) || $sid === '' || !ctype_digit((string)$clientId)) {
                continue;
            }

            try {
                $client = $this->clientMapper->getByUid((int)$clientId);
                $logoutUri = trim((string)($client->getBackchannelLogoutUri() ?? ''));
                if ($logoutUri === '') {
                    continue;
                }
                if (!self::isValidBackChannelLogoutUri($logoutUri, $client->getType())) {
                    $this->logger->warning('Skipped invalid Back-Channel Logout URI for client.', [
                        'client_id' => $client->getClientIdentifier(),
                    ]);
                    continue;
                }

                $logoutToken = $this->jwtGenerator->generateLogoutToken(
                    $client,
                    $userId,
                    $sid,
                    $this->request->getServerProtocol(),
                    $this->request->getServerHost()
                );

                $response = $httpClient->post($logoutUri, [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['logout_token' => $logoutToken],
                    'timeout' => 5,
                    'allow_redirects' => false,
                ]);

                $status = $response->getStatusCode();
                if ($status !== 200 && $status !== 204) {
                    $this->logger->warning('Back-Channel Logout endpoint returned an unexpected status.', [
                        'client_id' => $client->getClientIdentifier(),
                        'status' => $status,
                    ]);
                }
            } catch (\Throwable $e) {
                // A failing RP must not prevent the Nextcloud session from being
                // logged out or prevent logout notifications to other RPs.
                $this->logger->warning('Back-Channel Logout request failed.', [
                    'client_db_id' => (string)$clientId,
                    'exception' => $e,
                ]);
            }
        }
    }

    public static function isValidBackChannelLogoutUri(string $uri, string $clientType): bool {
        if ($uri === '' || mb_strlen($uri) > 2000 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && $clientType === 'confidential';
    }
}
