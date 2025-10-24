<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0015Date20251024100100 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('oidc_reg_tokens')) {
			$table = $schema->createTable('oidc_reg_tokens');

			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('client_id', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);

			$table->addColumn('created_at', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('expires_at', Types::INTEGER, [
				'notnull' => false,
				'unsigned' => true,
				'default' => null,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'oidc_rt_tok');
			$table->addIndex(['client_id'], 'oidc_rt_cid');
			$table->addIndex(['expires_at'], 'oidc_rt_exp');
		}

		return $schema;
	}
}
