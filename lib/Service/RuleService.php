<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Service;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\Db\RecurrenceRuleMapper;
use OCA\DeckRecurrence\Db\RecurrenceSpawnMapper;
use OCA\DeckRecurrence\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Rule management shared by the web UI controller and the OCS API.
 *
 * Error contract: \InvalidArgumentException for invalid input (400),
 * OCA\Deck\NoPermissionException for missing access (403), and
 * OCA\DeckRecurrence\NotFoundException for missing entities (404).
 */
class RuleService {
	public function __construct(
		private RecurrenceRuleMapper $ruleMapper,
		private RecurrenceSpawnMapper $spawnMapper,
		private RecurrenceService $recurrenceService,
		private PermissionService $permissionService,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
	) {
	}

	/** @return RecurrenceRule[] */
	public function listRules(string $userId): array {
		return $this->ruleMapper->findAllForUser($userId);
	}

	public function find(int $id, string $userId): RecurrenceRule {
		try {
			return $this->ruleMapper->find($id, $userId);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException('Rule not found');
		}
	}

	public function create(
		string $userId,
		int $templateCardId,
		int $targetStackId,
		string $rrule,
		int $dtstart,
		bool $skipIfOpen,
		bool $resetCheckboxes,
		string $mode,
	): RecurrenceRule {
		$this->validate($templateCardId, $targetStackId, $mode);

		$rule = new RecurrenceRule();
		$rule->setUserId($userId);
		$rule->setTemplateCardId($templateCardId);
		$rule->setTargetStackId($targetStackId);
		$rule->setRrule(trim($rrule));
		$rule->setDtstart($dtstart);
		$rule->setEnabled(true);
		$rule->setSkipIfOpen($skipIfOpen);
		$rule->setResetCheckboxes($resetCheckboxes);
		$rule->setMode($mode);
		$rule->setCreatedAt(time());

		$next = $this->nextOccurrence($rule);
		if ($next === null) {
			throw new \InvalidArgumentException('The recurrence rule has no upcoming occurrences');
		}
		$rule->setNextRun($next);

		return $this->ruleMapper->insert($rule);
	}

	public function update(
		int $id,
		string $userId,
		int $templateCardId,
		int $targetStackId,
		string $rrule,
		int $dtstart,
		bool $enabled,
		bool $skipIfOpen,
		bool $resetCheckboxes,
		string $mode,
	): RecurrenceRule {
		$rule = $this->find($id, $userId);
		$this->validate($templateCardId, $targetStackId, $mode);

		$rule->setTemplateCardId($templateCardId);
		$rule->setTargetStackId($targetStackId);
		$rule->setRrule(trim($rrule));
		$rule->setDtstart($dtstart);
		$rule->setEnabled($enabled);
		$rule->setSkipIfOpen($skipIfOpen);
		$rule->setResetCheckboxes($resetCheckboxes);
		$rule->setMode($mode);
		$rule->setNextRun($enabled ? $this->nextOccurrence($rule) : null);

		return $this->ruleMapper->update($rule);
	}

	public function delete(int $id, string $userId): RecurrenceRule {
		$rule = $this->find($id, $userId);
		$this->spawnMapper->deleteForRule($rule->getId());
		return $this->ruleMapper->delete($rule);
	}

	/**
	 * Manual spawn; bypasses skip-if-open and sets no due date.
	 */
	public function spawnNow(int $id, string $userId): Card {
		$rule = $this->find($id, $userId);
		// Deck reports missing cards as "no permission" to avoid leaking
		// their existence, but this rule is the requester's own — tell
		// them their template is gone instead.
		try {
			$this->cardMapper->find($rule->getTemplateCardId());
		} catch (DoesNotExistException $e) {
			throw new NotFoundException('The template card no longer exists');
		}
		return $this->recurrenceService->spawn($rule, true);
	}

	private function nextOccurrence(RecurrenceRule $rule): ?int {
		try {
			return $this->recurrenceService->nextOccurrence($rule, new \DateTimeImmutable());
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('Invalid recurrence rule: ' . $e->getMessage(), 0, $e);
		}
	}

	private function validate(int $templateCardId, int $targetStackId, string $mode): void {
		if (!in_array($mode, [RecurrenceRule::MODE_CLONE, RecurrenceRule::MODE_RESET], true)) {
			throw new \InvalidArgumentException('Invalid rule mode');
		}
		// Reset mode rewrites the card itself, so it needs edit rights on it
		$cardPermission = $mode === RecurrenceRule::MODE_RESET ? Acl::PERMISSION_EDIT : Acl::PERMISSION_READ;
		try {
			$this->permissionService->checkPermission($this->cardMapper, $templateCardId, $cardPermission);
			$this->permissionService->checkPermission($this->stackMapper, $targetStackId, Acl::PERMISSION_EDIT);
		} catch (DoesNotExistException $e) {
			throw new NotFoundException('Card or stack not found');
		}
	}
}
