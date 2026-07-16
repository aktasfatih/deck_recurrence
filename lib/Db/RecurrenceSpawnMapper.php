<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RecurrenceSpawn>
 */
class RecurrenceSpawnMapper extends QBMapper {
	public const TABLE = 'deck_rec_spawns';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, RecurrenceSpawn::class);
	}

	public function findLatestForRule(int $ruleId): ?RecurrenceSpawn {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->orderBy('spawned_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function deleteForRule(int $ruleId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
