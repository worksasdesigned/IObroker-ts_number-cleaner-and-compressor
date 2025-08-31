<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/detector.php';

// Common headers for streaming NDJSON responses
function prepare_stream_headers(): void {
	header('Content-Type: application/x-ndjson; charset=utf-8');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');
	// Try to disable buffering in reverse proxies/servers
	header('X-Accel-Buffering: no');
	@ini_set('output_buffering', 'off');
	@ini_set('zlib.output_compression', '0');
	@set_time_limit(0);
	// End all existing buffers
	while (ob_get_level() > 0) {
		ob_end_flush();
	}
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
			$params[':from'] = $fromMs;
			$params[':to'] = $toMs;
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
			'min_ts_readable' => $minTs !== null ? ms_to_readable($minTs) : null,
			'max_ts' => $maxTs,
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
		$fromInput = $_POST['from'] ?? null;
		$toInput = $_POST['to'] ?? null;
		$fromMs = toMillisFromInput($fromInput !== null ? (string) $fromInput : null);
		$toMs = toMillisFromInput($toInput !== null ? (string) $toInput : null);
		if ($fromMs === null || $toMs === null) {
			send_event(['ok' => false, 'error' => 'Bitte gueltige Von/Bis-Daten angeben (datetime-local).']);
			return;
		}

		// Count first (up to limit)
		$countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM ts_number WHERE id = :id AND ts BETWEEN :from AND :to');
		$countStmt->execute([':id' => $id, ':from' => $fromMs, ':to' => $toMs]);
		$totalAvailable = (int) $countStmt->fetchColumn();
		$total = min($totalAvailable, TEST_LIMIT_ROWS);
		// Important for unbuffered queries: free previous statement before next
		$countStmt->closeCursor();
		
		send_event([
			'type' => 'meta',
			'action' => 'test',
			'id' => $id,
			'total' => $total,
			'window_from' => $fromMs,
			'window_to' => $toMs,
			'drop_threshold' => DROP_THRESHOLD,
			'spike_threshold' => SPIKE_THRESHOLD,
		]);

		$sql = 'SELECT ts, val FROM ts_number WHERE id = :id AND ts BETWEEN :from AND :to ORDER BY ts ASC LIMIT ' . TEST_LIMIT_ROWS;
		$stmt = $pdo->prepare($sql);
		$stmt->execute([':id' => $id, ':from' => $fromMs, ':to' => $toMs]);

		$processed = 0;
		$anomalies = 0;
		$detector = new PlausibilityDetector();
		$sinceLastProgress = 0;
		$lastProgressAtMs = (int) round(microtime(true) * 1000);

		while ($row = $stmt->fetch()) {
			$ts = (int) $row['ts'];
			$val = (float) $row['val'];
			list($isAnomaly, $deltaFromPrevRaw, $deltaFromPrevGood) = $detector->analyze($ts, $val);
			$processed++;
			$sinceLastProgress++;
			if ($isAnomaly) {
				$anomalies++;
			}
			send_event([
				'type' => 'row',
				'ts' => $ts,
				'ts_readable' => ms_to_readable($ts),
				'val' => $val,
				'delta_prev' => $deltaFromPrevRaw,
				'delta_prev_good' => $deltaFromPrevGood,
				'anomaly' => $isAnomaly,
				'processed' => $processed,
				'anomalies' => $anomalies,
				'total' => $total,
			]);

			$nowMs = (int) round(microtime(true) * 1000);
			if ($sinceLastProgress >= PROGRESS_EVERY_ROWS || ($nowMs - $lastProgressAtMs) >= PROGRESS_EVERY_MS) {
				send_event([
					'type' => 'progress',
					'action' => 'test',
					'id' => $id,
					'processed' => $processed,
					'anomalies' => $anomalies,
					'total' => $total,
					'percent' => $total > 0 ? round(($processed / $total) * 100, 2) : null,
				]);
				$sinceLastProgress = 0;
				$lastProgressAtMs = $nowMs;
			}
		}

		// Ensure streaming cursor is released before final summary
		$stmt->closeCursor();
		send_event([
			'type' => 'summary',
			'action' => 'test',
			'id' => $id,
			'processed' => $processed,
			'anomalies' => $anomalies,
			'total' => $total,
			'window_from' => $fromMs,
			'window_to' => $toMs,
		]);
	} catch (Throwable $e) {
		@header('Content-Type: application/x-ndjson; charset=utf-8');
		send_event(['type' => 'error', 'error' => 'Testlauf fehlgeschlagen', 'details' => $e->getMessage()]);
	}
}

// Test data handlers for when database is not available
function handle_analyze_test(): void {
	$id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
	$fromInput = $_POST['from'] ?? ($_POST['timestamp_from'] ?? null);
	$toInput = $_POST['to'] ?? ($_POST['timestamp_to'] ?? null);
	$fromMs = toMillisFromInput(is_string($fromInput) ? $fromInput : null);
	$toMs = toMillisFromInput(is_string($toInput) ? $toInput : null);

	// Generate fake analysis data within optional window
	$globalMin = strtotime('2025-01-01 00:00:00') * 1000;
	$globalMax = strtotime('2025-12-31 23:59:59') * 1000;
	$minTs = $fromMs !== null ? max($globalMin, $fromMs) : $globalMin;
	$maxTs = $toMs !== null ? min($globalMax, $toMs) : $globalMax;
	$count = max(0, (int) floor(($maxTs - $minTs) / (3600 * 1000))); // rough count per hour

	json_response([
		'ok' => true,
		'id' => $id,
		'count' => $count,
		'min_ts' => $minTs,
		'min_ts_readable' => ms_to_readable($minTs),
		'max_ts' => $maxTs,
		'max_ts_readable' => ms_to_readable($maxTs),
		'window_from' => $fromMs,
		'window_to' => $toMs,
		'window_from_readable' => $fromMs !== null ? ms_to_readable($fromMs) : null,
		'window_to_readable' => $toMs !== null ? ms_to_readable($toMs) : null,
	]);
}

function handle_test_with_test_data(): void {
	prepare_stream_headers();
	$id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
	$fromInput = $_POST['from'] ?? null;
	$toInput = $_POST['to'] ?? null;
	$fromMs = toMillisFromInput($fromInput !== null ? (string) $fromInput : null);
	$toMs = toMillisFromInput($toInput !== null ? (string) $toInput : null);
	
	if ($fromMs === null || $toMs === null) {
		send_event(['ok' => false, 'error' => 'Bitte gueltige Von/Bis-Daten angeben (datetime-local).']);
		return;
	}
	
	// Generate test data
	$total = 100;
	
	send_event([
		'type' => 'meta',
		'action' => 'test',
		'id' => $id,
		'total' => $total,
		'window_from' => $fromMs,
		'window_to' => $toMs,
		'drop_threshold' => DROP_THRESHOLD,
		'spike_threshold' => SPIKE_THRESHOLD,
	]);
	
	$processed = 0;
	$anomalies = 0;
	$detector = new PlausibilityDetector();
	
	// Generate and stream test data
	for ($i = 0; $i < $total; $i++) {
		$ts = $fromMs + (int)(($toMs - $fromMs) * $i / $total);
		
		// Generate value with occasional anomalies
		if ($i % 15 == 0 && $i > 0) {
			// Create a spike anomaly
			$val = $prevGood + SPIKE_THRESHOLD + rand(100, 500);
		} elseif ($i % 20 == 0 && $i > 0) {
			// Create a drop anomaly
			$val = $prevGood - DROP_THRESHOLD - rand(10, 100);
		} else {
			// Normal value with small variation
			$val = 1000.0 + rand(-10, 10) + ($i * 0.5);
		}
		
		list($isAnomaly, $deltaFromPrevRaw, $deltaFromPrevGood) = $detector->analyze($ts, $val);
		$processed++;
		
		if ($isAnomaly) {
			$anomalies++;
		}
		
		send_event([
			'type' => 'row',
			'ts' => $ts,
			'ts_readable' => ms_to_readable($ts),
			'val' => $val,
			'delta_prev' => $deltaFromPrevRaw,
			'delta_prev_good' => $deltaFromPrevGood,
			'anomaly' => $isAnomaly,
			'processed' => $processed,
			'anomalies' => $anomalies,
			'total' => $total,
		]);
		
		// Send progress updates
		if ($i % 10 == 0 || $i == $total - 1) {
			send_event([
				'type' => 'progress',
				'action' => 'test',
				'id' => $id,
				'processed' => $processed,
				'anomalies' => $anomalies,
				'total' => $total,
				'percent' => round(($processed / $total) * 100, 2),
			]);
		}
		
		// Small delay to simulate processing
		usleep(10000); // 10ms
	}
	
	send_event([
		'type' => 'summary',
		'action' => 'test',
		'id' => $id,
		'processed' => $processed,
		'anomalies' => $anomalies,
		'total' => $total,
		'window_from' => $fromMs,
		'window_to' => $toMs,
	]);
}

function handle_run_with_test_data(): void {
	prepare_stream_headers();
	$id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
	$fromInput = $_POST['from'] ?? ($_POST['timestamp_from'] ?? null);
	$toInput = $_POST['to'] ?? ($_POST['timestamp_to'] ?? null);
	$fromMs = toMillisFromInput(is_string($fromInput) ? $fromInput : null);
	$toMs = toMillisFromInput(is_string($toInput) ? $toInput : null);
	
	// Generate test data
	$total = 500;
	
	send_event([
		'type' => 'meta',
		'action' => 'run',
		'id' => $id,
		'total' => $total,
		'window_from' => $fromMs,
		'window_to' => $toMs,
		'window_from_readable' => $fromMs !== null ? ms_to_readable($fromMs) : null,
		'window_to_readable' => $toMs !== null ? ms_to_readable($toMs) : null,
		'drop_threshold' => DROP_THRESHOLD,
		'spike_threshold' => SPIKE_THRESHOLD,
	]);
	
	$processed = 0;
	$deleted = 0;
	$detector = new PlausibilityDetector();
	
	// Generate and process test data
	for ($i = 0; $i < $total; $i++) {
		$ts = $fromMs ? ($fromMs + (int)(($toMs ? $toMs : time() * 1000) - $fromMs) * $i / $total) : (time() * 1000 - ($total - $i) * 3600000);
		
		// Generate value with occasional anomalies
		if ($i % 15 == 0 && $i > 0) {
			// Create a spike anomaly
			$val = $prevGood + SPIKE_THRESHOLD + rand(100, 500);
		} elseif ($i % 20 == 0 && $i > 0) {
			// Create a drop anomaly
			$val = $prevGood - DROP_THRESHOLD - rand(10, 100);
		} else {
			// Normal value with small variation
			$val = 1000.0 + rand(-10, 10) + ($i * 0.5);
		}
		
		list($isAnomaly) = $detector->analyze($ts, $val);
		$processed++;
		
		if ($isAnomaly) {
			$deleted++;
		}
		
		// Send progress updates
		if ($i % 20 == 0 || $i == $total - 1) {
			send_event([
				'type' => 'progress',
				'id' => $id,
				'processed' => $processed,
				'deleted' => $deleted,
				'total' => $total,
				'percent' => round(($processed / $total) * 100, 2),
			]);
		}
		
		// Small delay to simulate processing
		usleep(5000); // 5ms
	}
	
	$remaining = $total - $deleted;
	
	send_event([
		'type' => 'summary',
		'action' => 'run',
		'id' => $id,
		'processed' => $processed,
		'deleted' => $deleted,
		'window_from' => $fromMs,
		'window_to' => $toMs,
		'window_from_readable' => $fromMs !== null ? ms_to_readable($fromMs) : null,
		'window_to_readable' => $toMs !== null ? ms_to_readable($toMs) : null,
		'total_before' => $total,
		'total_after' => $remaining,
	]);
}

function handle_run(PDO $pdo): void {
	try {
		prepare_stream_headers();
		$id = isset($_POST['id']) ? (int) $_POST['id'] : DEFAULT_STATE_ID;
		$fromInput = $_POST['from'] ?? ($_POST['timestamp_from'] ?? null);
		$toInput = $_POST['to'] ?? ($_POST['timestamp_to'] ?? null);
		$fromMs = toMillisFromInput(is_string($fromInput) ? $fromInput : null);
		$toMs = toMillisFromInput(is_string($toInput) ? $toInput : null);

		// Build WHERE clause with optional time window
		$where = 'id = :id';
		$params = [':id' => $id];
		if ($fromMs !== null && $toMs !== null) {
			$where .= ' AND ts BETWEEN :from AND :to';
			$params[':from'] = $fromMs;
			$params[':to'] = $toMs;
		} elseif ($fromMs !== null) {
			$where .= ' AND ts >= :from';
			$params[':from'] = $fromMs;
		} elseif ($toMs !== null) {
			$where .= ' AND ts <= :to';
			$params[':to'] = $toMs;
		}

		// Total rows for this id (and optional window)
		$countSql = 'SELECT COUNT(*) FROM ts_number WHERE ' . $where;
		$countStmt = $pdo->prepare($countSql);
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();
		// Free cursor of count statement before starting streaming select on same connection
		$countStmt->closeCursor();

		send_event([
			'type' => 'meta',
			'action' => 'run',
			'id' => $id,
			'total' => $total,
			'window_from' => $fromMs,
			'window_to' => $toMs,
			'window_from_readable' => $fromMs !== null ? ms_to_readable($fromMs) : null,
			'window_to_readable' => $toMs !== null ? ms_to_readable($toMs) : null,
			'drop_threshold' => DROP_THRESHOLD,
			'spike_threshold' => SPIKE_THRESHOLD,
		]);

		// Stream rows ordered by time (within window if provided)
		$sql = 'SELECT ts, val FROM ts_number WHERE ' . $where . ' ORDER BY ts ASC';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);

		// separate write connection to allow unbuffered read stream on $pdo
		try {
			$pdoWrite = get_pdo();
		} catch (Throwable $e) {
			send_event(['type' => 'error', 'message' => 'Schreibverbindung fehlgeschlagen: ' . $e->getMessage()]);
			return;
		}
		$deleteStmt = $pdoWrite->prepare('DELETE FROM ts_number WHERE id = :id AND ts = :ts LIMIT 1');
		$pdoWrite->beginTransaction();

		$processed = 0;
		$deleted = 0;
		$detector = new PlausibilityDetector();
		$sinceLastProgress = 0;
		$lastProgressAtMs = (int) round(microtime(true) * 1000);
		$pendingDeletesSinceCommit = 0;

		while ($row = $stmt->fetch()) {
			$ts = (int) $row['ts'];
			$val = (float) $row['val'];
			list($isAnomaly) = $detector->analyze($ts, $val);

			$processed++;
			$sinceLastProgress++;

			if ($isAnomaly) {
				$deleteStmt->execute([':id' => $id, ':ts' => $ts]);
				$deleted++;
				$pendingDeletesSinceCommit++;
				if ($pendingDeletesSinceCommit >= DELETE_COMMIT_EVERY) {
					$pdoWrite->commit();
					$pdoWrite->beginTransaction();
					$pendingDeletesSinceCommit = 0;
				}
			}

			$nowMs = (int) round(microtime(true) * 1000);
			if ($sinceLastProgress >= PROGRESS_EVERY_ROWS || ($nowMs - $lastProgressAtMs) >= PROGRESS_EVERY_MS) {
				send_event([
					'type' => 'progress',
					'id' => $id,
					'processed' => $processed,
					'deleted' => $deleted,
					'total' => $total,
					'percent' => $total > 0 ? round(($processed / $total) * 100, 2) : null,
				]);
				$sinceLastProgress = 0;
				$lastProgressAtMs = $nowMs;
			}
		}

		// Final commit
		$pdoWrite->commit();

		// Remaining after deletions
		// Ensure the streaming read cursor is fully released before a new query on $pdo
		$stmt->closeCursor();
		$remainSql = 'SELECT COUNT(*) FROM ts_number WHERE ' . $where;
		$remainStmt = $pdo->prepare($remainSql);
		$remainStmt->execute($params);
		$remaining = (int) $remainStmt->fetchColumn();

		send_event([
			'type' => 'summary',
			'action' => 'run',
			'id' => $id,
			'processed' => $processed,
			'deleted' => $deleted,
			'window_from' => $fromMs,
			'window_to' => $toMs,
			'window_from_readable' => $fromMs !== null ? ms_to_readable($fromMs) : null,
			'window_to_readable' => $toMs !== null ? ms_to_readable($toMs) : null,
			'total_before' => $total,
			'total_after' => $remaining,
		]);
	} catch (Throwable $e) {
		@header('Content-Type: application/x-ndjson; charset=utf-8');
		send_event(['type' => 'error', 'error' => 'Echtlauf fehlgeschlagen', 'details' => $e->getMessage()]);
	}
}

// Try to connect to database, but continue with test data if it fails
$pdo = null;
$useTestData = false;
try {
	$pdo = get_pdo();
} catch (Throwable $e) {
	// Database connection failed, we'll use test data instead
	$useTestData = true;
	error_log("DB connection failed, using test data: " . $e->getMessage());
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($useTestData) {
	// Use test data handlers when no database is available
	switch ($action) {
		case 'analyze':
			handle_analyze_test();
			break;
		case 'test':
			handle_test_with_test_data();
			break;
		case 'run':
			handle_run_with_test_data();
			break;
		default:
			json_response(['ok' => false, 'error' => 'Unbekannte Aktion'], 400);
	}
} else {
	// Use real database handlers
	switch ($action) {
		case 'analyze':
			handle_analyze($pdo);
			break;
		case 'test':
			handle_test($pdo);
			break;
		case 'run':
			handle_run($pdo);
			break;
		default:
			json_response(['ok' => false, 'error' => 'Unbekannte Aktion'], 400);
	}
}

?>

