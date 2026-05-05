<?php

function ensure_meter_replacements_table(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS meter_replacements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tank_id INT NOT NULL,
            reading_type ENUM('normal', 'rejected') NOT NULL DEFAULT 'normal',
            replacement_datetime DATETIME NOT NULL,
            old_reading DECIMAL(14,3) NOT NULL,
            new_reading DECIMAL(14,3) NOT NULL,
            offset_delta DECIMAL(14,3) NOT NULL,
            notes VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tank_type_date (tank_id, reading_type, replacement_datetime)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $conn->query($sql);
    $ensured = true;
}

function get_meter_offset_index(mysqli $conn, array $tankIds, string $readingType = 'normal'): array {
    if (empty($tankIds)) {
        return [];
    }

    ensure_meter_replacements_table($conn);

    $tankIds = array_values(array_unique(array_map('intval', $tankIds)));
    $placeholders = implode(',', array_fill(0, count($tankIds), '?'));
    $types = str_repeat('i', count($tankIds)) . 's';

    $sql = "
        SELECT tank_id, replacement_datetime, offset_delta
        FROM meter_replacements
        WHERE tank_id IN ($placeholders)
          AND reading_type = ?
        ORDER BY tank_id ASC, replacement_datetime ASC, id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $params = array_merge($tankIds, [$readingType]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $index = [];
    foreach ($rows as $row) {
        $tankId = (int)$row['tank_id'];
        if (!isset($index[$tankId])) {
            $index[$tankId] = [];
        }
        $previous = empty($index[$tankId]) ? 0.0 : $index[$tankId][count($index[$tankId]) - 1]['cumulative'];
        $delta = (float)$row['offset_delta'];
        $index[$tankId][] = [
            'datetime' => $row['replacement_datetime'],
            'cumulative' => $previous + $delta,
        ];
    }

    return $index;
}

function get_adjusted_meter_value(int $tankId, string $readingDatetime, float $rawValue, array $offsetIndex): float {
    if (!isset($offsetIndex[$tankId]) || empty($offsetIndex[$tankId])) {
        return $rawValue;
    }

    $offset = 0.0;
    foreach ($offsetIndex[$tankId] as $event) {
        if ($event['datetime'] <= $readingDatetime) {
            $offset = (float)$event['cumulative'];
        } else {
            break;
        }
    }

    return $rawValue + $offset;
}
