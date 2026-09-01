<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCA\OIDCIdentityProvider\BackgroundJob\BackChannelLogoutRetryJob;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RecentSessionMapper;
use OCA\OIDCIdentityProvider\Util\JwtGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class BackChannelLogoutService {
    /**
     * Version the browser-session state so an upgrade cannot silently reuse a
     * sid that was created before the tightened RP-Initiated Logout checks.
     */
    private const SESSION_KEY = 'oidc_backchannel_sessions_v2';
    private const LEGACY_SESSION_KEY = 'oidc_backchannel_sessions';
    private const REAUTHENTICATION_SUPPRESS_KEY = 'oidc_backchannel_reauthentication_suppress';
    private const REAUTHENTICATION_PENDING_KEY = 'oidc_backchannel_reauthentication_pending';
    /** How long a genuinely logged-out OP/RP sid is considered recent. */
    public const RECENT_SESSION_TTL = 600;

    /** Two retries after the initial request. */
    private const MAX_RETRY_ATTEMPTS = 2;
    /** @var array<int, int> Retry number => minimum delay in seconds. */
    private const RETRY_DELAYS = [
        1 => 30,
        2 => 120,
    ];

    /** @var list<string> */
    private const BLOCKED_METADATA_HOSTNAMES = [
        'metadata.google.internal',
        'metadata.goog',
        'instance-data.ec2.internal',
    ];

    /** @var list<string> */
    private const BLOCKED_METADATA_IPS = [
        '169.254.169.254', // AWS, Azure, GCP and OCI IMDS
        '169.254.170.2',   // AWS ECS task metadata
        '100.100.100.200', // Alibaba Cloud metadata
        '168.63.129.16',   // Azure platform virtual IP
        'fd00:ec2::254',   // AWS EC2 IPv6 IMDS
    ];

    public function __construct(
        private ISession $session,
        private ISecureRandom $secureRandom,
        private ClientMapper $clientMapper,
        private RecentSessionMapper $recentSessionMapper,
        private JwtGenerator $jwtGenerator,
        private IClientService $clientService,
        private IRequest $request,
        private LoggerInterface $logger,
        private IJobList $jobList,
        private ITimeFactory $time,
    ) {
    }

    /**
     * Register an RP as participating in the current OP browser session and
     * return the stable, RP-visible sid for this OP-session/client pair.
     */
    public function registerClientSession(Client $client): string {
        $this->session->remove(self::LEGACY_SESSION_KEY);

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
     * Check that an ID Token sid identifies the RP session registered for the
     * current OP browser session.
     */
    public function isCurrentClientSession(Client $client, ?string $sid): bool {
        if ($sid === null || $sid === '') {
            return false;
        }

        $sessions = $this->session->get(self::SESSION_KEY);
        if (!is_array($sessions)) {
            return false;
        }

        $currentSid = $sessions[(string)$client->getId()] ?? null;
        return is_string($currentSid)
            && $currentSid !== ''
            && hash_equals($currentSid, $sid);
    }


    /**
     * Check whether an RP/user/sid tuple was part of an OP browser session
     * that was genuinely logged out within the recent-session window.
     *
     * A missing sid is supported for older ID Tokens by matching a recent
     * session for the same RP and user. New 2.2.0 ID Tokens always carry sid.
     */
    public function isRecentClientSession(Client $client, string $userId, ?string $sid): bool {
        if ($userId === '') {
            return false;
        }

        $notBefore = $this->time->getTime() - self::RECENT_SESSION_TTL;
        try {
            return $this->recentSessionMapper->isRecent(
                $userId,
                $client->getClientIdentifier(),
                $sid,
                $notBefore,
            );
        } catch (\Throwable $e) {
            // Recent-session correlation is a security check. If its persistence
            // is unavailable, fail closed rather than accepting an expired or
            // otherwise uncorrelated ID Token hint.
            $this->logger->warning('Could not check recent OIDC logout session correlation.', [
                'client_id' => $client->getClientIdentifier(),
                'exception' => $e,
            ]);
            return false;
        }
    }


    /**
     * Prepare a local OP reauthentication without terminating RP sessions.
     * The returned state is stored only after IUserSession::logout() has
     * cleared the old Nextcloud session and is bound to the current user.
     *
     * @return array{user_id:string,sessions:array<string,string>}
     */
    public function prepareReauthentication(string $userId): array {
        $sessions = $this->session->get(self::SESSION_KEY);
        $this->session->set(self::REAUTHENTICATION_SUPPRESS_KEY, true);
        return [
            'user_id' => $userId,
            'sessions' => is_array($sessions) ? $sessions : [],
        ];
    }

    public function cancelReauthentication(): void {
        $this->session->remove(self::REAUTHENTICATION_SUPPRESS_KEY);
    }

    /** @param array{user_id:string,sessions:array<string,string>} $state */
    public function storePendingReauthentication(array $state): void {
        $this->session->remove(self::REAUTHENTICATION_SUPPRESS_KEY);
        $this->session->remove(self::LEGACY_SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);
        $this->session->set(self::REAUTHENTICATION_PENDING_KEY, $state);
    }

    /**
     * Restore RP/sid correlation only when the same user completed the fresh
     * authentication. A different user must never inherit RP session state.
     */
    public function resumeAfterReauthentication(string $userId): void {
        $pending = $this->session->get(self::REAUTHENTICATION_PENDING_KEY);
        if (!is_array($pending)) {
            return;
        }
        $this->session->remove(self::REAUTHENTICATION_PENDING_KEY);

        $expectedUserId = $pending['user_id'] ?? null;
        $sessions = $pending['sessions'] ?? null;
        if (!is_string($expectedUserId) || !hash_equals($expectedUserId, $userId) || !is_array($sessions)) {
            $this->logger->warning('Discarding OIDC reauthentication state because the authenticated user changed.');
            return;
        }

        if ($sessions !== []) {
            $this->session->set(self::SESSION_KEY, $sessions);
        }
    }

    /**
     * Send one Back-Channel Logout request to every participating RP that
     * currently has a backchannel_logout_uri configured.
     *
     * Requests are started first and only then awaited. Transient failures are
     * queued for at most two delayed retries, each with a freshly signed Logout
     * Token. A retry never runs inside the interactive logout request.
     */
    public function logout(?string $userId): void {
        if ($this->session->get(self::REAUTHENTICATION_SUPPRESS_KEY) === true) {
            $this->logger->debug('Skipping Back-Channel Logout for OIDC reauthentication.');
            return;
        }

        $this->session->remove(self::LEGACY_SESSION_KEY);

        $sessions = $this->session->get(self::SESSION_KEY);
        if (!is_array($sessions) || $sessions === []) {
            return;
        }

        $this->session->remove(self::SESSION_KEY);
        $logoutTime = null;
        if ($userId !== null && $userId !== '') {
            $logoutTime = $this->time->getTime();
            try {
                $this->recentSessionMapper->cleanUp($logoutTime - self::RECENT_SESSION_TTL);
            } catch (\Throwable $e) {
                // History persistence must never prevent the actual local logout
                // or Back-Channel Logout fan-out.
                $this->logger->warning('Could not clean up recent OIDC logout sessions.', [
                    'exception' => $e,
                ]);
            }
        }
        $httpClient = $this->clientService->newClient();

        /** @var list<IPromise> $promises */
        $promises = [];

        foreach ($sessions as $clientId => $sid) {
            if (!is_string($sid) || $sid === '' || !ctype_digit((string)$clientId)) {
                continue;
            }

            try {
                $client = $this->clientMapper->getByUid((int)$clientId);

                // Record recent-session correlation before any outbound I/O.
                // Reauthentication exits above and is deliberately not stored.
                if ($logoutTime !== null && $userId !== null && $userId !== '') {
                    try {
                        $this->recentSessionMapper->remember($userId, $client->getClientIdentifier(), $sid, $logoutTime);
                    } catch (\Throwable $e) {
                        // Do not suppress the RP notification just because the
                        // optional recent-session history could not be persisted.
                        $this->logger->warning('Could not remember recent OIDC logout session.', [
                            'client_id' => $client->getClientIdentifier(),
                            'exception' => $e,
                        ]);
                    }
                }

                $logoutUri = trim((string)($client->getBackchannelLogoutUri() ?? ''));
                if (!$this->isUsableLogoutUriForClient($client, $logoutUri)) {
                    continue;
                }

                $logoutToken = $this->generateLogoutToken($client, $userId, $sid);
                $clientIdentifier = $client->getClientIdentifier();

                try {
                    $promise = $httpClient->postAsync($logoutUri, $this->requestOptions($logoutToken, $client->isDcr()));
                } catch (\Throwable $e) {
                    $this->logger->warning('Back-Channel Logout request could not be started.', [
                        'client_id' => $clientIdentifier,
                        'exception' => $e,
                    ]);
                    $this->scheduleRetry($client, $sid, 1);
                    continue;
                }

                $promises[] = $promise->then(
                    function (IResponse $response) use ($client, $sid): void {
                        $status = $response->getStatusCode();
                        if ($status === 200 || $status === 204) {
                            return;
                        }

                        $this->logger->warning('Back-Channel Logout endpoint returned an unexpected status.', [
                            'client_id' => $client->getClientIdentifier(),
                            'status' => $status,
                        ]);
                        if ($this->isRetryableStatus($status)) {
                            $this->scheduleRetry($client, $sid, 1);
                        }
                    },
                    function (\Throwable $e) use ($client, $sid): void {
                        $this->logger->warning('Back-Channel Logout request failed.', [
                            'client_id' => $client->getClientIdentifier(),
                            'exception' => $e,
                        ]);
                        $this->scheduleRetry($client, $sid, 1);
                    }
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Back-Channel Logout request could not be prepared.', [
                    'client_db_id' => (string)$clientId,
                    'exception' => $e,
                ]);
            }
        }

        foreach ($promises as $promise) {
            $promise->wait(false);
        }
    }

    /**
     * Execute one queued retry. The job carries only the minimum correlation
     * data and the current client configuration/URI is re-read before sending.
     *
     * @param array<string, mixed> $argument
     */
    public function retry(array $argument): void {
        $clientDbId = $argument['client_db_id'] ?? null;
        $sid = $argument['sid'] ?? null;
        $attempt = $argument['attempt'] ?? null;

        if (!is_int($clientDbId)
            || !is_string($sid) || $sid === ''
            || !is_int($attempt) || $attempt < 1 || $attempt > self::MAX_RETRY_ATTEMPTS) {
            $this->logger->warning('Ignoring malformed Back-Channel Logout retry job.');
            return;
        }

        try {
            $client = $this->clientMapper->getByUid($clientDbId);
            $logoutUri = trim((string)($client->getBackchannelLogoutUri() ?? ''));
            if (!$this->isUsableLogoutUriForClient($client, $logoutUri)) {
                return;
            }

            // Persist only the session correlation identifier in the job queue.
            // The Back-Channel Logout specification permits a sid-only Logout
            // Token; avoiding user_id in queued arguments minimizes sensitive
            // identity data retained for retries.
            $logoutToken = $this->generateLogoutToken($client, null, $sid);
            $response = $this->clientService->newClient()->post($logoutUri, $this->requestOptions($logoutToken, $client->isDcr()));
            $status = $response->getStatusCode();
            if ($status === 200 || $status === 204) {
                return;
            }

            $this->logger->warning('Back-Channel Logout retry returned an unexpected status.', [
                'client_id' => $client->getClientIdentifier(),
                'status' => $status,
                'attempt' => $attempt,
            ]);
            if ($this->isRetryableStatus($status)) {
                $this->scheduleRetry($client, $sid, $attempt + 1);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Back-Channel Logout retry failed.', [
                'client_db_id' => $clientDbId,
                'attempt' => $attempt,
                'exception' => $e,
            ]);

            if (isset($client) && $client instanceof Client) {
                $this->scheduleRetry($client, $sid, $attempt + 1);
            }
        }
    }

    private function generateLogoutToken(Client $client, ?string $userId, string $sid): string {
        return $this->jwtGenerator->generateLogoutToken(
            $client,
            $userId,
            $sid,
            $this->request->getServerProtocol(),
            $this->request->getServerHost()
        );
    }

    /** @return array<string, mixed> */
    private function requestOptions(string $logoutToken, bool $forcePublicAddressPolicy = false): array {
        $options = [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'body' => ['logout_token' => $logoutToken],
            'timeout' => 5,
            'allow_redirects' => false,
        ];

        if ($forcePublicAddressPolicy) {
            // Force Nextcloud's DNS pinning/local-address protection for DCR
            // callbacks even when allow_local_remote_servers is globally true.
            $options['nextcloud'] = ['allow_local_address' => false];
        }

        return $options;
    }

    private function isRetryableStatus(int $status): bool {
        return $status === 408 || $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function scheduleRetry(Client $client, string $sid, int $attempt): void {
        if ($attempt < 1 || $attempt > self::MAX_RETRY_ATTEMPTS) {
            return;
        }

        $delay = self::RETRY_DELAYS[$attempt] ?? null;
        if ($delay === null) {
            return;
        }

        try {
            $this->jobList->scheduleAfter(
                BackChannelLogoutRetryJob::class,
                $this->time->getTime() + $delay,
                [
                    'client_db_id' => $client->getId(),
                    'sid' => $sid,
                    'attempt' => $attempt,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not queue Back-Channel Logout retry.', [
                'client_id' => $client->getClientIdentifier(),
                'attempt' => $attempt,
                'exception' => $e,
            ]);
        }
    }

    private function isUsableLogoutUriForClient(Client $client, string $logoutUri): bool {
        if ($logoutUri === '' || !self::isValidBackChannelLogoutUri($logoutUri, $client->getType())) {
            $this->logger->warning('Skipped invalid Back-Channel Logout URI for client.', [
                'client_id' => $client->getClientIdentifier(),
            ]);
            return false;
        }

        if ($client->isDcr() && !self::isAllowedDynamicBackChannelLogoutUri($logoutUri, $client->getType())) {
            $this->logger->warning('Blocked dynamic Back-Channel Logout URI by application SSRF policy.', [
                'client_id' => $client->getClientIdentifier(),
            ]);
            return false;
        }

        return true;
    }

    public static function isValidBackChannelLogoutUri(string $uri, string $clientType): bool {
        if ($uri === '' || strlen($uri) > 2000 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
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

    /**
     * Additional application-level SSRF policy for dynamically registered RPs.
     * It deliberately does not depend on Nextcloud's allow_local_remote_servers
     * setting. The hostname is resolved during registration/update and again
     * immediately before every initial/retry delivery; every resolved address
     * must be globally routable.
     */
    public static function isAllowedDynamicBackChannelLogoutUri(string $uri, string $clientType): bool {
        if (!self::isValidBackChannelLogoutUri($uri, $clientType)) {
            return false;
        }

        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        // Application policy for DCR/RFC 7592: dynamically registered
        // Back-Channel Logout endpoints must always use TLS, even for
        // confidential clients. Static/admin-managed clients retain the
        // base specification policy implemented by isValidBackChannelLogoutUri().
        if (strtolower((string)$parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (in_array($host, self::BLOCKED_METADATA_HOSTNAMES, true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isAllowedPublicAddress($host);
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        $addresses = self::resolveHostAddresses($host);
        if ($addresses === []) {
            // Fail closed if the callback host cannot currently be resolved.
            return false;
        }

        foreach ($addresses as $address) {
            if (!self::isAllowedPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function resolveHostAddresses(string $host): array {
        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        if (defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = $record['ipv6'] ?? null;
                    if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                        $addresses[] = $address;
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function isAllowedPublicAddress(string $address): bool {
        $normalized = strtolower($address);
        foreach (self::BLOCKED_METADATA_IPS as $blocked) {
            if ($normalized === strtolower($blocked)) {
                return false;
            }
        }

        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        // 100.64.0.0/10 is shared address space. It is not classified as a
        // private/reserved range by all PHP versions and includes Alibaba's
        // metadata endpoint 100.100.100.200.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);
            $sharedStart = ip2long('100.64.0.0');
            $sharedEnd = ip2long('100.127.255.255');
            if ($long !== false && $sharedStart !== false && $sharedEnd !== false
                && $long >= $sharedStart && $long <= $sharedEnd) {
                return false;
            }
        }

        return true;
    }
}
