<?php
declare(strict_types=1);

// Database configuration (inherit style from cleaner)
const DB_HOST = 'localhost';
const DB_NAME = 'iobroker';
const DB_USER = 'iobroker_user';
const DB_PASS = 'change_me';

// Default ID like in cleaner
const DEFAULT_STATE_ID = 102;

// Execution tuning
const DELETE_COMMIT_EVERY = 1000;
const PROGRESS_EVERY_ROWS = 200;
const PROGRESS_EVERY_MS = 1000;
const TEST_LIMIT_ROWS = 20000;

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
        if ($len >= 13) return (int)$trim; // ms
        if ($len === 10) return ((int)$trim) * 1000; // sec
        return ((int)$trim) * 1000;
    }
    $ts = strtotime($trim);
    return $ts === false ? null : ($ts * 1000);
}

function ms_to_readable(int $ms): string {
    $sec = (int) floor($ms / 1000);
    return date('Y-m-d H:i:s', $sec);
}

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

