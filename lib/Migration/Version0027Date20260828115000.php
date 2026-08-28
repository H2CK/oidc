<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Make RFC 8693 token lineage race-safe at the database level.
 *
 * The self-referencing foreign key guarantees that an exchanged token can only
 * be inserted while its parent token still exists. ON DELETE CASCADE also makes
 * a concurrent parent revocation remove a child that was inserted immediately
 * before the parent deletion, closing the insert-vs-revoke race window.
 */
class Version0027Date20260828115000 extends SimpleMigrationStep {
    private const PARENT_FOREIGN_KEY = 'oidc_at_parent_fk';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Remove any already-orphaned lineage before the foreign key is created.
     *
     * An orphaned exchanged token must be deleted rather than detached from its
     * parent: setting parent_token_id to NULL would incorrectly turn it into a
     * normal access token and weaken the UserInfo/revocation semantics.
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('oidc_access_tokens')) {
            return;
        }

        $table = $schema->getTable('oidc_access_tokens');
        if (!$table->hasColumn('parent_token_id')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'parent_token_id')
            ->from('oidc_access_tokens');
        $result = $qb->executeQuery();

        $parents = [];
        $children = [];
        try {
            while (($row = $result->fetchAssociative()) !== false) {
                $id = (int)$row['id'];
                $parentTokenId = $row['parent_token_id'] === null ? null : (int)$row['parent_token_id'];
                $parents[$id] = $parentTokenId;
                if ($parentTokenId !== null && $parentTokenId > 0) {
                    $children[$parentTokenId][] = $id;
                }
            }
        } finally {
            $result->closeCursor();
        }

        $toDelete = [];
        $stack = [];
        foreach ($parents as $id => $parentTokenId) {
            if ($parentTokenId !== null && $parentTokenId > 0 && !array_key_exists($parentTokenId, $parents)) {
                $stack[] = $id;
            }
        }

        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($toDelete[$id])) {
                continue;
            }
            $toDelete[$id] = true;
            foreach ($children[$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        foreach (array_keys($toDelete) as $id) {
            $delete = $this->db->getQueryBuilder();
            $delete->delete('oidc_access_tokens')
                ->where($delete->expr()->eq('id', $delete->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->executeStatement();
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('oidc_access_tokens')) {
            return null;
        }

        $table = $schema->getTable('oidc_access_tokens');
        if (!$table->hasColumn('parent_token_id') || $table->hasForeignKey(self::PARENT_FOREIGN_KEY)) {
            return null;
        }

        $table->addForeignKeyConstraint(
            $table,
            ['parent_token_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            self::PARENT_FOREIGN_KEY
        );

        return $schema;
    }
}
