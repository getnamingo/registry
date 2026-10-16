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

require __DIR__ . '/../vendor/autoload.php';

use Ramsey\Uuid\Uuid;

$uniqueIdentifier = Uuid::uuid4()->toString();

echo $uniqueIdentifier . PHP_EOL;