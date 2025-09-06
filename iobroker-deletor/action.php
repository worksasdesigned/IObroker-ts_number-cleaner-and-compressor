<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Analyze: count per datapoint: zeros, nulls, blanks within optional window
function handle_analyze(PDO $pdo): void {
    try {
        $fromInput = $_POST['from'] ?? null;
        $toInput = $_POST['to'] ?? null;
        $fromMs = toMillisFromInput($fromInput !== null ? (string)$fromInput : null);
        $toMs = toMillisFromInput($toInput !== null ? (string)$toInput : null);

        $timeWhere = '';
        $params = [];
        if ($fromMs !== null && $toMs !== null) { $timeWhere = 'AND tn.ts BETWEEN :from AND :to'; $params[':from']=$fromMs; $params[':to']=$toMs; }
        else if ($fromMs !== null) { $timeWhere = 'AND tn.ts >= :from'; $params[':from']=$fromMs; }
        else if ($toMs !== null) { $timeWhere = 'AND tn.ts <= :to'; $params[':to']=$toMs; }

        // Note: ts_number.val is numeric; blanks typically won't exist. We include IS NULL and val=0. For completeness, detect empty via CAST/JSON where applicable -> count 0.
        $sql = "SELECT dp.id AS id, dp.name AS name,
                       SUM(CASE WHEN tn.val = 0 THEN 1 ELSE 0 END) AS count_zero,
                       SUM(CASE WHEN tn.val IS NULL THEN 1 ELSE 0 END) AS count_null,
                       0 AS count_blank
                FROM ts_number tn
                JOIN datapoints dp ON dp.id = tn.id
                WHERE 1=1 $timeWhere
                GROUP BY dp.id, dp.name
                ORDER BY count_zero DESC, count_null DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        while ($r = $stmt->fetch()) {
            $rows[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'count_zero' => (int)$r['count_zero'],
                'count_null' => (int)$r['count_null'],
                'count_blank' => (int)$r['count_blank'],
            ];
        }
        json_response(['ok'=>true,'rows'=>$rows]);
    } catch (Throwable $e) {
        json_response(['ok'=>false,'error'=>'Analyse fehlgeschlagen','details'=>$e->getMessage()],500);
    }
}

function stream_test_delete(PDO $pdo, string $kind): void {
    try {
        prepare_stream_headers();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : DEFAULT_STATE_ID;
        $fromMs = toMillisFromInput($_POST['from'] ?? null);
        $toMs = toMillisFromInput($_POST['to'] ?? null);

        $where = 'id = :id';
        $params = [':id'=>$id];
        if ($fromMs !== null && $toMs !== null) { $where .= ' AND ts BETWEEN :from AND :to'; $params[':from']=$fromMs; $params[':to']=$toMs; }
        else if ($fromMs !== null) { $where .= ' AND ts >= :from'; $params[':from']=$fromMs; }
        else if ($toMs !== null) { $where .= ' AND ts <= :to'; $params[':to']=$toMs; }

        $valCond = $kind === 'zero' ? 'val = 0' : 'val IS NULL';

        $countSql = 'SELECT COUNT(*) FROM ts_number WHERE ' . $where . ' AND ' . $valCond;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $countStmt->closeCursor();

        send_event(['type'=>'meta','action'=>'test','id'=>$id,'total'=>$total,'window_from'=>$fromMs,'window_to'=>$toMs]);

        $processed = 0; $affected = 0; $sinceLast = 0; $lastAt = (int)round(microtime(true)*1000);

        // Iterate in chunks by selecting TS only to simulate streaming counting until TEST_LIMIT_ROWS
        $limit = TEST_LIMIT_ROWS;
        if ($total > $limit) { $total = $limit; }

        $sql = 'SELECT ts FROM ts_number WHERE ' . $where . ' AND ' . $valCond . ' ORDER BY ts ASC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $processed++; $affected++;
            $sinceLast++;
            $now = (int)round(microtime(true)*1000);
            if ($sinceLast >= PROGRESS_EVERY_ROWS || ($now - $lastAt) >= PROGRESS_EVERY_MS) {
                send_event(['type'=>'progress','id'=>$id,'processed'=>$processed,'affected'=>$affected,'total'=>$total]);
                $sinceLast = 0; $lastAt = $now;
            }
        }
        $stmt->closeCursor();
        send_event(['type'=>'summary','action'=>'test','id'=>$id,'processed'=>$processed,'affected'=>$affected,'total'=>$total,'window_from'=>$fromMs,'window_to'=>$toMs]);
    } catch (Throwable $e) {
        @header('Content-Type: application/x-ndjson; charset=utf-8');
        send_event(['type'=>'error','error'=>'Test fehlgeschlagen','details'=>$e->getMessage()]);
    }
}

function stream_run_delete(PDO $pdo): void {
    try {
        prepare_stream_headers();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : DEFAULT_STATE_ID;
        $fromMs = toMillisFromInput($_POST['from'] ?? null);
        $toMs = toMillisFromInput($_POST['to'] ?? null);
        $kind = ($_POST['action'] ?? '') === 'run_delete_zero' ? 'zero' : 'null';

        $where = 'id = :id';
        $params = [':id'=>$id];
        if ($fromMs !== null && $toMs !== null) { $where .= ' AND ts BETWEEN :from AND :to'; $params[':from']=$fromMs; $params[':to']=$toMs; }
        else if ($fromMs !== null) { $where .= ' AND ts >= :from'; $params[':from']=$fromMs; }
        else if ($toMs !== null) { $where .= ' AND ts <= :to'; $params[':to']=$toMs; }

        $valCond = $kind === 'zero' ? 'val = 0' : 'val IS NULL';

        $countSql = 'SELECT COUNT(*) FROM ts_number WHERE ' . $where . ' AND ' . $valCond;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $countStmt->closeCursor();

        send_event(['type'=>'meta','action'=>'run','id'=>$id,'total'=>$total,'window_from'=>$fromMs,'window_to'=>$toMs]);

        // read and delete with separate write connection
        $pdoWrite = get_pdo();
        $deleteStmt = $pdoWrite->prepare('DELETE FROM ts_number WHERE id = :id AND ts = :ts LIMIT 1');
        $pdoWrite->beginTransaction();

        $processed = 0; $deleted = 0; $sinceLast = 0; $lastAt = (int)round(microtime(true)*1000); $pending=0;

        $sql = 'SELECT ts FROM ts_number WHERE ' . $where . ' AND ' . $valCond . ' ORDER BY ts ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $ts = (int)$row['ts'];
            $processed++;
            $deleteStmt->execute([':id'=>$id, ':ts'=>$ts]);
            $deleted++; $pending++; $sinceLast++;
            if ($pending >= DELETE_COMMIT_EVERY) { $pdoWrite->commit(); $pdoWrite->beginTransaction(); $pending = 0; }
            $now = (int)round(microtime(true)*1000);
            if ($sinceLast >= PROGRESS_EVERY_ROWS || ($now - $lastAt) >= PROGRESS_EVERY_MS) {
                send_event(['type'=>'progress','id'=>$id,'processed'=>$processed,'deleted'=>$deleted,'total'=>$total]);
                $sinceLast = 0; $lastAt = $now;
            }
        }
        $stmt->closeCursor();
        $pdoWrite->commit();

        // remaining
        $remainStmt = $pdo->prepare('SELECT COUNT(*) FROM ts_number WHERE ' . $where);
        $remainStmt->execute($params);
        $remaining = (int)$remainStmt->fetchColumn();
        send_event(['type'=>'summary','action'=>'run','id'=>$id,'processed'=>$processed,'deleted'=>$deleted,'window_from'=>$fromMs,'window_to'=>$toMs,'total_before'=>$total,'total_after'=>$remaining]);
    } catch (Throwable $e) {
        @header('Content-Type: application/x-ndjson; charset=utf-8');
        send_event(['type'=>'error','error'=>'Run fehlgeschlagen','details'=>$e->getMessage()]);
    }
}

// Test data fallbacks if DB unavailable
function analyze_test_fallback(): void {
    $rows = [
        ['id'=>101,'name'=>'dp.sample.1','count_zero'=>123,'count_null'=>4,'count_blank'=>0],
        ['id'=>102,'name'=>'dp.sample.2','count_zero'=>45,'count_null'=>0,'count_blank'=>0],
    ];
    json_response(['ok'=>true,'rows'=>$rows]);
}

function stream_test_delete_fallback(): void {
    prepare_stream_headers();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : DEFAULT_STATE_ID;
    $total = 500; $processed=0; $affected=0;
    send_event(['type'=>'meta','action'=>'test','id'=>$id,'total'=>$total,'window_from'=>null,'window_to'=>null]);
    for ($i=0;$i<$total;$i++) {
        $processed++; $affected++;
        if ($i % 50 === 0 || $i === $total-1) {
            send_event(['type'=>'progress','id'=>$id,'processed'=>$processed,'affected'=>$affected,'total'=>$total]);
        }
        usleep(5000);
    }
    send_event(['type'=>'summary','action'=>'test','id'=>$id,'processed'=>$processed,'affected'=>$affected,'total'=>$total]);
}

function stream_run_delete_fallback(): void {
    prepare_stream_headers();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : DEFAULT_STATE_ID;
    $total = 800; $processed=0; $deleted=0;
    send_event(['type'=>'meta','action'=>'run','id'=>$id,'total'=>$total]);
    for ($i=0;$i<$total;$i++) {
        $processed++; if ($i % 3 === 0) $deleted++;
        if ($i % 40 === 0 || $i === $total-1) {
            send_event(['type'=>'progress','id'=>$id,'processed'=>$processed,'deleted'=>$deleted,'total'=>$total]);
        }
        usleep(3000);
    }
    send_event(['type'=>'summary','action'=>'run','id'=>$id,'processed'=>$processed,'deleted'=>$deleted,'total_before'=>$total,'total_after'=>$total-$deleted]);
}

// Bootstrap
$pdo = null; $useTest = false;
try { $pdo = get_pdo(); } catch (Throwable $e) { $useTest = true; error_log('DB failed: '.$e->getMessage()); }

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($useTest) {
    switch ($action) {
        case 'analyze': analyze_test_fallback(); break;
        case 'test_delete_zero':
        case 'test_delete_null': stream_test_delete_fallback(); break;
        case 'run_delete_zero':
        case 'run_delete_null': stream_run_delete_fallback(); break;
        default: json_response(['ok'=>false,'error'=>'Unbekannte Aktion'],400);
    }
} else {
    switch ($action) {
        case 'analyze': handle_analyze($pdo); break;
        case 'test_delete_zero': stream_test_delete($pdo, 'zero'); break;
        case 'test_delete_null': stream_test_delete($pdo, 'null'); break;
        case 'run_delete_zero':
        case 'run_delete_null': stream_run_delete($pdo); break;
        default: json_response(['ok'=>false,'error'=>'Unbekannte Aktion'],400);
    }
}

?>

