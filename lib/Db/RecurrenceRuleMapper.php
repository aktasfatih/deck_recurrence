<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RecurrenceRule>
 */
class RecurrenceRuleMapper extends QBMapper {
	public const TABLE = 'deck_rec_rules';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, RecurrenceRule::class);
	}

	public function find(int $id, string $userId): RecurrenceRule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @return RecurrenceRule[] */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return RecurrenceRule[] */
	public function findDue(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNotNull('next_run'))
			->andWhere($qb->expr()->lte('next_run', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}
}
