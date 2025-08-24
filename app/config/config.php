<?php
declare(strict_types=1);
session_start();

/* 日本語向けのデフォルト */
mb_language("Japanese");
mb_internal_encoding("UTF-8");
setlocale(LC_ALL, 'ja_JP.UTF-8');
date_default_timezone_set('Asia/Tokyo');

$driver = getenv('DB_DRIVER') ?: 'mysql'; // 'mysql' or 'pgsql'
$host   = getenv('DB_HOST')   ?: '127.0.0.1';
$name   = getenv('DB_NAME')   ?: 'leads';
$user   = getenv('DB_USER')   ?: 'root';
$pass   = getenv('DB_PASS')   ?: '';

$dsn = $driver === 'pgsql'
    ? "pgsql:host=$host;port=5432;dbname=$name"
    : "mysql:host=$host;dbname=$name;charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  echo "DB接続エラー: " . htmlspecialchars($e->getMessage());
  exit;
}

$sslmode = getenv('DB_SSLMODE') ?: null;
if ($driver === 'pgsql' && $sslmode) { $dsn .= ";sslmode={$sslmode}"; }


/* 共通ユーティリティ */
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_check(): void {
  if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400); exit('不正なリクエスト（CSRF）');
  }
}
function require_login(PDO $pdo): void {
  // 初回起動時：ユーザ未作成ならブートストラップへ誘導
  $cnt = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  if ($cnt === 0) {
    if (($_GET['action'] ?? '') !== 'bootstrap') {
      header('Location: /?action=bootstrap'); exit;
    }
    return; // bootstrap画面はログイン不要
  }
  if (empty($_SESSION['uid'])) {
    header('Location: /?action=login'); exit;
  }
}
function score(array $l): int {
  $budget   = (int)($l['budget'] ?? 0);             // 0–100
  $timeline = strtolower(trim($l['timeline'] ?? 'mid')); // short/mid/long
  $auth     = (int)($l['authority'] ?? 0);         // 0/1
  $techfit  = (int)($l['tech_fit'] ?? 5);          // 0–10
  $need     = strlen(trim((string)($l['need'] ?? ''))) > 10 ? 1 : 0;

  $tl = ['short'=>30,'mid'=>15,'long'=>5][$timeline] ?? 10;
  $s  = min(100, max(0, (int)round(
        0.4*$budget + 0.2*$tl + 0.25*($auth?25:0) + 0.1*($techfit*10) + 0.05*($need?5:0)
      )));
  return $s;
}
