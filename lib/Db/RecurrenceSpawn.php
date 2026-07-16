<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRuleId()
 * @method void setRuleId(int $ruleId)
 * @method int getCardId()
 * @method void setCardId(int $cardId)
 * @method int getSpawnedAt()
 * @method void setSpawnedAt(int $spawnedAt)
 */
class RecurrenceSpawn extends Entity {
	protected int $ruleId = 0;
	protected int $cardId = 0;
	protected int $spawnedAt = 0;

	public function __construct() {
		$this->addType('ruleId', 'integer');
		$this->addType('cardId', 'integer');
		$this->addType('spawnedAt', 'integer');
	}
}
