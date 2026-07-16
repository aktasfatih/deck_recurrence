<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Controller;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\Db\RecurrenceRuleMapper;
use OCA\DeckRecurrence\Db\RecurrenceSpawnMapper;
use OCA\DeckRecurrence\Service\RecurrenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RuleController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private RecurrenceRuleMapper $ruleMapper,
		private RecurrenceSpawnMapper $spawnMapper,
		private RecurrenceService $recurrenceService,
		private PermissionService $permissionService,
		private CardMapper $cardMapper,
		private StackMapper $stackMapper,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse($this->ruleMapper->findAllForUser($this->userId));
	}

	#[NoAdminRequired]
	public function create(int $templateCardId, int $targetStackId, string $rrule, int $dtstart, bool $skipIfOpen = false, bool $resetCheckboxes = false, string $mode = RecurrenceRule::MODE_CLONE): JSONResponse {
		$error = $this->validate($templateCardId, $targetStackId, $mode);
		if ($error !== null) {
			return $error;
		}

		$rule = new RecurrenceRule();
		$rule->setUserId($this->userId);
		$rule->setTemplateCardId($templateCardId);
		$rule->setTargetStackId($targetStackId);
		$rule->setRrule(trim($rrule));
		$rule->setDtstart($dtstart);
		$rule->setEnabled(true);
		$rule->setSkipIfOpen($skipIfOpen);
		$rule->setResetCheckboxes($resetCheckboxes);
		$rule->setMode($mode);
		$rule->setCreatedAt(time());

		try {
			$rule->setNextRun($this->recurrenceService->nextOccurrence($rule, new \DateTimeImmutable()));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid recurrence rule: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($rule->getNextRun() === null) {
			return new JSONResponse(['message' => 'The recurrence rule has no upcoming occurrences'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->ruleMapper->insert($rule));
	}

	#[NoAdminRequired]
	public function update(int $id, int $templateCardId, int $targetStackId, string $rrule, int $dtstart, bool $enabled, bool $skipIfOpen = false, bool $resetCheckboxes = false, string $mode = RecurrenceRule::MODE_CLONE): JSONResponse {
		try {
			$rule = $this->ruleMapper->find($id, $this->userId);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'Rule not found'], Http::STATUS_NOT_FOUND);
		}

		$error = $this->validate($templateCardId, $targetStackId, $mode);
		if ($error !== null) {
			return $error;
		}

		$rule->setTemplateCardId($templateCardId);
		$rule->setTargetStackId($targetStackId);
		$rule->setRrule(trim($rrule));
		$rule->setDtstart($dtstart);
		$rule->setEnabled($enabled);
		$rule->setSkipIfOpen($skipIfOpen);
		$rule->setResetCheckboxes($resetCheckboxes);
		$rule->setMode($mode);

		try {
			$rule->setNextRun($enabled
				? $this->recurrenceService->nextOccurrence($rule, new \DateTimeImmutable())
				: null);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid recurrence rule: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->ruleMapper->update($rule));
	}

	#[NoAdminRequired]
	public function spawn(int $id): JSONResponse {
		try {
			$rule = $this->ruleMapper->find($id, $this->userId);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'Rule not found'], Http::STATUS_NOT_FOUND);
		}
		// Deck reports missing cards as "no permission" to avoid leaking
		// their existence, but this rule is the requester's own — tell them
		// their template is gone instead.
		try {
			$this->cardMapper->find($rule->getTemplateCardId());
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'The template card no longer exists'], Http::STATUS_NOT_FOUND);
		}
		try {
			$card = $this->recurrenceService->spawn($rule, true);
		} catch (NoPermissionException $e) {
			return new JSONResponse(['message' => 'No permission for this card or stack'], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'The template card no longer exists'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($card);
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		try {
			$rule = $this->ruleMapper->find($id, $this->userId);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'Rule not found'], Http::STATUS_NOT_FOUND);
		}
		$this->spawnMapper->deleteForRule($rule->getId());
		return new JSONResponse($this->ruleMapper->delete($rule));
	}

	private function validate(int $templateCardId, int $targetStackId, string $mode): ?JSONResponse {
		if (!in_array($mode, [RecurrenceRule::MODE_CLONE, RecurrenceRule::MODE_RESET], true)) {
			return new JSONResponse(['message' => 'Invalid rule mode'], Http::STATUS_BAD_REQUEST);
		}
		// Reset mode rewrites the card itself, so it needs edit rights on it
		$cardPermission = $mode === RecurrenceRule::MODE_RESET ? Acl::PERMISSION_EDIT : Acl::PERMISSION_READ;
		try {
			$this->permissionService->checkPermission($this->cardMapper, $templateCardId, $cardPermission);
			$this->permissionService->checkPermission($this->stackMapper, $targetStackId, Acl::PERMISSION_EDIT);
		} catch (NoPermissionException $e) {
			return new JSONResponse(['message' => 'No permission for this card or stack'], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'Card or stack not found'], Http::STATUS_NOT_FOUND);
		}
		return null;
	}
}
