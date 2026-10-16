<?php

/*
 * Namingo Registry
 *
 * Copyright (c) 2023-2026 Taras Kondratyuk
 * Copyright (c) 2025-2026 Namingo contributors
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the MIT License.
 * See the LICENSE file distributed with this software for the full license text.
 *
 * SPDX-License-Identifier: MIT
 */

$c = require_once 'config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/archive-logs.log';
$log = setupLogger($logFilePath, 'ARCHIVE_LOGS');
$log->info('job started.');

archiveOldLogs($logFilePath);

$log->info('job finished successfully.');