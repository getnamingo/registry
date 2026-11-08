<?php

/*
 * Namingo Registry - manual, read-only renewal-volume forecast.
 * SPDX-License-Identifier: MIT
 * Run from the repository root: php automation/renewal-prediction.php
 */

use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Regressors\RegressionTree;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';
$c = require __DIR__ . '/config.php';

// One row = one completed calendar month; current-month activity is not training data.
$month = new DateTimeImmutable('first day of this month 00:00:00');
$start = $month->modify('-36 months');
$end = $month->modify('+1 month');
$monthKeys = [];
for ($i = 0; $i < 37; $i++) {
    $monthKeys[$start->modify("+$i months")->format('Y-m')] = $i;
}

// MariaDB and PostgreSQL both support EXTRACT(). All queries are read-only.
$dsn = "{$c['db_type']}:host={$c['db_host']};dbname={$c['db_database']};port={$c['db_port']}";
try {
    $db = new PDO($dsn, $c['db_username'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $names = $db->query('SELECT id, name FROM registrar')->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmt = $db->prepare(<<<'SQL'
SELECT registrar_id, EXTRACT(YEAR FROM date) AS yr,
       EXTRACT(MONTH FROM date) AS mon, COUNT(*) AS total
FROM statement
WHERE command = 'renew' AND date >= :start AND date < :end
GROUP BY registrar_id, EXTRACT(YEAR FROM date), EXTRACT(MONTH FROM date)
SQL);
    $stmt->execute([
        ':start' => $start->format('Y-m-d H:i:s'),
        ':end' => $end->format('Y-m-d H:i:s'),
    ]);
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = sprintf('%04d-%02d', $row['yr'], $row['mon']);
        $index = $monthKeys[$key] ?? null;
        if ($index !== null) {
            $counts[(int) $row['registrar_id']][$index] = (int) $row['total'];
        }
    }
} catch (PDOException $e) {
    fwrite(STDERR, "Unable to read registry transactions: {$e->getMessage()}\n");
    exit(1);
}

function features(array $values, int $i): array
{
    return [
        (float) $values[$i - 1],
        array_sum(array_slice($values, $i - 3, 3)) / 3,
        (float) $values[$i - 12],
    ];
}

function recentAverage(array $values, int $i): float
{
    return array_sum(array_slice($values, max(0, $i - 3), min(3, $i))) / min(3, $i);
}

function predictRenewals(array $values): array
{
    $n = count($values);
    $baseline = recentAverage($values, $n);
    if ($n < 24 || array_sum($values) < 12) {
        return [$baseline, '3-month average'];
    }

    // Last 3 completed months are held out chronologically, not randomly.
    $samples = $labels = [];
    for ($i = 12; $i < $n - 3; $i++) {
        $samples[] = features($values, $i);
        $labels[] = (float) $values[$i];
    }
    $model = new RegressionTree(3, 3);
    $model->train(new Labeled($samples, $labels));

    $mlError = $baselineError = 0.0;
    for ($i = $n - 3; $i < $n; $i++) {
        $ml = $model->predict(new Unlabeled([features($values, $i)]))[0];
        $mlError += abs($ml - $values[$i]);
        $baselineError += abs(recentAverage($values, $i) - $values[$i]);
    }
    if ($mlError >= $baselineError) {
        return [$baseline, '3-month average'];
    }

    // Refit with all completed months after selecting the model.
    for ($i = $n - 3; $i < $n; $i++) {
        $samples[] = features($values, $i);
        $labels[] = (float) $values[$i];
    }
    $model = new RegressionTree(3, 3);
    $model->train(new Labeled($samples, $labels));

    return [max(0.0, $model->predict(new Unlabeled([features($values, $n)]))[0]), 'Rubix ML tree'];
}

$results = [];
foreach ($counts as $id => $monthly) {
    $series = array_replace(array_fill(0, 37, 0), $monthly);
    $first = array_search(true, array_map(static fn ($v) => $v > 0, array_slice($series, 0, 36)), true);
    if ($first === false) {
        continue; // No completed-month history for this registrar.
    }
    $completed = array_slice($series, $first, 36 - $first);
    [$forecast, $method] = predictRenewals($completed);
    $results[] = [
        'name' => $names[$id] ?? "Registrar #$id",
        'forecast' => max($series[36], 0, (int) round($forecast)),
        'actual' => $series[36],
        'method' => $method,
    ];
}

usort($results, static fn ($a, $b) => $b['forecast'] <=> $a['forecast']);
printf("Explicit renewals forecast for %s (entire month)\n", $month->format('F Y'));
echo "Only statement.command='renew'; automatic renewals are excluded.\n\n";
printf("%-36.36s %9s %9s  %s\n", 'Registrar', 'Forecast', 'Actual MTD', 'Method');
echo str_repeat('-', 80), "\n";
$predictedTotal = $actualTotal = 0;
foreach ($results as $row) {
    $predictedTotal += $row['forecast'];
    $actualTotal += $row['actual'];
    printf("%-36.36s %9d %9d  %s\n", $row['name'], $row['forecast'], $row['actual'], $row['method']);
}
echo str_repeat('-', 80), "\n";
printf("%-36s %9d %9d\n", 'Total', $predictedTotal, $actualTotal);
if (!$results) {
    echo "No completed-month explicit renewal history; cannot forecast yet.\n";
}
echo "Forecasts are floored at actual MTD counts; not per-domain renewal probabilities.\n";
