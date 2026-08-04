<?php
/**
 * Voras Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Voras Team <help@namingo.org>
 * @copyright  Copyright (c) 2026 Voras
 * @license    MIT License
 * @link       https://github.com/atriohq/foundry
 */

require __DIR__ . '/../vendor/autoload.php';

use Ramsey\Uuid\Uuid;

$uniqueIdentifier = Uuid::uuid4()->toString();

echo $uniqueIdentifier . PHP_EOL;