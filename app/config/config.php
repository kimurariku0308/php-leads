<?php
declare(strict_types=1);

/**
 * 共通設定 / DB接続 / セキュリティユーティリティ
 * - PHP 8.3 / Apache
 * - Koyeb の Environment（DB_*）を読んで Postgres に接続
 * - Postgres は app スキーマを既定に（search_path）
 * - CSRF / ログイン必須ハンドラ / スコア計算を提供
 */

session_start();

/* 日本語向けデフォルト */
mb_language("Japanese");
mb_internal_encoding("UTF-8");
setlocale(LC_ALL, 'ja_JP.UTF-8');
date_default_timezone_set('Asia/Tokyo');

/* ========== DB接続 ========== */
$driver = getenv('DB_DRIVER') ?: 'pgsql';   // 'pgsql'（既定）/ 'mysql'
$host   = getenv('DB_HOST')   ?: '127.0.0.1';
$name   = getenv('DB_NAME')   ?: 'leads';
$user   = getenv('DB_USER')   ?: 'app_user';
$pass   = getenv('DB_PASS')   ?: '';
$ssl    = getenv('DB_SSLMODE') ?: null;     // Koyeb は 'require' のことが多い

if ($driver === 'pgsql') {
    $dsn = "pgsql:host={$host};dbname={$name}";
    if ($ssl) { $dsn .= ";sslmode={$ssl}"; }
} elseif ($driver === 'mysql') {
    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
} else {
    throw new RuntimeException("Unsupported DB_DRIVER: {$driver}");
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// Postgres は app スキーマを既定にする（テーブルは app.users / app.leads）
if ($driver === 'pgsql') {
    $pdo->exec("SET search_path TO app, public");
}

/* ========== セキュリティ系ユーティリティ ========== */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $t = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? '');
    if ($t === '' || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        echo 'Bad Request (invalid CSRF token)';
        exit;
    }
}

/**
 * ログイン必須化（bootstrap/login 以外では未ログインを弾く）
 * - users が 0 件のときは bootstrap に誘導
 */
function require_login(PDO $pdo): void {
    // 1) ユーザ0件なら初期管理者作成へ
    try {
        $cnt = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        // 万一 search_path が効いていない環境でも落ちないよう保険
        $cnt = 0;
    }
    if ($cnt === 0) {
        if (($_GET['action'] ?? '') !== 'bootstrap') {
            header('Location: /?action=bootstrap'); exit;
        }
        return; // bootstrap はログイン不要
    }

    // 2) 未ログインならログイン画面へ
    if (empty($_SESSION['uid'])) {
        if (($_GET['action'] ?? '') !== 'login') {
            header('Location: /?action=login'); exit;
        }
    }
}

/* ========== スコア計算 ========== */
function score(array $l): int {
    $budget   = (int)($l['budget'] ?? 0);                 // 0–100
    $timeline = strtolower(trim((string)($l['timeline'] ?? 'mid'))); // short/mid/long
    $auth     = (int)($l['authority'] ?? 0);              // 0/1（決裁者）
    $techfit  = (int)($l['tech_fit'] ?? 5);               // 0–10
    $need     = strlen(trim((string)($l['need'] ?? ''))) > 10 ? 1 : 0;

    $tlScore = ['short'=>30, 'mid'=>15, 'long'=>5][$timeline] ?? 10;

    $s = (int)round(
        0.40 * $budget           +   // 予算
        0.20 * $tlScore          +   // 期間
        0.25 * ($auth ? 25 : 0)  +   // 決裁者
        0.10 * ($techfit * 10)   +   // 技術適合
        0.05 * ($need ? 5 : 0)       // ニーズ量
    );

    return max(0, min(100, $s));
}
