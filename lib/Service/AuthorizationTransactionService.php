<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Persist an authorization request across a Nextcloud login/session replacement. */
class AuthorizationTransactionService {
    public const TTL = 600;
    private const TABLE = 'oidc_auth_transactions';

    public function __construct(private IDBConnection $db, private ITimeFactory $time) {
    }

    /** @param array<string, mixed> $parameters */
    public function create(array $parameters, string $reason): string {
        if (!in_array($reason, ['not_authenticated', 'prompt_login', 'max_age', 'select_account', 'authorization_post'], true)) {
            throw new \InvalidArgumentException('Invalid authorization transaction reason.');
        }
        $payload = json_encode($parameters, JSON_THROW_ON_ERROR);
        $id = bin2hex(random_bytes(32));
        $now = $this->time->getTime();
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)->values([
            'id' => $qb->createNamedParameter(hash('sha256', $id)),
            'request_payload' => $qb->createNamedParameter($payload),
            'reason' => $qb->createNamedParameter($reason),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'expires_at' => $qb->createNamedParameter($now + self::TTL, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return $id;
    }

    /** Check that a handoff is still valid without consuming its one-time token. */
    public function isPending(string $id): bool {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
            return false;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(hash('sha256', $id))))
            ->andWhere($qb->expr()->isNull('consumed_at'))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)));

        return $qb->executeQuery()->fetchOne() !== false;
    }

    /** @return array{parameters:array<string, mixed>, reason:string, created_at:int}|null */
    public function consume(string $id, ?string $expectedReason = null): ?array {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) {
            return null;
        }
        $hash = hash('sha256', $id);
        $now = $this->time->getTime();
        $qb = $this->db->getQueryBuilder();
        $qb->select('request_payload', 'reason', 'created_at')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($hash)))
            ->andWhere($qb->expr()->isNull('consumed_at'))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        if ($expectedReason !== null) {
            $qb->andWhere($qb->expr()->eq('reason', $qb->createNamedParameter($expectedReason)));
        }
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
        } finally {
            $result->closeCursor();
        }
        if ($row === false) {
            return null;
        }

        // A conditional update is the single-use gate across all app nodes.
        $qb = $this->db->getQueryBuilder();
        $claimed = $qb->update(self::TABLE)
            ->set('consumed_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($hash)))
            ->andWhere($qb->expr()->isNull('consumed_at'))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        if ($claimed !== 1) {
            return null;
        }

        try {
            $parameters = json_decode((string)$row['request_payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($parameters) || !in_array($row['reason'], ['not_authenticated', 'prompt_login', 'max_age', 'select_account', 'authorization_post'], true)) {
            return null;
        }
        return ['parameters' => $parameters, 'reason' => $row['reason'], 'created_at' => (int)$row['created_at']];
    }

    public function cleanup(): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
