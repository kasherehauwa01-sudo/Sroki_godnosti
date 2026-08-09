<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../public/fill-stock.php');
if (!is_string($source)) {
    throw new RuntimeException('Не удалось прочитать форму заполнения остатков.');
}

foreach ([
    "eventKey.startsWith('recount_')" => 'Форма должна отдельно распознавать событие пересчета.',
    'Пересчет остатков. Склад' => 'В шапке пересчета должен отображаться склад без общего срока годности.',
    'Заполнение остатков. Склад' => 'В шапке обычной формы должен отображаться склад без общего срока годности.',
    '<th>Срок годности</th>' => 'В таблице должна быть колонка срока годности.',
    'formatItemExpiryRu(item)' => 'Для каждой строки должен форматироваться собственный срок годности.',
] as $fragment => $message) {
    if (!str_contains($source, $fragment)) throw new RuntimeException($message);
}

$recountBranchStart = strpos($source, "if (eventKey.startsWith('recount_'))");
$recountBranchEnd = strpos($source, "\n        // В одном уведомлении", (int)$recountBranchStart);
$recountBranch = substr($source, (int)$recountBranchStart, (int)$recountBranchEnd - (int)$recountBranchStart);
if (str_contains($recountBranch, 'Срок годности') || str_contains($recountBranch, 'срок годности')) {
    throw new RuntimeException('Шапка формы пересчета не должна упоминать срок годности.');
}
if (str_contains($source, 'Срок годности партии истекает')) {
    throw new RuntimeException('Форма заполнения не должна содержать общий текст об истечении срока годности.');
}

echo "Проверки формы пересчета пройдены.\n";
