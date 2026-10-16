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

namespace App\Security;

final class PasswordHasher
{
    public const OPTIONS = [
        'memory_cost' => 64 * 1024,
        'time_cost' => 3,
        'threads' => 1,
    ];

    private function __construct()
    {
    }

    public static function hash(
        #[\SensitiveParameter] string $password
    ): string {
        return \password_hash(
            $password,
            \PASSWORD_ARGON2ID,
            self::OPTIONS
        );
    }
}