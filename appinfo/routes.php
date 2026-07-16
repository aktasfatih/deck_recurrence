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
		['name' => 'rule#spawn', 'url' => '/rules/{id}/spawn', 'verb' => 'POST'],
		['name' => 'rule#destroy', 'url' => '/rules/{id}', 'verb' => 'DELETE'],
	],
	'ocs' => [
		['name' => 'rule_api#index', 'url' => '/api/v1/rules', 'verb' => 'GET'],
		['name' => 'rule_api#create', 'url' => '/api/v1/rules', 'verb' => 'POST'],
		['name' => 'rule_api#show', 'url' => '/api/v1/rules/{id}', 'verb' => 'GET'],
		['name' => 'rule_api#update', 'url' => '/api/v1/rules/{id}', 'verb' => 'PUT'],
		['name' => 'rule_api#destroy', 'url' => '/api/v1/rules/{id}', 'verb' => 'DELETE'],
		['name' => 'rule_api#spawn', 'url' => '/api/v1/rules/{id}/spawn', 'verb' => 'POST'],
	],
];
