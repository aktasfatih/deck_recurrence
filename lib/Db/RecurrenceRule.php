<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getTemplateCardId()
 * @method void setTemplateCardId(int $templateCardId)
 * @method int getTargetStackId()
 * @method void setTargetStackId(int $targetStackId)
 * @method string getRrule()
 * @method void setRrule(string $rrule)
 * @method int getDtstart()
 * @method void setDtstart(int $dtstart)
 * @method int|null getNextRun()
 * @method void setNextRun(?int $nextRun)
 * @method int|null getLastRun()
 * @method void setLastRun(?int $lastRun)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method bool getSkipIfOpen()
 * @method void setSkipIfOpen(bool $skipIfOpen)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class RecurrenceRule extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected int $templateCardId = 0;
	protected int $targetStackId = 0;
	protected string $rrule = '';
	protected int $dtstart = 0;
	protected ?int $nextRun = null;
	protected ?int $lastRun = null;
	protected bool $enabled = true;
	protected bool $skipIfOpen = false;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('templateCardId', 'integer');
		$this->addType('targetStackId', 'integer');
		$this->addType('dtstart', 'integer');
		$this->addType('nextRun', 'integer');
		$this->addType('lastRun', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('skipIfOpen', 'boolean');
		$this->addType('createdAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'templateCardId' => $this->getTemplateCardId(),
			'targetStackId' => $this->getTargetStackId(),
			'rrule' => $this->getRrule(),
			'dtstart' => $this->getDtstart(),
			'nextRun' => $this->getNextRun(),
			'lastRun' => $this->getLastRun(),
			'enabled' => $this->getEnabled(),
			'skipIfOpen' => $this->getSkipIfOpen(),
		];
	}
}
