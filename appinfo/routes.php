<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'rule#index', 'url' => '/rules', 'verb' => 'GET'],
		['name' => 'rule#create', 'url' => '/rules', 'verb' => 'POST'],
		['name' => 'rule#update', 'url' => '/rules/{id}', 'verb' => 'PUT'],
		['name' => 'rule#destroy', 'url' => '/rules/{id}', 'verb' => 'DELETE'],
	],
];
