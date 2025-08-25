<?php
require __DIR__ . '/../config/config.php';

$action = $_GET['action'] ?? 'list';

/* ====== 初期管理者の作成（ユーザ0件時だけ表示） ====== */
if ($action === 'bootstrap') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if ($email === '' || $pass === '') { $error = 'メールとパスワードは必須です'; }
    else {
      $stmt = $pdo->prepare('INSERT INTO users(email, password_hash) VALUES (?, ?)');
      $stmt->execute([$email, password_hash($pass, PASSWORD_DEFAULT)]);
      header('Location:/?action=login'); exit;
    }
  }
  $t = csrf_token();
  $errorText = isset($error) ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';
  echo <<<HTML
  <!doctype html><html lang="ja"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>初期ユーザ作成</title><link rel="stylesheet" href="/styles.css">
  </head><body><div class="container">
    <h1>初期管理者の作成</h1>
    <p>初回起動のため、管理者ユーザを作成します。</p>
    <form method="post">
      <input type="hidden" name="csrf" value="$t">
      <div class="row"><input name="email" placeholder="メールアドレス" required></div>
      <div class="row"><input name="password" type="password" placeholder="パスワード" required></div>
      <input type="submit" value="作成する">
      <p style="color:red;">$errorText</p>
    </form>
  </div></body></html>
HTML;
  exit;
}

/* ====== ログイン処理（POST） ====== */
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email=?');
  $stmt->execute([$_POST['email'] ?? '']);
  $u = $stmt->fetch();
  if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
    $_SESSION['uid'] = (int)$u['id'];
    header('Location: /'); exit;
  }
  $error = 'メールまたはパスワードが違います';
}

/* ====== ログアウト ====== */
if ($action === 'logout') { session_destroy(); header('Location:/?action=login'); exit; }

/* ====== ログイン画面（GET）※ここでは require_login を呼ばない ====== */
if ($action === 'login') {
  $t = csrf_token();
  $errorText = isset($error) ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';
  echo <<<HTML
  <!doctype html><html lang="ja"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ログイン</title><link rel="stylesheet" href="/styles.css">
  </head><body><div class="container">
    <h1>ログイン</h1>
    <form method="post">
      <input type="hidden" name="csrf" value="$t" />
      <div class="row"><input name="email" placeholder="メール" required></div>
      <div class="row"><input name="password" type="password" placeholder="パスワード" required></div>
      <input type="submit" value="ログイン">
      <p style="color:red;">$errorText</p>
    </form>
  </div></body></html>
HTML;
  exit;
}

/* ここでログイン必須化（bootstrap/login以外） */
require_login($pdo);

/* ====== CRUDなど ====== */
if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $data = [
    'company'=>$_POST['company']??'',
    'contact'=>$_POST['contact']??'',
    'email'  =>$_POST['email']??'',
    'phone'  =>$_POST['phone']??'',
    'budget' =>(int)($_POST['budget']??0),
    'timeline'=>$_POST['timeline']??'mid',
    'authority'=> isset($_POST['authority']) ? 1 : 0,
    'tech_fit'=>(int)($_POST['tech_fit']??5),
    'need'   =>$_POST['need']??'',
    'notes'  =>$_POST['notes']??'',
  ];
  $data['score'] = score($data);

  if (!empty($_POST['id'])) {
    $sql = 'UPDATE leads SET company=?,contact=?,email=?,phone=?,budget=?,timeline=?,authority=?,tech_fit=?,need=?,notes=?,score=? WHERE id=?';
    $vals = array_values($data); $vals[] = (int)$_POST['id'];
  } else {
    $sql = 'INSERT INTO leads(company,contact,email,phone,budget,timeline,authority,tech_fit,need,notes,score) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
    $vals = array_values($data);
  }
  $stmt = $pdo->prepare($sql); $stmt->execute($vals);
  header('Location:/'); exit;
}

if ($action === 'delete' && isset($_GET['id'])) {
  $stmt = $pdo->prepare('DELETE FROM leads WHERE id=?'); $stmt->execute([(int)$_GET['id']]);
  header('Location:/'); exit;
}

if ($action === 'export') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="leads.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','company','contact','email','phone','budget','timeline','authority','tech_fit','need','notes','score','created_at']);
  foreach ($pdo->query('SELECT * FROM leads ORDER BY score DESC, created_at DESC') as $r) fputcsv($out, $r);
  exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD']==='POST' && is_uploaded_file($_FILES['csv']['tmp_name'])) {
  csrf_check();
  $f = fopen($_FILES['csv']['tmp_name'],'r'); $header = fgetcsv($f);
  while ($row = fgetcsv($f)) {
    $rec = array_combine($header, $row);
    $rec['score'] = score($rec);
    $stmt = $pdo->prepare('INSERT INTO leads(company,contact,email,phone,budget,timeline,authority,tech_fit,need,notes,score) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$rec['company'],$rec['contact'],$rec['email'],$rec['phone'],(int)$rec['budget'],$rec['timeline'],(int)$rec['authority'],(int)$rec['tech_fit'],$rec['need'],$rec['notes'],(int)$rec['score']]);
  }
  header('Location:/'); exit;
}

/* ====== 一覧 + 入力フォーム ====== */
$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM leads';
$params = [];
if ($q !== '') { $sql .= ' WHERE company LIKE ? OR contact LIKE ? OR email LIKE ?'; $params = ["%$q%","%$q%","%$q%"]; }
$sql .= ' ORDER BY score DESC, created_at DESC';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();

$edit = ['id'=>'','company'=>'','contact'=>'','email'=>'','phone'=>'','budget'=>50,'timeline'=>'mid','authority'=>0,'tech_fit'=>5,'need'=>'','notes'=>''];
if ($action === 'edit' && isset($_GET['id'])) {
  $st = $pdo->prepare('SELECT * FROM leads WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit = $st->fetch() ?: $edit;
}
$t = csrf_token();
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>リード管理 & 受注確度スコア</title>
  <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
  <h1>リード管理 & 受注確度スコア</h1>
  <p><a class="button" href="/?action=export">CSVエクスポート</a>
     <a class="button" href="/?action=logout">ログアウト</a></p>
  <form method="get" style="margin:.5rem 0;">
    <input type="hidden" name="action" value="list">
    <input name="q" placeholder="会社名・担当者・メールで検索" value="<?=htmlspecialchars($q)?>">
    <input type="submit" value="検索">
  </form>
  <table>
    <tr><th>ID</th><th>会社</th><th>担当</th><th>連絡先</th><th>予算</th><th>期間</th><th>決裁</th><th>Tech</th><th>Score</th><th></th></tr>
    <?php foreach($rows as $r): $cls=$r['score']>=80?'score-80':($r['score']>=50?'score-50':'score-0'); ?>
      <tr class="<?=$cls?>">
        <td><?=$r['id']?></td>
        <td><?=htmlspecialchars($r['company'])?></td>
        <td><?=htmlspecialchars($r['contact'])?></td>
        <td><?=htmlspecialchars($r['email'])?></td>
        <td><?=$r['budget']?></td>
        <td><?=$r['timeline']?></td>
        <td><?=$r['authority']?'Yes':'No'?></td>
        <td><?=$r['tech_fit']?></td>
        <td><span class="badge"><?=$r['score']?></span></td>
        <td>
          <a class="button" href="/?action=edit&id=<?=$r['id']?>">編集</a>
          <a class="button" href="/?action=delete&id=<?=$r['id']?>" onclick="return confirm('削除しますか？')">削除</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2><?=$edit['id']?'編集':'新規'?></h2>
  <form method="post" action="/?action=store" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=$t?>">
    <input type="hidden" name="id" value="<?=htmlspecialchars($edit['id'])?>">
    <div class="row">
      <input name="company" placeholder="会社名" value="<?=htmlspecialchars($edit['company'])?>" required>
      <input name="contact" placeholder="担当者" value="<?=htmlspecialchars($edit['contact'])?>">
    </div>
    <div class="row">
      <input name="email" placeholder="email" value="<?=htmlspecialchars($edit['email'])?>">
      <input name="phone" placeholder="電話" value="<?=htmlspecialchars($edit['phone'])?>">
    </div>
    <div class="row">
      <input name="budget" type="number" min="0" max="100" value="<?=htmlspecialchars((string)$edit['budget'])?>">
      <select name="timeline">
        <option <?=$edit['timeline']==='short'?'selected':''?> value="short">短期</option>
        <option <?=$edit['timeline']==='mid'?'selected':''?> value="mid">中期</option>
        <option <?=$edit['timeline']==='long'?'selected':''?> value="long">長期</option>
      </select>
      <label><input type="checkbox" name="authority" <?=$edit['authority']?'checked':''?>> 決裁者</label>
      <input name="tech_fit" type="number" min="0" max="10" value="<?=htmlspecialchars((string)$edit['tech_fit'])?>">
    </div>
    <div class="row"><textarea name="need" placeholder="課題・ニーズ" rows="3"><?=htmlspecialchars($edit['need'])?></textarea></div>
    <div class="row"><textarea name="notes" placeholder="メモ" rows="2"><?=htmlspecialchars($edit['notes'])?></textarea></div>
    <input type="submit" value="保存">
  </form>

  <h2>CSVインポート</h2>
  <form method="post" action="/?action=import" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=$t?>">
    <input type="file" name="csv" accept=".csv" required>
    <input type="submit" value="取り込み">
  </form>
</div>
</body></html>
