<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Common headers for streaming NDJSON responses
function prepare_stream_headers(): void {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);
    while (ob_get_level() > 0) { ob_end_flush(); }
    ob_implicit_flush(1);
}

function send_event(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    flush();
}

function json_response(array $payload, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function handle_analyze(PDO $pdo): void {
    try {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
        $fromInput = $_POST['from'] ?? ($_POST['timestamp_from'] ?? null);
        $toInput = $_POST['to'] ?? ($_POST['timestamp_to'] ?? null);
        $fromMs = toMillisFromInput(is_string($fromInput) ? $fromInput : null);
        $toMs = toMillisFromInput(is_string($toInput) ? $toInput : null);

        $where = 'id = :id';
        $params = [':id' => $id];
        if ($fromMs !== null && $toMs !== null) {
            $where .= ' AND ts BETWEEN :from AND :to';
            $params[':from'] = $fromMs; $params[':to'] = $toMs;
        } elseif ($fromMs !== null) {
            $where .= ' AND ts >= :from';
            $params[':from'] = $fromMs;
        } elseif ($toMs !== null) {
            $where .= ' AND ts <= :to';
            $params[':to'] = $toMs;
        }

        $sql = 'SELECT COUNT(*) AS cnt, MIN(ts) AS min_ts, MAX(ts) AS max_ts FROM ts_number WHERE ' . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $count = (int) ($row['cnt'] ?? 0);
        $minTs = isset($row['min_ts']) ? (int) $row['min_ts'] : null;
        $maxTs = isset($row['max_ts']) ? (int) $row['max_ts'] : null;

        json_response([
            'ok' => true,
            'id' => $id,
            'count' => $count,
            'min_ts' => $minTs,
            'max_ts' => $maxTs,
            'min_ts_readable' => $minTs !== null ? ms_to_readable($minTs) : null,
            'max_ts_readable' => $maxTs !== null ? ms_to_readable($maxTs) : null,
            'window_from' => $fromMs,
            'window_to' => $toMs,
            'window_from_readable' => $fromMs !== null ? ms_to_readable($fromMs) : null,
            'window_to_readable' => $toMs !== null ? ms_to_readable($toMs) : null,
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'Analyse fehlgeschlagen', 'details' => $e->getMessage()], 500);
    }
}

function handle_test(PDO $pdo): void {
    try {
        prepare_stream_headers();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
        $intervalKey = isset($_POST['intervalKey']) ? (string) $_POST['intervalKey'] : '1h';
        $intervalMs = resolve_interval_ms($intervalKey) ?? (60 * 60 * 1000);
        $fromInput = $_POST['from'] ?? null;
        $toInput = $_POST['to'] ?? null;
        $fromMs = toMillisFromInput($fromInput !== null ? (string) $fromInput : null);
        $toMs = toMillisFromInput($toInput !== null ? (string) $toInput : null);
        if ($fromMs === null || $toMs === null) {
            send_event(['ok' => false, 'error' => 'Bitte gueltige Von/Bis-Daten angeben (datetime-local).']);
            return;
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ts_number WHERE id = :id AND ts BETWEEN :from AND :to');
        $countStmt->execute([':id' => $id, ':from' => $fromMs, ':to' => $toMs]);
        $totalAvailable = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();
        $total = min($totalAvailable, TEST_LIMIT_ROWS);

        send_event([
            'type' => 'meta',
            'action' => 'test',
            'id' => $id,
            'total' => $total,
            'window_from' => $fromMs,
            'window_to' => $toMs,
            'interval_key' => $intervalKey,
            'interval_ms' => $intervalMs,
            'interval_label' => $intervalKey,
        ]);

        $sql = 'SELECT ts, val FROM ts_number WHERE id = :id AND ts BETWEEN :from AND :to ORDER BY ts ASC LIMIT ' . TEST_LIMIT_ROWS;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':from' => $fromMs, ':to' => $toMs]);

        $processed = 0; $groups = 0; $sinceLastProgress = 0; $lastProgressAtMs = (int) round(microtime(true) * 1000);
        $bucketStart = null; $bucketEnd = null; $sum = 0.0; $cnt = 0; $firstTs = null; $lastTs = null;

        $flushGroup = function() use (&$bucketStart, &$bucketEnd, &$sum, &$cnt, &$firstTs, &$lastTs, &$groups, &$processed, $total) {
            if ($bucketStart === null || $cnt === 0) return;
            $avg = $sum / $cnt;
            $groups++;
            send_event([
                'type' => 'row',
                'interval_start' => $bucketStart,
                'interval_start_readable' => ms_to_readable($bucketStart),
                'interval_end' => $bucketEnd,
                'count' => $cnt,
                'avg' => round($avg, 6),
                'first_ts' => $firstTs,
                'first_ts_readable' => $firstTs !== null ? ms_to_readable($firstTs) : null,
                'last_ts' => $lastTs,
                'last_ts_readable' => $lastTs !== null ? ms_to_readable($lastTs) : null,
                'processed' => $processed,
                'groups' => $groups,
                'total' => $total,
            ]);
        };

        while ($row = $stmt->fetch()) {
            $ts = (int) $row['ts'];
            $val = (float) $row['val'];
            $processed++;
            $sinceLastProgress++;

            $bucket = (int) floor($ts / $intervalMs) * $intervalMs;
            if ($bucketStart === null) {
                $bucketStart = $bucket; $bucketEnd = $bucketStart + $intervalMs - 1; $sum = 0.0; $cnt = 0; $firstTs = $ts; $lastTs = $ts;
            }
            if ($bucket !== $bucketStart) {
                $flushGroup();
                $bucketStart = $bucket; $bucketEnd = $bucketStart + $intervalMs - 1; $sum = 0.0; $cnt = 0; $firstTs = $ts; $lastTs = $ts;
            }
            $sum += $val; $cnt++; $lastTs = $ts;

            $nowMs = (int) round(microtime(true) * 1000);
            if ($sinceLastProgress >= PROGRESS_EVERY_ROWS || ($nowMs - $lastProgressAtMs) >= PROGRESS_EVERY_MS) {
                send_event([
                    'type' => 'progress', 'action' => 'test', 'id' => $id,
                    'processed' => $processed, 'groups' => $groups, 'total' => $total,
                    'percent' => $total > 0 ? round(($processed / $total) * 100, 2) : null,
                ]);
                $sinceLastProgress = 0; $lastProgressAtMs = $nowMs;
            }
        }

        $flushGroup();
        $stmt->closeCursor();

        send_event([
            'type' => 'summary', 'action' => 'test', 'id' => $id,
            'processed' => $processed, 'groups' => $groups, 'total' => $total,
            'window_from' => $fromMs, 'window_to' => $toMs,
        ]);
    } catch (Throwable $e) {
        @header('Content-Type: application/x-ndjson; charset=utf-8');
        send_event(['type' => 'error', 'error' => 'Test fehlgeschlagen', 'details' => $e->getMessage()]);
    }
}

function handle_run(PDO $pdoRead): void {
    try {
        prepare_stream_headers();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
        $intervalKey = isset($_POST['intervalKey']) ? (string) $_POST['intervalKey'] : '1h';
        $intervalMs = resolve_interval_ms($intervalKey) ?? (60 * 60 * 1000);
        $fromInput = $_POST['from'] ?? ($_POST['timestamp_from'] ?? null);
        $toInput = $_POST['to'] ?? ($_POST['timestamp_to'] ?? null);
        $fromMs = toMillisFromInput(is_string($fromInput) ? $fromInput : null);
        $toMs = toMillisFromInput(is_string($toInput) ? $toInput : null);

        $where = 'id = :id'; $params = [':id' => $id];
        if ($fromMs !== null && $toMs !== null) { $where .= ' AND ts BETWEEN :from AND :to'; $params[':from'] = $fromMs; $params[':to'] = $toMs; }
        elseif ($fromMs !== null) { $where .= ' AND ts >= :from'; $params[':from'] = $fromMs; }
        elseif ($toMs !== null) { $where .= ' AND ts <= :to'; $params[':to'] = $toMs; }

        $countStmt = $pdoRead->prepare('SELECT COUNT(*) FROM ts_number WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $countStmt->closeCursor();

        send_event([
            'type' => 'meta', 'action' => 'run', 'id' => $id,
            'total' => $total,
            'window_from' => $fromMs, 'window_to' => $toMs,
            'interval_key' => $intervalKey, 'interval_ms' => $intervalMs, 'interval_label' => $intervalKey,
        ]);

        // Stream rows on read connection
        $sql = 'SELECT ts, val FROM ts_number WHERE ' . $where . ' ORDER BY ts ASC';
        $stmt = $pdoRead->prepare($sql);
        $stmt->execute($params);

        // Use a second connection for writes to avoid conflicts with unbuffered reads
        $pdoWrite = get_pdo();
        $deleteStmt = $pdoWrite->prepare('DELETE FROM ts_number WHERE id = :id AND ts BETWEEN :from AND :to');
        $insertStmt = $pdoWrite->prepare('INSERT INTO ts_number (id, ts, val) VALUES (:id, :ts, :val)');

        $processed = 0; $affected = 0; $inserted = 0; $groups = 0; $sinceLastProgress = 0; $lastProgressAtMs = (int) round(microtime(true) * 1000);
        $bucketStart = null; $bucketEnd = null; $sum = 0.0; $cnt = 0; $firstTs = null; $lastTs = null;

        $commitGroup = function() use (&$bucketStart, &$bucketEnd, &$sum, &$cnt, &$firstTs, &$lastTs, &$affected, &$inserted, &$groups, $id, $deleteStmt, $insertStmt) {
            if ($bucketStart === null || $cnt === 0) return;
            $groups++;
            if ($cnt <= 1) { return; }
            $avg = $sum / $cnt; $avg = round($avg, 6);
            // Delete originals in range first, then insert averaged point at firstTs + 1ms
            $deleteStmt->execute([':id' => $id, ':from' => $firstTs, ':to' => $lastTs]);
            $affected += (int) $deleteStmt->rowCount();
            $newTs = $firstTs + 1;
            // attempt insert; if duplicates on (id, ts) unique exist, bump by +1 up to +10
            for ($i = 0; $i < 10; $i++) {
                try {
                    $insertStmt->execute([':id' => $id, ':ts' => $newTs, ':val' => $avg]);
                    $inserted++;
                    break;
                } catch (Throwable $e) {
                    $newTs++;
                    if ($i === 9) { throw $e; }
                }
            }
        };

        while ($row = $stmt->fetch()) {
            $ts = (int) $row['ts'];
            $val = (float) $row['val'];
            $processed++;
            $sinceLastProgress++;

            $bucket = (int) floor($ts / $intervalMs) * $intervalMs;
            if ($bucketStart === null) {
                $bucketStart = $bucket; $bucketEnd = $bucketStart + $intervalMs - 1; $sum = 0.0; $cnt = 0; $firstTs = $ts; $lastTs = $ts;
            }
            if ($bucket !== $bucketStart) {
                $commitGroup();
                $bucketStart = $bucket; $bucketEnd = $bucketStart + $intervalMs - 1; $sum = 0.0; $cnt = 0; $firstTs = $ts; $lastTs = $ts;
            }
            $sum += $val; $cnt++; $lastTs = $ts;

            $nowMs = (int) round(microtime(true) * 1000);
            if ($sinceLastProgress >= PROGRESS_EVERY_ROWS || ($nowMs - $lastProgressAtMs) >= PROGRESS_EVERY_MS) {
                send_event(['type' => 'progress', 'action' => 'run', 'id' => $id, 'processed' => $processed, 'affected' => $affected, 'total' => $total, 'inserted' => $inserted]);
                $sinceLastProgress = 0; $lastProgressAtMs = $nowMs;
            }
        }

        $commitGroup();
        $stmt->closeCursor();

        // recompute counts after
        $countAfterStmt = $pdoRead->prepare('SELECT COUNT(*) FROM ts_number WHERE ' . $where);
        $countAfterStmt->execute($params);
        $totalAfter = (int) $countAfterStmt->fetchColumn();
        $countAfterStmt->closeCursor();

        send_event([
            'type' => 'summary', 'action' => 'run', 'id' => $id,
            'processed' => $processed, 'affected' => $affected, 'inserted' => $inserted,
            'total_before' => $total, 'total_after' => $totalAfter, 'groups' => $groups,
        ]);
    } catch (Throwable $e) {
        @header('Content-Type: application/x-ndjson; charset=utf-8');
        send_event(['type' => 'error', 'error' => 'Run fehlgeschlagen', 'details' => $e->getMessage()]);
    }
}

// Router
try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    // If DB unavailable, provide minimal error responses
    $action = $_POST['action'] ?? '';
    if ($action === 'analyze') {
        json_response(['ok' => false, 'error' => 'DB-Verbindung fehlgeschlagen', 'details' => $e->getMessage()], 500);
    } elseif ($action === 'test' || $action === 'run') {
        prepare_stream_headers();
        send_event(['type' => 'error', 'error' => 'DB-Verbindung fehlgeschlagen', 'details' => $e->getMessage()]);
    } else {
        json_response(['ok' => false, 'error' => 'Ungültige Aktion oder DB-Fehler', 'details' => $e->getMessage()], 400);
    }
    exit;
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
switch ($action) {
    case 'analyze': handle_analyze($pdo); break;
    case 'test': handle_test($pdo); break;
    case 'run': handle_run($pdo); break;
    default: json_response(['ok' => false, 'error' => 'Unbekannte Aktion'], 400); break;
}

?>

