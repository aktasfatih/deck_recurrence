<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Controller;

use OCA\Deck\NoPermissionException;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\NotFoundException;
use OCA\DeckRecurrence\Service\RuleService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * OCS API for scripts and integrations, usable with app passwords:
 *
 *   /ocs/v2.php/apps/deck_recurrence/api/v1/rules
 */
class RuleApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private RuleService $ruleService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->ruleService->listRules($this->userId));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->respond(fn () => $this->ruleService->find($id, $this->userId));
	}

	#[NoAdminRequired]
	public function create(int $templateCardId, int $targetStackId, string $rrule, int $dtstart, bool $skipIfOpen = false, bool $resetCheckboxes = false, string $mode = RecurrenceRule::MODE_CLONE): DataResponse {
		return $this->respond(fn () => $this->ruleService->create(
			$this->userId, $templateCardId, $targetStackId, $rrule, $dtstart, $skipIfOpen, $resetCheckboxes, $mode,
		));
	}

	#[NoAdminRequired]
	public function update(int $id, int $templateCardId, int $targetStackId, string $rrule, int $dtstart, bool $enabled, bool $skipIfOpen = false, bool $resetCheckboxes = false, string $mode = RecurrenceRule::MODE_CLONE): DataResponse {
		return $this->respond(fn () => $this->ruleService->update(
			$id, $this->userId, $templateCardId, $targetStackId, $rrule, $dtstart, $enabled, $skipIfOpen, $resetCheckboxes, $mode,
		));
	}

	#[NoAdminRequired]
	public function spawn(int $id): DataResponse {
		return $this->respond(fn () => $this->ruleService->spawnNow($id, $this->userId));
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		return $this->respond(fn () => $this->ruleService->delete($id, $this->userId));
	}

	private function respond(callable $action): DataResponse {
		try {
			return new DataResponse($action());
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		} catch (NoPermissionException $e) {
			throw new OCSForbiddenException('No permission for this card or stack');
		} catch (NotFoundException $e) {
			throw new OCSNotFoundException($e->getMessage());
		}
	}
}
