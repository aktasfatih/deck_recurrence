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

class Version060000Date20260719090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$rules = $schema->getTable('deck_rec_rules');
		if (!$rules->hasColumn('due_offset')) {
			// Seconds between an occurrence and the spawned card's due date.
			// 0 = due at the occurrence itself (the pre-0.6 behaviour, hence
			// the default), -1 = no due date.
			$rules->addColumn('due_offset', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
			]);
		}

		return $schema;
	}
}
