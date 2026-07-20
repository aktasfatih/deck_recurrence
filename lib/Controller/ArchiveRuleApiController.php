<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Controller;

use OCA\Deck\NoPermissionException;
use OCA\DeckRecurrence\Db\ArchiveRule;
use OCA\DeckRecurrence\NotFoundException;
use OCA\DeckRecurrence\Service\ArchiveRuleService;
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
 *   /ocs/v2.php/apps/deck_recurrence/api/v1/archive-rules
 */
class ArchiveRuleApiController extends OCSController {
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
	public function index(): DataResponse {
		return new DataResponse($this->ruleService->listRules($this->userId));
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->respond(fn () => $this->ruleService->find($id, $this->userId));
	}

	#[NoAdminRequired]
	public function create(int $boardId, ?int $stackId = null, string $mode = ArchiveRule::MODE_DONE_FOR, int $threshold = 0): DataResponse {
		return $this->respond(fn () => $this->ruleService->create(
			$this->userId, $boardId, $stackId, $mode, $threshold,
		));
	}

	#[NoAdminRequired]
	public function update(int $id, int $boardId, bool $enabled, ?int $stackId = null, string $mode = ArchiveRule::MODE_DONE_FOR, int $threshold = 0): DataResponse {
		return $this->respond(fn () => $this->ruleService->update(
			$id, $this->userId, $boardId, $stackId, $mode, $threshold, $enabled,
		));
	}

	#[NoAdminRequired]
	public function run(int $id): DataResponse {
		return $this->respond(fn () => ['archived' => $this->ruleService->runNow($id, $this->userId)]);
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
			throw new OCSForbiddenException('No permission for this board or stack');
		} catch (NotFoundException $e) {
			throw new OCSNotFoundException($e->getMessage());
		}
	}
}
