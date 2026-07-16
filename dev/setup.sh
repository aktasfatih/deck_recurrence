#!/bin/sh
# SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Boot the dev Nextcloud, install Deck and enable deck_recurrence.
set -eu
cd "$(dirname "$0")"

OCC="docker exec -u www-data deck_recurrence-dev php occ"

docker compose up -d

echo "Waiting for Nextcloud to finish installing..."
for i in $(seq 1 60); do
	if curl -sf http://localhost:8890/status.php 2>/dev/null | grep -q '"installed":true'; then
		break
	fi
	sleep 5
done
curl -sf http://localhost:8890/status.php | grep -q '"installed":true' || {
	echo "Nextcloud did not come up; check: docker logs deck_recurrence-dev" >&2
	exit 1
}

# Docker pre-creates the mountpoint parent as root; hand it to the web user
docker exec deck_recurrence-dev chown www-data:www-data /var/www/html/custom_apps

$OCC app:install deck 2>/dev/null || $OCC app:enable deck
$OCC app:enable deck_recurrence
$OCC background:cron

echo
echo "Ready: http://localhost:8890  (admin / admin)"
echo "Run the spawn job:  docker exec -u www-data deck_recurrence-dev php occ background-job:list --class 'OCA\\DeckRecurrence\\Cron\\SpawnRecurringCards'"
