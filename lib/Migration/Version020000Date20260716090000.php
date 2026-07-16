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

class Version020000Date20260716090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$rules = $schema->getTable('deck_rec_rules');
		if (!$rules->hasColumn('skip_if_open')) {
			$rules->addColumn('skip_if_open', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
		}

		if (!$schema->hasTable('deck_rec_spawns')) {
			$table = $schema->createTable('deck_rec_spawns');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('rule_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('card_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('spawned_at', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['rule_id', 'spawned_at'], 'deck_rec_spawns_rule');
		}

		return $schema;
	}
}
