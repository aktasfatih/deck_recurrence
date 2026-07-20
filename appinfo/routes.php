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
		['name' => 'archive_rule#index', 'url' => '/archive-rules', 'verb' => 'GET'],
		['name' => 'archive_rule#create', 'url' => '/archive-rules', 'verb' => 'POST'],
		['name' => 'archive_rule#update', 'url' => '/archive-rules/{id}', 'verb' => 'PUT'],
		['name' => 'archive_rule#run', 'url' => '/archive-rules/{id}/run', 'verb' => 'POST'],
		['name' => 'archive_rule#destroy', 'url' => '/archive-rules/{id}', 'verb' => 'DELETE'],
	],
	'ocs' => [
		['name' => 'rule_api#index', 'url' => '/api/v1/rules', 'verb' => 'GET'],
		['name' => 'rule_api#create', 'url' => '/api/v1/rules', 'verb' => 'POST'],
		['name' => 'rule_api#show', 'url' => '/api/v1/rules/{id}', 'verb' => 'GET'],
		['name' => 'rule_api#update', 'url' => '/api/v1/rules/{id}', 'verb' => 'PUT'],
		['name' => 'rule_api#destroy', 'url' => '/api/v1/rules/{id}', 'verb' => 'DELETE'],
		['name' => 'rule_api#spawn', 'url' => '/api/v1/rules/{id}/spawn', 'verb' => 'POST'],
		['name' => 'archive_rule_api#index', 'url' => '/api/v1/archive-rules', 'verb' => 'GET'],
		['name' => 'archive_rule_api#create', 'url' => '/api/v1/archive-rules', 'verb' => 'POST'],
		['name' => 'archive_rule_api#show', 'url' => '/api/v1/archive-rules/{id}', 'verb' => 'GET'],
		['name' => 'archive_rule_api#update', 'url' => '/api/v1/archive-rules/{id}', 'verb' => 'PUT'],
		['name' => 'archive_rule_api#run', 'url' => '/api/v1/archive-rules/{id}/run', 'verb' => 'POST'],
		['name' => 'archive_rule_api#destroy', 'url' => '/api/v1/archive-rules/{id}', 'verb' => 'DELETE'],
	],
];
