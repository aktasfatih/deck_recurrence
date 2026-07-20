<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Service;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\Db\ArchiveRule;
use OCA\DeckRecurrence\Db\ArchiveRuleMapper;
use OCA\DeckRecurrence\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Archive-rule management shared by the web UI controller and the OCS
 * API, with the same error contract as RuleService:
 * \InvalidArgumentException (400), NoPermissionException (403),
 * NotFoundException (404).
 */
class ArchiveRuleService {
	/** Ten years, far beyond any sensible cleanup threshold */
	private const MAX_THRESHOLD = 315360000;

	public function __construct(
		private ArchiveRuleMapper $ruleMapper,
		private ArchiveService $archiveService,
		private PermissionService $permissionService,
		private StackMapper $stackMapper,
	) {
	}

	/** @return ArchiveRule[] */
	public function listRules(string $userId): array {
		return $this->ruleMapper->findAllForUser($userId);
	}

	public function find(int $id, string $userId): ArchiveRule {
		try {
			return $this->ruleMapper->find($id, $userId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException('Rule not found');
		}
	}

	public function create(
		string $userId,
		int $boardId,
		?int $stackId,
		string $mode,
		int $threshold,
	): ArchiveRule {
		$this->validate($boardId, $stackId, $mode, $threshold);

		$rule = new ArchiveRule();
		$rule->setUserId($userId);
		$rule->setBoardId($boardId);
		$rule->setStackId($stackId);
		$rule->setMode($mode);
		$rule->setThreshold($threshold);
		$rule->setEnabled(true);
		$rule->setCreatedAt(time());

		return $this->ruleMapper->insert($rule);
	}

	public function update(
		int $id,
		string $userId,
		int $boardId,
		?int $stackId,
		string $mode,
		int $threshold,
		bool $enabled,
	): ArchiveRule {
		$rule = $this->find($id, $userId);
		$this->validate($boardId, $stackId, $mode, $threshold);

		$rule->setBoardId($boardId);
		$rule->setStackId($stackId);
		$rule->setMode($mode);
		$rule->setThreshold($threshold);
		$rule->setEnabled($enabled);

		return $this->ruleMapper->update($rule);
	}

	public function delete(int $id, string $userId): ArchiveRule {
		$rule = $this->find($id, $userId);
		return $this->ruleMapper->delete($rule);
	}

	/**
	 * Manual sweep, same semantics as the scheduled one (it is
	 * idempotent — nothing extra qualifies just because it ran early).
	 *
	 * @return int number of cards archived
	 */
	public function runNow(int $id, string $userId): int {
		$rule = $this->find($id, $userId);
		$count = $this->archiveService->sweep($rule);
		$rule->setLastRun(time());
		$this->ruleMapper->update($rule);
		return $count;
	}

	private function validate(int $boardId, ?int $stackId, string $mode, int $threshold): void {
		if (!in_array($mode, [ArchiveRule::MODE_DONE_FOR, ArchiveRule::MODE_MIN_AGE], true)) {
			throw new \InvalidArgumentException('Invalid archive rule mode');
		}
		if ($threshold <= 0 || $threshold > self::MAX_THRESHOLD) {
			throw new \InvalidArgumentException('Invalid archive threshold');
		}
		try {
			// Archiving rewrites cards, so the rule needs edit rights on its scope
			$this->permissionService->checkPermission(null, $boardId, Acl::PERMISSION_EDIT);
			if ($stackId !== null) {
				$stack = $this->stackMapper->find($stackId);
				if ((int)$stack->getBoardId() !== $boardId) {
					throw new \InvalidArgumentException('The stack does not belong to the board');
				}
				$this->permissionService->checkPermission($this->stackMapper, $stackId, Acl::PERMISSION_EDIT);
			}
		} catch (DoesNotExistException $e) {
			throw new NotFoundException('Board or stack not found');
		}
	}
}
