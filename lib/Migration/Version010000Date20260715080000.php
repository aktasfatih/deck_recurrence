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

class Version010000Date20260715080000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('deck_rec_rules')) {
			$table = $schema->createTable('deck_rec_rules');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('template_card_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('target_stack_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('rrule', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('dtstart', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('next_run', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('last_run', Types::BIGINT, [
				'notnull' => false,
			]);
			$table->addColumn('enabled', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$table->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'deck_rec_rules_user');
			$table->addIndex(['enabled', 'next_run'], 'deck_rec_rules_due');
		}

		return $schema;
	}
}
