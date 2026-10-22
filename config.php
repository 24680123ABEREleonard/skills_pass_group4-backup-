<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $database = getenv('DB_NAME') ?: 'aurora_group3';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') ?: '';

    $connection = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}

function escape(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submitted) || !is_string($known) || $known === '' || !hash_equals($known, $submitted)) {
        http_response_code(419);
        exit('Your form session expired. Go back and try again.');
    }
}

function signed_in_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}
