<?php

declare(strict_types=1);

/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** HMAC clients know their signing secret: only locally issued ID tokens are trusted. */
class IssuedIdTokenMapper {
    public function __construct(private IDBConnection $db) {
    }

    public function record(string $token, int $expiresAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('oidc_id_token_hashes')->values([
            'token_hash' => $qb->createNamedParameter(hash('sha256', $token)),
            'expires_at' => $qb->createNamedParameter($expiresAt, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    public function isIssued(string $token, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('token_hash')->from('oidc_id_token_hashes')
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter(hash('sha256', $token))))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        try {
            return $result->fetchOne() !== false;
        } finally {
            $result->closeCursor();
        }
    }

    public function cleanUp(int $now): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('oidc_id_token_hashes')
            ->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
