ALTER TABLE batches
    MODIFY COLUMN status ENUM('В наличии', 'Реализована', 'Списана', 'Перемещено на СБ', 'Нет в наличии')
    NOT NULL DEFAULT 'В наличии';

UPDATE batches
SET status = 'Перемещено на СБ'
WHERE status = 'Списана';

UPDATE batches
SET status = 'Нет в наличии'
WHERE status = 'Реализована';

ALTER TABLE batches
    MODIFY COLUMN status ENUM('В наличии', 'Перемещено на СБ', 'Нет в наличии')
    NOT NULL DEFAULT 'В наличии';
