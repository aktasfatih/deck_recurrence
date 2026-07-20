<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A board-hygiene rule: archive done cards in a board (or a single
 * stack) once they cross an age threshold.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getBoardId()
 * @method void setBoardId(int $boardId)
 * @method int|null getStackId()
 * @method void setStackId(?int $stackId)
 * @method string getMode()
 * @method void setMode(string $mode)
 * @method int getThreshold()
 * @method void setThreshold(int $threshold)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int|null getLastRun()
 * @method void setLastRun(?int $lastRun)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class ArchiveRule extends Entity implements \JsonSerializable {
	/** Archive once the card has been marked done for `threshold` seconds */
	public const MODE_DONE_FOR = 'done_for';
	/** Archive done cards once they are `threshold` seconds old (creation time) */
	public const MODE_MIN_AGE = 'min_age';

	protected string $userId = '';
	protected int $boardId = 0;
	protected ?int $stackId = null;
	protected string $mode = self::MODE_DONE_FOR;
	protected int $threshold = 0;
	protected bool $enabled = true;
	protected ?int $lastRun = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('boardId', 'integer');
		$this->addType('stackId', 'integer');
		$this->addType('threshold', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('lastRun', 'integer');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'boardId' => $this->getBoardId(),
			'stackId' => $this->getStackId(),
			'mode' => $this->getMode(),
			'threshold' => $this->getThreshold(),
			'enabled' => $this->getEnabled(),
			'lastRun' => $this->getLastRun(),
		];
	}
}
