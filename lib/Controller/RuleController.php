<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Controller;

use OCA\Deck\NoPermissionException;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\NotFoundException;
use OCA\DeckRecurrence\Service\RuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RuleController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private RuleService $ruleService,
		private string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse($this->ruleService->listRules($this->userId));
	}

	#[NoAdminRequired]
	public function create(int $templateCardId, int $targetStackId, string $rrule, int $dtstart, bool $skipIfOpen = false, bool $resetCheckboxes = false, string $mode = RecurrenceRule::MODE_CLONE): JSONResponse {
		return $this->respond(fn () => $this->ruleService->create(
			$this->userId, $templateCardId, $targetStackId, $rrule, $dtstart, $skipIfOpen, $resetCheckboxes, $mode,
		));
	}

	#[NoAdminRequired]
	public function update(int $id, int $templateCardId, int $targetStackId, string $rrule, int $dtstart, bool $enabled, bool $skipIfOpen = false, bool $resetCheckboxes = false, string $mode = RecurrenceRule::MODE_CLONE): JSONResponse {
		return $this->respond(fn () => $this->ruleService->update(
			$id, $this->userId, $templateCardId, $targetStackId, $rrule, $dtstart, $enabled, $skipIfOpen, $resetCheckboxes, $mode,
		));
	}

	#[NoAdminRequired]
	public function spawn(int $id): JSONResponse {
		return $this->respond(fn () => $this->ruleService->spawnNow($id, $this->userId));
	}

	#[NoAdminRequired]
	public function destroy(int $id): JSONResponse {
		return $this->respond(fn () => $this->ruleService->delete($id, $this->userId));
	}

	private function respond(callable $action): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NoPermissionException $e) {
			return new JSONResponse(['message' => 'No permission for this card or stack'], Http::STATUS_FORBIDDEN);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}
}
