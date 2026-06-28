<?php

declare(strict_types=1);

echo "1 before api\n";

require_once __DIR__ . '/../public/api.php';

echo "2 after api\n";

require_once __DIR__ . '/../app/auto_importer.php';

echo "3 after importer\n";

try {

    echo "4 before db\n";

    $pdo = getDatabaseConnection();

    echo "5 after db\n";

    $once = in_array('--once', $argv ?? [], true);

    echo "6 before run\n";

    $result = runAutoImport($pdo, $once);

    echo "7 after run\n";

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

    exit(($result['ok'] ?? false) ? 0 : 1);

} catch (Throwable $error) {

    echo "ERROR: " . $error->getMessage() . PHP_EOL;
    echo $error->getTraceAsString() . PHP_EOL;

    exit(1);
}
