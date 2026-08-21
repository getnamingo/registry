<?php

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