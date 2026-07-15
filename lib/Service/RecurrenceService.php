<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Service;

use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\Db\RecurrenceRuleMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Recur\RRuleIterator;

class RecurrenceService {
	public function __construct(
		private RecurrenceRuleMapper $ruleMapper,
		private CardService $cardService,
		private CardMapper $cardMapper,
		private ChangeHelper $changeHelper,
		private PermissionService $permissionService,
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
	 * Clone the rule's template card into its target stack and stamp the
	 * occurrence time as the new card's due date. Permission checks run as
	 * the rule's owner, so revoked board access disables spawning naturally.
	 *
	 * @throws \OCA\Deck\NoPermissionException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function spawn(RecurrenceRule $rule): void {
		$this->permissionService->setUserId($rule->getUserId());

		$card = $this->cardService->cloneCard($rule->getTemplateCardId(), $rule->getTargetStackId());

		$occurrence = $rule->getNextRun() ?? time();
		$fresh = $this->cardMapper->find($card->getId());
		$fresh->setDuedate(new \DateTime('@' . $occurrence));
		$this->cardMapper->update($fresh);
		$this->changeHelper->cardChanged($card->getId());
	}

	/**
	 * Spawn every due rule, then advance its schedule. Advancing happens even
	 * when spawning fails so a broken rule (deleted template card, lost board
	 * access) cannot stall the whole queue; the error is logged instead.
	 */
	public function runDueRules(): void {
		$now = time();
		foreach ($this->ruleMapper->findDue($now) as $rule) {
			try {
				$this->spawn($rule);
				$rule->setLastRun($now);
			} catch (\Throwable $e) {
				$this->logger->warning('Deck Recurrence: could not spawn card for rule ' . $rule->getId(), [
					'exception' => $e,
				]);
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
