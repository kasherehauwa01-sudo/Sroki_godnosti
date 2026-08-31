-- Срок, указанный без дня в формате мм.гггг, действует до конца месяца.
UPDATE batches
SET expiry_date = LAST_DAY(expiry_date),
    days_left = DATEDIFF(LAST_DAY(expiry_date), CURDATE())
WHERE expiry_full_date = 0
  AND expiry_invalid = 0
  AND expiry_date <> LAST_DAY(expiry_date);
