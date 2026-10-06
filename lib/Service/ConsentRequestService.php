<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Service;

use OCP\ISession;
use OCP\AppFramework\Utility\ITimeFactory;

/** Independent, user-bound and single-use consent snapshots for browser tabs. */
class ConsentRequestService {
    public const TTL = 600;
    private const KEY = 'oidc_consent_requests';
    private const MAX_PENDING = 10;

    public function __construct(private ISession $session, private ITimeFactory $time) {
    }

    public function create(string $uid, array $parameters, string $clientName, bool $freshLogin): string {
        $requests = $this->pending();
        while (count($requests) >= self::MAX_PENDING) {
            array_shift($requests);
        }
        $id = bin2hex(random_bytes(32));
        $requests[$id] = [
            'uid' => $uid, 'parameters' => $parameters, 'clientName' => $clientName,
            'freshLogin' => $freshLogin, 'expiresAt' => $this->time->getTime() + self::TTL,
        ];
        $this->session->set(self::KEY, $requests);
        return $id;
    }

    public function get(?string $id, string $uid): ?array {
        if ($id === null || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
            return null;
        }
        $request = $this->pending()[$id] ?? null;
        return $request !== null && $request['uid'] === $uid ? $request : null;
    }

    public function consume(?string $id, string $uid): ?array {
        $request = $this->get($id, $uid);
        if ($request !== null) {
            $requests = $this->pending();
            unset($requests[$id]);
            $this->session->set(self::KEY, $requests);
        }
        return $request;
    }

    private function pending(): array {
        $requests = $this->session->get(self::KEY);
        if (!is_array($requests)) {
            return [];
        }
        return array_filter($requests, fn ($request): bool => is_array($request)
            && isset($request['expiresAt'], $request['uid'], $request['parameters'])
            && $request['expiresAt'] > $this->time->getTime());
    }
}
