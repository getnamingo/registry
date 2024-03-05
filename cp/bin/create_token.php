<?php

require __DIR__ . '/../vendor/autoload.php';

use Ramsey\Uuid\Uuid;

$uniqueIdentifier = Uuid::uuid4()->toString();

echo $uniqueIdentifier . PHP_EOL;