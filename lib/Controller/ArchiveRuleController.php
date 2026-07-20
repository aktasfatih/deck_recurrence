<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Controller;

use OCA\Deck\NoPermissionException;
use OCA\DeckRecurrence\Db\ArchiveRule;
use OCA\DeckRecurrence\NotFoundException;
use OCA\DeckRecurrence\Service\ArchiveRuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ArchiveRuleController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArchiveRuleService $ruleService,
		// null for guests: construction happens before the auth middleware
		// rejects them, so this must not be a hard string
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse($this->ruleService->listRules($this->userId));
	}

	#[NoAdminRequired]
	public function create(int $boardId, ?int $stackId = null, string $mode = ArchiveRule::MODE_DONE_FOR, int $threshold = 0): JSONResponse {
		return $this->respond(fn () => $this->ruleService->create(
			$this->userId, $boardId, $stackId, $mode, $threshold,
		));
	}

	#[NoAdminRequired]
	public function update(int $id, int $boardId, bool $enabled, ?int $stackId = null, string $mode = ArchiveRule::MODE_DONE_FOR, int $threshold = 0): JSONResponse {
		return $this->respond(fn () => $this->ruleService->update(
			$id, $this->userId, $boardId, $stackId, $mode, $threshold, $enabled,
		));
	}

	#[NoAdminRequired]
	public function run(int $id): JSONResponse {
		return $this->respond(fn () => ['archived' => $this->ruleService->runNow($id, $this->userId)]);
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
			return new JSONResponse(['message' => 'No permission for this board or stack'], Http::STATUS_FORBIDDEN);
		} catch (NotFoundException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}
}
