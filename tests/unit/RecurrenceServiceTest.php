<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\DeckRecurrence\Tests\Unit;

use OCA\Deck\Activity\ActivityManager;
use OCA\Deck\Db\AssignmentMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\LabelMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\DeckRecurrence\Db\RecurrenceRule;
use OCA\DeckRecurrence\Db\RecurrenceRuleMapper;
use OCA\DeckRecurrence\Db\RecurrenceSpawnMapper;
use OCA\DeckRecurrence\Service\RecurrenceService;
use OCP\IConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RecurrenceServiceTest extends TestCase {
	private function service(string $timezone = 'UTC'): RecurrenceService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($timezone);
		return new RecurrenceService(
			$this->createMock(RecurrenceRuleMapper::class),
			$this->createMock(RecurrenceSpawnMapper::class),
			$this->createMock(CardMapper::class),
			$this->createMock(StackMapper::class),
			$this->createMock(LabelMapper::class),
			$this->createMock(AssignmentMapper::class),
			$this->createMock(ChangeHelper::class),
			$this->createMock(PermissionService::class),
			$this->createMock(ActivityManager::class),
			$this->createMock(INotificationManager::class),
			$config,
			new NullLogger(),
		);
	}

	private function rule(string $rrule, \DateTimeImmutable $dtstart): RecurrenceRule {
		$rule = new RecurrenceRule();
		$rule->setUserId('tester');
		$rule->setRrule($rrule);
		$rule->setDtstart($dtstart->getTimestamp());
		return $rule;
	}

	public function testNextOccurrenceIsAtOrAfterTheGivenMoment(): void {
		$dtstart = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
		$rule = $this->rule('FREQ=DAILY', $dtstart);

		$after = new \DateTimeImmutable('2026-07-10 09:00:00', new \DateTimeZone('UTC'));
		$next = $this->service()->nextOccurrence($rule, $after);

		$this->assertSame(
			(new \DateTimeImmutable('2026-07-10 10:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
			$next,
		);
	}

	public function testWeeklyOccurrenceKeepsLocalTimeAcrossDstStart(): void {
		$berlin = new \DateTimeZone('Europe/Berlin');
		// Monday 09:00 local, the week before the EU spring DST switch
		$dtstart = new \DateTimeImmutable('2026-03-23 09:00:00', $berlin);
		$rule = $this->rule('FREQ=WEEKLY;BYDAY=MO', $dtstart);

		$next = $this->service('Europe/Berlin')->nextOccurrence(
			$rule,
			new \DateTimeImmutable('2026-03-28 00:00:00', $berlin),
		);

		// The Monday after the switch must still be 09:00 local time,
		// even though the UTC offset changed from +01:00 to +02:00.
		$expected = new \DateTimeImmutable('2026-03-30 09:00:00', $berlin);
		$this->assertSame('+02:00', $expected->format('P'));
		$this->assertSame($expected->getTimestamp(), $next);
	}

	public function testWeeklyOccurrenceKeepsLocalTimeAcrossDstEnd(): void {
		$berlin = new \DateTimeZone('Europe/Berlin');
		// Monday 09:00 local, the week before the EU autumn switch (2026-10-25)
		$dtstart = new \DateTimeImmutable('2026-10-19 09:00:00', $berlin);
		$rule = $this->rule('FREQ=WEEKLY;BYDAY=MO', $dtstart);

		$next = $this->service('Europe/Berlin')->nextOccurrence(
			$rule,
			new \DateTimeImmutable('2026-10-24 00:00:00', $berlin),
		);

		$expected = new \DateTimeImmutable('2026-10-26 09:00:00', $berlin);
		$this->assertSame('+01:00', $expected->format('P'));
		$this->assertSame($expected->getTimestamp(), $next);
	}

	public function testCountExhaustionReturnsNull(): void {
		$dtstart = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
		$rule = $this->rule('FREQ=DAILY;COUNT=3', $dtstart);

		$next = $this->service()->nextOccurrence(
			$rule,
			new \DateTimeImmutable('2026-07-10 00:00:00', new \DateTimeZone('UTC')),
		);

		$this->assertNull($next);
	}

	public function testUntilInThePastReturnsNull(): void {
		$dtstart = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
		$rule = $this->rule('FREQ=DAILY;UNTIL=20260705T000000Z', $dtstart);

		$next = $this->service()->nextOccurrence(
			$rule,
			new \DateTimeImmutable('2026-07-10 00:00:00', new \DateTimeZone('UTC')),
		);

		$this->assertNull($next);
	}

	public function testInvalidRruleThrowsInvalidArgumentException(): void {
		$rule = $this->rule('FREQ=BOGUS', new \DateTimeImmutable('2026-07-01 10:00:00'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->nextOccurrence($rule, new \DateTimeImmutable('2026-07-10 00:00:00'));
	}

	public function testDueDateDefaultsToTheOccurrenceTime(): void {
		$rule = $this->rule('FREQ=DAILY', new \DateTimeImmutable('2026-07-01 10:00:00'));

		$due = $this->service()->dueDateFor($rule, 1780000000);

		$this->assertSame(1780000000, $due->getTimestamp());
	}

	public function testDueDateHonorsTheOffset(): void {
		$rule = $this->rule('FREQ=DAILY', new \DateTimeImmutable('2026-07-01 10:00:00'));
		$rule->setDueOffset(7 * 86400);

		$due = $this->service()->dueDateFor($rule, 1780000000);

		$this->assertSame(1780000000 + 7 * 86400, $due->getTimestamp());
	}

	public function testDueNoneYieldsNoDueDate(): void {
		$rule = $this->rule('FREQ=DAILY', new \DateTimeImmutable('2026-07-01 10:00:00'));
		$rule->setDueOffset(RecurrenceRule::DUE_NONE);

		$this->assertNull($this->service()->dueDateFor($rule, 1780000000));
	}

	public function testInvalidTimezonePreferenceFallsBackToServerDefault(): void {
		$dtstart = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
		$rule = $this->rule('FREQ=DAILY', $dtstart);

		$next = $this->service('Not/AZone')->nextOccurrence(
			$rule,
			new \DateTimeImmutable('2026-07-10 09:00:00', new \DateTimeZone('UTC')),
		);

		$this->assertNotNull($next);
	}
}
