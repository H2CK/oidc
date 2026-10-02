<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;
use OCA\OIDCIdentityProvider\Service\RedirectUriUpgradeService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

class Version0037Date20261002080000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
        private RedirectUriUpgradeService $redirectUris,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('oidc_user_consents')) {
            $table = $schema->getTable('oidc_user_consents');
            if (!$table->hasColumn('scopes_requested')) {
                // Existing consent proves only granted scopes; previously
                // declined scopes cannot reliably be reconstructed.
                $table->addColumn('scopes_requested', Types::STRING, ['notnull' => false, 'length' => 512]);
            }
        }
        if ($schema->hasTable('oidc_access_tokens')) {
            $table = $schema->getTable('oidc_access_tokens');
            if (!$table->hasColumn('event_generated')) {
                $table->addColumn('event_generated', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            }
        }
        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $schema = $schemaClosure();
        if (!$schema->hasTable('oidc_redirect_uris') || !$schema->hasTable('oidc_clients')) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('r.id', 'r.client_id', 'r.redirect_uri', 'c.dcr')
            ->from('oidc_redirect_uris', 'r')
            ->innerJoin('r', 'oidc_clients', 'c', $qb->expr()->eq('r.client_id', 'c.id'));
        $result = $qb->executeQuery();
        try {
            $rows = [];
            while (($row = $result->fetch()) !== false) {
                $rows[] = $row;
            }
        } finally {
            $result->closeCursor();
        }
        $registered = [];
        foreach ($rows as $row) {
            $registered[(int)$row['client_id']][$row['redirect_uri']] = true;
        }
        $review = [];
        foreach ($rows as $row) {
            $clientId = (int)$row['client_id'];
            try {
                $dcr = in_array(strtolower((string)$row['dcr']), ['1', 'true', 't'], true);
                $aliases = $this->redirectUris->compatibilityAliases($row['redirect_uri'], $dcr);
            } catch (RedirectUriValidationException $e) {
                // Never guess a missing scheme/host or preserve an unsafe
                // wildcard. Keep the row visible for administrative repair.
                $entry = ['client_id' => $clientId, 'redirect_uri_id' => (int)$row['id'], 'reason' => $e->getMessage()];
                $review[] = $entry;
                $this->logger->warning('OIDC redirect URI requires manual review after upgrade.', $entry);
                $output->warning('OIDC client ' . $clientId . ', redirect URI ' . $row['id']
                    . ' requires manual review. See README.md: Redirect URI upgrade.');
                continue;
            }
            foreach ($aliases as $alias) {
                if (isset($registered[$clientId][$alias])) {
                    continue;
                }
                $insert = $this->db->getQueryBuilder();
                $insert->insert('oidc_redirect_uris')->values([
                    'client_id' => $insert->createNamedParameter($clientId, IQueryBuilder::PARAM_INT),
                    'redirect_uri' => $insert->createNamedParameter($alias),
                ])->executeStatement();
                $registered[$clientId][$alias] = true;
            }
        }
        $this->appConfig->setAppValueString('redirect_uri_upgrade_review', json_encode($review, JSON_THROW_ON_ERROR));
        $output->info('OIDC redirect URI review completed; ' . count($review) . ' entries require manual correction.');
    }
}
