<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../public/index.php');
if (!is_string($page)) throw new RuntimeException('Не удалось прочитать вкладку помощи.');

foreach ([
    'data-help-tab="primary-invoice-import"',
    'data-help-panel="primary-invoice-import"',
    'Инструкция по экспорту XLS в первичный счет',
    'Для экспорта в первичный счет',
    '7-Zip → Распаковать',
    'Сервис → Дополнительные возможности → Импорт счетов',
    'Проверьте корректность данных перед созданием счета.',
    'На основании созданного первичного счета оформите списание товара.',
    'если в ZIP-архиве находится несколько XLS-файлов',
] as $fragment) {
    if (!str_contains($page, $fragment)) throw new RuntimeException('В инструкции по импорту отсутствует: ' . $fragment);
}

echo "Проверки инструкции по импорту в первичный счет пройдены.\n";
