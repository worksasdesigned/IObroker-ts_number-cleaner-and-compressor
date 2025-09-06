<?php
declare(strict_types=1);

// Database configuration. Update these to match your MySQL setup.
// You can also override via environment variables: DB_HOST, DB_NAME, DB_USER, DB_PASS
const DB_HOST = 'localhost';
const DB_NAME = 'iobroker';
const DB_USER = 'iobroker_user';
const DB_PASS = 'change_me';

// Hard-set default device/state id for Stromzähler as per requirements
const DEFAULT_STATE_ID = 102;

// Anomaly detection thresholds (in value units of `val`)
// A negative jump larger than this (absolute) is an anomaly
const DROP_THRESHOLD = 50.0;
// A positive jump larger than this is an anomaly
const SPIKE_THRESHOLD = 1000.0;

// Moving average and stabilization heuristics
// Number of recent good points to consider for rate estimation
const MOVING_WINDOW_SIZE_GOOD = 10;
// Consider a new baseline established if the last N raw deltas are all small
const SMALL_DELTA_CLUSTER_COUNT = 4;
// Absolute threshold for a "small" raw delta (domain-specific; adjust as needed)
const SMALL_DELTA_ABS_MAX = 1.0;
// Tolerance for rate-based plausibility: expected +/- (multiplier * avgAbsRate * dt + abs)
const RATE_TOLERANCE_MULTIPLIER = 5.0;
const RATE_TOLERANCE_ABS = 2.0;

// Real run deletion behavior
const DELETE_COMMIT_EVERY = 1000;   // commit every N deletions to reduce locks
const PROGRESS_EVERY_ROWS = 200;    // report progress every N processed rows
const PROGRESS_EVERY_MS = 1000;     // or at least every N milliseconds
const TEST_LIMIT_ROWS = 10000;       // cap test-run rows
// Log every Nth row during test run regardless of anomalies
const TEST_SAMPLE_EVERY_ROWS = 500;

function env_or(string $key, string $fallback): string {
	$val = getenv($key);
	return $val !== false ? $val : $fallback;
}

function get_pdo(): PDO {
	$host = env_or('DB_HOST', DB_HOST);
	$name = env_or('DB_NAME', DB_NAME);
	$user = env_or('DB_USER', DB_USER);
	$pass = env_or('DB_PASS', DB_PASS);
	$dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
	$options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	];
	// Only set MySQL-specific options if driver constants exist
	if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
		$options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
	}
	return new PDO($dsn, $user, $pass, $options);
}

function toMillisFromInput(?string $input): ?int {
	if ($input === null || $input === '') {
		return null;
	}
	$trim = trim($input);
	// Accept numeric inputs as ms (>=13 digits) or seconds (10 digits)
	if (preg_match('/^\d+$/', $trim) === 1) {
		$len = strlen($trim);
		if ($len >= 13) {
			return (int) $trim; // milliseconds
		}
		if ($len === 10) {
			return ((int) $trim) * 1000; // seconds
		}
		// Fallback: treat as seconds
		return ((int) $trim) * 1000;
	}
	// Expecting HTML datetime-local input like 2025-01-31T12:34
	$ts = strtotime($trim);
	return $ts === false ? null : ($ts * 1000);
}

function ms_to_readable(int $ms): string {
	$sec = (int) floor($ms / 1000);
	return date('Y-m-d H:i:s', $sec);
}

function detect_anomaly(?float $prevVal, float $currentVal): array {
	if ($prevVal === null) {
		return [false, 0.0];
	}
	$delta = $currentVal - $prevVal;
	$isAnomaly = ($delta < (-1.0 * DROP_THRESHOLD)) || ($delta > SPIKE_THRESHOLD);
	return [$isAnomaly, $delta];
}

