<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;

class Version0003Date20220927082100 extends SimpleMigrationStep {
    private LoggerInterface $logger;
    private ClientMapper $clientMapper;
    private RedirectUriMapper $redirectUriMapper;
    private IDBConnection $db;

    public function __construct(IDBConnection $db,
                                ClientMapper $clientMapper,
                                RedirectUriMapper $redirectUriMapper,
                                LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
        $this->clientMapper = $clientMapper;
        $this->redirectUriMapper = $redirectUriMapper;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('oidc_redirect_uris')) {
            $schema->dropTable('oidc_redirect_uris');
        }

        if (!$schema->hasTable('oidc_redirect_uris')) {
            $table = $schema->createTable('oidc_redirect_uris');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('client_id', 'integer', [
                'notnull' => true,
            ]);
            $table->addColumn('redirect_uri', 'string', [
                'notnull' => true,
                'length' => 2000,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['client_id'], 'oidc_redir_id_idx');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $clients = $this->clientMapper->getClients();

        foreach($clients as $client) {
            foreach ($client->getRedirectUris() as $uri) {
                $redirectUri = new RedirectUri();
                $redirectUri->setClientId($client->getId());
                $redirectUri->setRedirectUri($uri);
                $this->redirectUriMapper->insert($redirectUri);
            }
        }

        return $schema;

     }
}
