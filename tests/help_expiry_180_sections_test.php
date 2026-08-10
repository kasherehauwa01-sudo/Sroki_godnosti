<?php
declare(strict_types=1);

$html = file_get_contents(__DIR__ . '/../public/index.php');
if (!is_string($html)) {
    throw new RuntimeException('Не удалось прочитать вкладку помощи.');
}

$requiredTexts = [
    'Событие <code>180 дней</code>',
    'Биоактиваторы для выгребных и компостных ям',
    'Газ для зажигалок, горелок и плит',
    'Земля для цветов, рассады',
    'Защита от насекомых',
    'Семена',
    'Удобрения, лекарства для растений',
    'Средства для бассейнов',
    'параметр <code>Раздел</code>',
    'товары без переданного раздела в это уведомление не включаются',
];

foreach ($requiredTexts as $text) {
    if (!str_contains($html, $text)) {
        throw new RuntimeException('Во вкладке помощи отсутствует описание: ' . $text);
    }
}

echo "Проверки справки о событии 180 дней пройдены.\n";
