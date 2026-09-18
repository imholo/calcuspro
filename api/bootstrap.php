<?php
declare(strict_types=1);

function api_bootstrap(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

function api_fail(int $code, string $message, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(
        array_merge(['ok' => false, 'error' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

function api_ok(array $data): never
{
    echo json_encode(
        array_merge(['ok' => true], $data),
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

function api_require_post_json(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        api_fail(405, 'Метод не поддерживается');
    }

    $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if ($ctype === '' || strncmp($ctype, 'application/json', 16) !== 0) {
        api_fail(415, 'Ожидается application/json');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8192) {
        api_fail(413, 'Слишком большой запрос');
    }

    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        api_fail(400, 'Некорректный JSON');
    }

    if (!is_array($data)) {
        api_fail(400, 'Некорректный JSON');
    }

    return $data;
}

function api_rate_limit(string $bucket, int $max, int $windowSec): void
{
    $now = time();
    $key = 'rl_' . $bucket;
    $state = $_SESSION[$key] ?? ['start' => $now, 'count' => 0];

    if (($now - (int)$state['start']) >= $windowSec) {
        $state = ['start' => $now, 'count' => 0];
    }

    $state['count'] = (int)$state['count'] + 1;
    $_SESSION[$key] = $state;

    if ($state['count'] > $max) {
        api_fail(429, 'Слишком много запросов. Подождите немного.');
    }
}

function api_num(array $data, string $key, float $min, float $max): float
{
    if (!array_key_exists($key, $data)) {
        api_fail(422, "Поле «{$key}» обязательно");
    }

    $v = $data[$key];
    if (is_string($v)) {
        $v = str_replace(',', '.', trim($v));
    }
    if (!is_numeric($v)) {
        api_fail(422, "Поле «{$key}» должно быть числом");
    }

    $n = (float)$v;
    if (!is_finite($n) || $n < $min || $n > $max) {
        api_fail(422, "Поле «{$key}» вне допустимого диапазона");
    }

    return $n;
}

function api_enum(array $data, string $key, array $allowed): string
{
    $v = (string)($data[$key] ?? '');
    if (!in_array($v, $allowed, true)) {
        api_fail(422, "Недопустимое значение «{$key}»");
    }
    return $v;
}
