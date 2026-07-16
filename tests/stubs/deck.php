<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

// Minimal stand-ins for Deck's classes so unit tests can mock the
// RecurrenceService constructor without a Deck installation.

namespace OCA\Deck {
	class NoPermissionException extends \Exception {
	}
}

namespace OCA\Deck\Db {
	class Acl {
		public const PERMISSION_READ = 0;
		public const PERMISSION_EDIT = 1;
		public const PERMISSION_MANAGE = 2;
		public const PERMISSION_SHARE = 3;
	}
	class Card {
	}
	class Assignment {
	}
	class CardMapper {
	}
	class StackMapper {
	}
	class LabelMapper {
	}
	class AssignmentMapper {
	}
	class ChangeHelper {
	}
}

namespace OCA\Deck\Service {
	class PermissionService {
	}
}

namespace OCA\Deck\Activity {
	class ActivityManager {
		public const DECK_OBJECT_CARD = 'deckCard';
		public const SUBJECT_CARD_CREATE = 'card_create';
	}
}
