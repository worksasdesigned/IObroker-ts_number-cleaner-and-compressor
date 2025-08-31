<?php
declare(strict_types=1);

// Reuse same config approach as cleaner
const DB_HOST = 'localhost';
const DB_NAME = 'iobroker';
const DB_USER = 'iobroker_user';
const DB_PASS = 'change_me';

const DEFAULT_STATE_ID = 102;

// Streaming & limits
const PROGRESS_EVERY_ROWS = 200;
const PROGRESS_EVERY_MS = 1000;
const TEST_LIMIT_ROWS = 20000; // higher for grouping visibility
const RUN_BATCH_SIZE = 1000;   // rows per batch for deletes/inserts

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
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
    }
    return new PDO($dsn, $user, $pass, $options);
}

function toMillisFromInput(?string $input): ?int {
    if ($input === null || $input === '') return null;
    $trim = trim($input);
    if (preg_match('/^\d+$/', $trim) === 1) {
        $len = strlen($trim);
        if ($len >= 13) return (int) $trim;
        if ($len === 10) return ((int) $trim) * 1000;
        return ((int) $trim) * 1000;
    }
    $ts = strtotime($trim);
    return $ts === false ? null : ($ts * 1000);
}

function ms_to_readable(int $ms): string {
    $sec = (int) floor($ms / 1000);
    return date('Y-m-d H:i:s', $sec);
}

// Interval helpers (value in milliseconds)
const INTERVALS = [
    '1m'  => 60_000,
    '5m'  => 5 * 60_000,
    '15m' => 15 * 60_000,
    '30m' => 30 * 60_000,
    '1h'  => 60 * 60_000,
    '3h'  => 3 * 60 * 60_000,
    '6h'  => 6 * 60 * 60_000,
    '12h' => 12 * 60 * 60_000,
    '1d'  => 24 * 60 * 60_000,
];

function resolve_interval_ms(string $key): ?int {
    return INTERVALS[$key] ?? null;
}

?>

