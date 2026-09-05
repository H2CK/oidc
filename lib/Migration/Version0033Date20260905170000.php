<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Migration;

use Closure;
use OCA\OIDCIdentityProvider\Db\DeviceCode;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0033Date20260905170000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('oidc_device_codes')) {
			return $schema;
		}

		$table = $schema->createTable('oidc_device_codes');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('client_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('hashed_device_code', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('hashed_user_code', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 512]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('interval_seconds', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 5]);
		$table->addColumn('last_polled_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => DeviceCode::STATUS_PENDING]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 256]);
		$table->addColumn('consumed_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['hashed_device_code'], 'oidc_device_code_hash_idx');
		$table->addUniqueIndex(['hashed_user_code'], 'oidc_user_code_hash_idx');
		$table->addIndex(['expires_at'], 'oidc_device_code_exp_idx');
		$table->addIndex(['client_id', 'status'], 'oidc_device_client_idx');

		return $schema;
	}
}
