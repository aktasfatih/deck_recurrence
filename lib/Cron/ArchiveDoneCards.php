<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Cron;

use OCA\DeckRecurrence\Service\ArchiveService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;

class ArchiveDoneCards extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IAppManager $appManager,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 15);
	}

	protected function run($argument): void {
		if (!$this->appManager->isInstalled('deck')) {
			return;
		}
		// Resolved lazily so this job never fails to construct when Deck
		// is disabled — ArchiveService depends on Deck's classes.
		Server::get(ArchiveService::class)->runEnabledRules();
	}
}
