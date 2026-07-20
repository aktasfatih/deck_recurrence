<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version070000Date20260720090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('deck_rec_arch_rules')) {
			$table = $schema->createTable('deck_rec_arch_rules');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('board_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('stack_id', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('mode', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'done_for',
			]);
			$table->addColumn('threshold', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('enabled', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$table->addColumn('last_run', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'deck_rec_arch_user');
			$table->addIndex(['enabled'], 'deck_rec_arch_enabled');
		}

		return $schema;
	}
}
