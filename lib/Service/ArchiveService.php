<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\AppInfo\Application;
use OCA\DeckRecurrence\Db\ArchiveRule;
use OCA\DeckRecurrence\Db\ArchiveRuleMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * The sweep behind auto-archive rules: find done cards past a rule's
 * age threshold and archive them, acting as the rule's owner.
 */
class ArchiveService {
	/**
	 * Cards archived per rule per pass. Bounds the cron job's runtime when
	 * a rule is first enabled on a board with years of done cards; the
	 * remainder is picked up on subsequent passes.
	 */
	public const MAX_PER_RUN = 100;

	public function __construct(
		private ArchiveRuleMapper $ruleMapper,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private ChangeHelper $changeHelper,
		private PermissionService $permissionService,
		private ActivityManager $activityManager,
		private INotificationManager $notificationManager,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Archive every eligible card in the rule's scope (capped at
	 * MAX_PER_RUN). Runs with the rule owner's permissions, so revoked
	 * board access stops the sweep naturally.
	 *
	 * @return int number of cards archived
	 * @throws \OCA\Deck\NoPermissionException
	 */
	public function sweep(ArchiveRule $rule): int {
		$this->permissionService->setUserId($rule->getUserId());
		if ($rule->getStackId() !== null) {
			$this->permissionService->checkPermission($this->stackMapper, $rule->getStackId(), Acl::PERMISSION_EDIT, $rule->getUserId());
		} else {
			$this->permissionService->checkPermission(null, $rule->getBoardId(), Acl::PERMISSION_EDIT, $rule->getUserId());
		}

		$count = 0;
		foreach ($this->findEligibleCardIds($rule, time()) as $cardId) {
			try {
				$card = $this->cardMapper->find($cardId);
				$card->setArchived(true);
				$this->cardMapper->update($card);
				$this->changeHelper->cardChanged($cardId);
			} catch (\Throwable $e) {
				$this->logger->debug('Deck Recurrence: could not archive card ' . $cardId
					. ' for rule ' . $rule->getId(), ['exception' => $e]);
				continue;
			}
			try {
				$this->activityManager->triggerEvent(
					ActivityManager::DECK_OBJECT_CARD,
					$card,
					ActivityManager::SUBJECT_CARD_UPDATE_ARCHIVE,
					[],
					$rule->getUserId(),
				);
			} catch (\Throwable $e) {
				$this->logger->debug('Deck Recurrence: could not record activity for card ' . $cardId, [
					'exception' => $e,
				]);
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Sweep every enabled rule. A broken rule (deleted board, lost access)
	 * is skipped and its owner notified; it cannot stall the others.
	 */
	public function runEnabledRules(): void {
		$now = time();
		foreach ($this->ruleMapper->findEnabled() as $rule) {
			try {
				$count = $this->sweep($rule);
			} catch (\Throwable $e) {
				$this->logger->warning('Deck Recurrence: could not run archive rule ' . $rule->getId(), [
					'exception' => $e,
				]);
				$this->notifyFailure($rule);
				continue;
			}
			if ($count > 0) {
				$this->logger->info('Deck Recurrence: archive rule ' . $rule->getId()
					. ' archived ' . $count . ' card(s)');
			}
			$rule->setLastRun($now);
			$this->ruleMapper->update($rule);
		}
	}

	/** @return int[] card ids, oldest first */
	private function findEligibleCardIds(ArchiveRule $rule, int $now): array {
		$cutoff = $now - $rule->getThreshold();
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($rule->getBoardId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('c.done'))
			->orderBy('c.id', 'ASC')
			->setMaxResults(self::MAX_PER_RUN);
		if ($rule->getStackId() !== null) {
			$qb->andWhere($qb->expr()->eq('c.stack_id', $qb->createNamedParameter($rule->getStackId(), IQueryBuilder::PARAM_INT)));
		}
		if ($rule->getMode() === ArchiveRule::MODE_MIN_AGE) {
			$qb->andWhere($qb->expr()->lte('c.created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		} else {
			// Deck writes `done` as a server-local DateTime, so the cutoff
			// must be built the same way for the comparison to be sound
			$cutoffDate = (new \DateTime())->setTimestamp($cutoff);
			$qb->andWhere($qb->expr()->lte('c.done', $qb->createNamedParameter($cutoffDate, IQueryBuilder::PARAM_DATE)));
		}

		$result = $qb->executeQuery();
		$ids = array_map('intval', array_column($result->fetchAll(), 'id'));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * One active notification per rule, replaced rather than piled onto
	 * every 15 minutes — same contract as recurrence spawn failures.
	 */
	private function notifyFailure(ArchiveRule $rule): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($rule->getUserId())
				->setObject('archive_rule', (string)$rule->getId());
			$this->notificationManager->markProcessed($notification);

			$notification->setDateTime(new \DateTime())
				->setSubject('archive_failed', ['ruleId' => $rule->getId()]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->debug('Deck Recurrence: could not notify owner of archive rule ' . $rule->getId(), [
				'exception' => $e,
			]);
		}
	}
}
