<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Service;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\AppInfo\Application;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\Db\RecurrenceRuleMapper;
use OCA\DeckRecurrence\Db\RecurrenceSpawn;
use OCA\DeckRecurrence\Db\RecurrenceSpawnMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Recur\RRuleIterator;

class RecurrenceService {
	public function __construct(
		private RecurrenceRuleMapper $ruleMapper,
		private RecurrenceSpawnMapper $spawnMapper,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private LabelMapper $labelMapper,
		private AssignmentMapper $assignmentMapper,
		private ChangeHelper $changeHelper,
		private PermissionService $permissionService,
		private ActivityManager $activityManager,
		private INotificationManager $notificationManager,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * First occurrence at or after $after, as a unix timestamp,
	 * or null if the rule has no further occurrences (COUNT/UNTIL exhausted).
	 *
	 * @throws \InvalidArgumentException if the RRULE cannot be parsed
	 */
	public function nextOccurrence(RecurrenceRule $rule, \DateTimeInterface $after): ?int {
		$timezone = $this->timezoneFor($rule->getUserId());
		$start = (new \DateTimeImmutable('@' . $rule->getDtstart()))->setTimezone($timezone);
		$iterator = new RRuleIterator($rule->getRrule(), $start);
		$iterator->fastForward(\DateTimeImmutable::createFromInterface($after));
		if (!$iterator->valid()) {
			return null;
		}
		return $iterator->current()->getTimestamp();
	}

	/**
	 * Copy the rule's template card into its target stack. Scheduled spawns
	 * get the occurrence time as due date; manual spawns ("create now") get
	 * no due date and ignore the skip-if-open setting.
	 *
	 * The copy is done through Deck's mappers rather than
	 * CardService::cloneCard() because Deck's service layer assumes a
	 * logged-in session for activity authorship, which does not exist under
	 * cron (Deck's own background jobs use the mapper layer for the same
	 * reason). Permission checks run as the rule's owner, so revoked board
	 * access disables spawning naturally.
	 *
	 * Returns the created card, or null when the rule is configured to wait
	 * for the previous card and that card is still open.
	 *
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function spawn(RecurrenceRule $rule, bool $manual = false): ?Card {
		if ($rule->getMode() === RecurrenceRule::MODE_RESET) {
			return $this->resetCard($rule, $manual);
		}

		if (!$manual && $rule->getSkipIfOpen()) {
			$latest = $this->spawnMapper->findLatestForRule($rule->getId());
			if ($latest !== null && $this->isCardOpen($latest->getCardId())) {
				$this->logger->info('Deck Recurrence: rule ' . $rule->getId()
					. ' skipped, card ' . $latest->getCardId() . ' is still open');
				return null;
			}
		}

		$this->permissionService->setUserId($rule->getUserId());
		$this->permissionService->checkPermission($this->cardMapper, $rule->getTemplateCardId(), Acl::PERMISSION_READ, $rule->getUserId());
		$this->permissionService->checkPermission($this->stackMapper, $rule->getTargetStackId(), Acl::PERMISSION_EDIT, $rule->getUserId());

		$template = $this->cardMapper->find($rule->getTemplateCardId());

		$description = $template->getDescription();
		if ($rule->getResetCheckboxes() && $description !== null) {
			$description = $this->uncheckAll($description);
		}

		$card = new Card();
		$card->setTitle($template->getTitle());
		$card->setStackId($rule->getTargetStackId());
		$card->setType($template->getType());
		$card->setOrder($template->getOrder());
		$card->setOwner($rule->getUserId());
		$card->setDescription($description);
		if (!$manual) {
			$occurrence = $rule->getNextRun() ?? time();
			$card->setDuedate(new \DateTime('@' . $occurrence));
		}
		$card = $this->cardMapper->insert($card);

		// Labels are only copied when both stacks are on the same board;
		// cross-board label cloning needs CardService and a user session.
		$templateBoard = $this->cardMapper->findBoardId($template->getId());
		$targetBoard = $this->stackMapper->findBoardId($rule->getTargetStackId());
		if ($templateBoard !== null && $templateBoard === $targetBoard) {
			foreach ($this->labelMapper->findAssignedLabelsForCard($template->getId()) as $label) {
				$this->cardMapper->assignLabel($card->getId(), $label->getId());
			}
		}

		// Assignees carry over when they can read the new card, mirroring
		// what Deck's own cloneCard does; anyone without access is skipped.
		foreach ($this->assignmentMapper->findAll($template->getId()) as $assignment) {
			try {
				$this->permissionService->checkPermission($this->cardMapper, $card->getId(), Acl::PERMISSION_READ, $assignment->getParticipant());
			} catch (\Throwable $e) {
				continue;
			}
			$copy = new Assignment();
			$copy->setCardId($card->getId());
			$copy->setParticipant($assignment->getParticipant());
			$copy->setType($assignment->getType());
			try {
				$this->assignmentMapper->insert($copy);
			} catch (\Throwable $e) {
				$this->logger->debug('Deck Recurrence: could not assign ' . $assignment->getParticipant()
					. ' to card ' . $card->getId(), ['exception' => $e]);
			}
		}

		$this->changeHelper->cardChanged($card->getId());

		$spawnRecord = new RecurrenceSpawn();
		$spawnRecord->setRuleId($rule->getId());
		$spawnRecord->setCardId($card->getId());
		$spawnRecord->setSpawnedAt(time());
		$this->spawnMapper->insert($spawnRecord);

		try {
			$this->activityManager->triggerEvent(
				ActivityManager::DECK_OBJECT_CARD,
				$card,
				ActivityManager::SUBJECT_CARD_CREATE,
				[],
				$rule->getUserId(),
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Deck Recurrence: could not record activity for card ' . $card->getId(), [
				'exception' => $e,
			]);
		}

		return $card;
	}

	/**
	 * Reset mode: the rule's card is the one and only working card. Each
	 * occurrence moves it back to the target stack, clears its done state,
	 * unarchives it and re-arms the due date (deck#6058 household style).
	 * Manual resets leave the due date untouched.
	 */
	private function resetCard(RecurrenceRule $rule, bool $manual): Card {
		$this->permissionService->setUserId($rule->getUserId());
		$this->permissionService->checkPermission($this->cardMapper, $rule->getTemplateCardId(), Acl::PERMISSION_EDIT, $rule->getUserId());
		$this->permissionService->checkPermission($this->stackMapper, $rule->getTargetStackId(), Acl::PERMISSION_EDIT, $rule->getUserId());

		$card = $this->cardMapper->find($rule->getTemplateCardId());
		$card->setStackId($rule->getTargetStackId());
		$card->setDone(null);
		$card->setArchived(false);
		if ($rule->getResetCheckboxes() && $card->getDescription() !== null) {
			$card->setDescription($this->uncheckAll($card->getDescription()));
		}
		if (!$manual) {
			$card->setDuedate(new \DateTime('@' . ($rule->getNextRun() ?? time())));
			$card->setNotified(false);
		}
		$card = $this->cardMapper->update($card);
		$this->changeHelper->cardChanged($card->getId());

		$spawnRecord = new RecurrenceSpawn();
		$spawnRecord->setRuleId($rule->getId());
		$spawnRecord->setCardId($card->getId());
		$spawnRecord->setSpawnedAt(time());
		$this->spawnMapper->insert($spawnRecord);

		return $card;
	}

	private function uncheckAll(string $description): string {
		return preg_replace('/^(\s*[-*+]\s+)\[[xX]\]/m', '$1[ ]', $description);
	}

	/**
	 * A card counts as open while it exists, is not marked done,
	 * and is neither archived nor deleted.
	 */
	private function isCardOpen(int $cardId): bool {
		try {
			$card = $this->cardMapper->find($cardId);
		} catch (DoesNotExistException $e) {
			return false;
		}
		return $card->getDone() === null
			&& !$card->getArchived()
			&& (int)$card->getDeletedAt() === 0;
	}

	/**
	 * Spawn every due rule, then advance its schedule. Advancing happens even
	 * when spawning fails so a broken rule (deleted template card, lost board
	 * access) cannot stall the whole queue; the owner is notified instead.
	 */
	public function runDueRules(): void {
		$now = time();
		foreach ($this->ruleMapper->findDue($now) as $rule) {
			try {
				if ($this->spawn($rule) !== null) {
					$rule->setLastRun($now);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Deck Recurrence: could not spawn card for rule ' . $rule->getId(), [
					'exception' => $e,
				]);
				$this->notifyFailure($rule);
			}

			try {
				$next = $this->nextOccurrence($rule, new \DateTimeImmutable('@' . ($now + 1)));
			} catch (\InvalidArgumentException $e) {
				$this->logger->error('Deck Recurrence: invalid RRULE on rule ' . $rule->getId(), [
					'exception' => $e,
				]);
				$next = null;
			}
			$rule->setNextRun($next);
			if ($next === null) {
				$rule->setEnabled(false);
			}
			$this->ruleMapper->update($rule);
		}
	}

	/**
	 * One active notification per rule: an existing one for the same rule
	 * is replaced rather than piled onto every 15 minutes.
	 */
	private function notifyFailure(RecurrenceRule $rule): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($rule->getUserId())
				->setObject('rule', (string)$rule->getId());
			$this->notificationManager->markProcessed($notification);

			$notification->setDateTime(new \DateTime())
				->setSubject('spawn_failed', ['ruleId' => $rule->getId()]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->debug('Deck Recurrence: could not notify owner of rule ' . $rule->getId(), [
				'exception' => $e,
			]);
		}
	}

	private function timezoneFor(string $userId): \DateTimeZone {
		$timezone = $this->config->getUserValue($userId, 'core', 'timezone', '');
		if ($timezone !== '') {
			try {
				return new \DateTimeZone($timezone);
			} catch (\Exception $e) {
				// fall through to server default
			}
		}
		return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
	}
}
