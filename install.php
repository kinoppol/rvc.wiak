<?php
declare(strict_types=1);

require __DIR__ . '/install/Requirements.php';

session_name('rvcwiak_install');
session_start();

$root = __DIR__;
$configFile = $root . '/config/config.php';
$lockFile = $root . '/config/install.lock';
$uploadDir = $root . '/storage/uploads';

$alreadyInstalled = is_file($configFile) && is_file($lockFile);

$state = $_SESSION['install'] ?? [];
$errors = [];
$step = isset($_POST['step']) ? (int) $_POST['step'] : (isset($_GET['step']) ? (int) $_GET['step'] : 1);
$step = max(1, min(4, $step));

if ($alreadyInstalled && $step < 4) {
    $step = 0; // "already installed" screen
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------------
// Step transitions (POST)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 2) {
        // Coming from step 1: requirements + upload settings.
        $maxMb = max(1, (int) ($_POST['max_upload_mb'] ?? 10));
        $ceiling = Requirements::phpUploadCeilingBytes();
        $chosenBytes = $maxMb * 1024 * 1024;
        if ($ceiling > 0 && $chosenBytes > $ceiling) {
            $chosenBytes = $ceiling;
        }
        $state['upload_max_bytes'] = $chosenBytes;
        $state['upload_dir'] = $uploadDir;
        $_SESSION['install'] = $state;
    } elseif ($step === 3) {
        // Coming from step 2: database connection.
        $db = [
            'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'port' => trim((string) ($_POST['db_port'] ?? '3306')),
            'name' => trim((string) ($_POST['db_name'] ?? '')),
            'user' => trim((string) ($_POST['db_user'] ?? '')),
            'pass' => (string) ($_POST['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['host'], $db['port']);
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $dbNameEsc = str_replace('`', '', $db['name']);
            if ($dbNameEsc === '') {
                throw new RuntimeException('กรุณาระบุชื่อฐานข้อมูล');
            }
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbNameEsc}`");
            $sql = (string) file_get_contents($root . '/database/schema.sql');
            $lines = array_filter(explode("\n", $sql), fn ($l) => !str_starts_with(trim($l), '--'));
            $sqlNoComments = implode("\n", $lines);
            foreach (array_filter(array_map('trim', explode(';', $sqlNoComments))) as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
            $state['db'] = $db;
            $_SESSION['install'] = $state;
        } catch (Throwable $e) {
            $errors[] = 'เชื่อมต่อฐานข้อมูลหรือสร้างตารางไม่สำเร็จ: ' . $e->getMessage();
            $step = 2;
        }
    } elseif ($step === 4) {
        // Coming from step 3: create admin account + finalize.
        $fullName = trim((string) ($_POST['admin_name'] ?? ''));
        $username = trim((string) ($_POST['admin_username'] ?? ''));
        $password = (string) ($_POST['admin_password'] ?? '');
        $email = trim((string) ($_POST['admin_email'] ?? ''));

        if ($fullName === '' || $username === '' || strlen($password) < 8) {
            $errors[] = 'กรุณากรอกชื่อ-นามสกุล, ชื่อผู้ใช้ และรหัสผ่านอย่างน้อย 8 ตัวอักษร';
            $step = 3;
        } else {
            try {
                $db = $state['db'] ?? null;
                if (!$db) {
                    throw new RuntimeException('ไม่พบข้อมูลการเชื่อมต่อฐานข้อมูล กรุณาย้อนกลับไปขั้นตอนที่ 2');
                }
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, email, icon) VALUES (?,?,?,?,?)');
                $ins->execute([$username, $hash, $fullName, $email ?: null, 'shield-lock-fill']);
                $userId = (int) $pdo->lastInsertId();

                $roleIds = $pdo->query("SELECT id FROM roles WHERE code IN ('admin','director')")->fetchAll(PDO::FETCH_COLUMN);
                $urIns = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)');
                foreach ($roleIds as $rid) {
                    $urIns->execute([$userId, $rid]);
                }

                if (!is_dir(dirname($uploadDir))) {
                    @mkdir(dirname($uploadDir), 0755, true);
                }
                Requirements::ensureWritableDir($uploadDir);

                $configPhp = "<?php\nreturn [\n"
                    . "    'app' => [\n"
                    . "        'name' => 'ระบบมอบหมายและติดตามงาน',\n"
                    . "        'url' => '" . addslashes(rtrim((($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/')) . "',\n"
                    . "        'timezone' => 'Asia/Bangkok',\n"
                    . "        'debug' => false,\n"
                    . "    ],\n"
                    . "    'db' => [\n"
                    . "        'host' => '" . addslashes($db['host']) . "',\n"
                    . "        'port' => '" . addslashes($db['port']) . "',\n"
                    . "        'name' => '" . addslashes($db['name']) . "',\n"
                    . "        'user' => '" . addslashes($db['user']) . "',\n"
                    . "        'pass' => '" . addslashes($db['pass']) . "',\n"
                    . "        'charset' => 'utf8mb4',\n"
                    . "    ],\n"
                    . "    'upload' => [\n"
                    . "        'path' => __DIR__ . '/../storage/uploads',\n"
                    . "        'max_bytes' => " . (int) ($state['upload_max_bytes'] ?? 10485760) . ",\n"
                    . "    ],\n"
                    . "];\n";
                file_put_contents($configFile, $configPhp);
                file_put_contents($lockFile, 'installed_at=' . date('c') . "\n");

                unset($_SESSION['install']);
                $step = 5; // done
            } catch (Throwable $e) {
                $errors[] = 'สร้างบัญชีผู้ดูแลระบบไม่สำเร็จ: ' . $e->getMessage();
                $step = 3;
            }
        }
    }
}

$checks = Requirements::checks($uploadDir);
$checksOk = Requirements::allOk($checks);
$ceilingBytes = Requirements::phpUploadCeilingBytes();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ติดตั้งระบบมอบหมายและติดตามงาน</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>body{font-family:"IBM Plex Sans Thai",system-ui,sans-serif;background:#f1f5f9}</style>
</head>
<body>
<div class="container py-4 py-md-5" style="max-width:720px">
  <div class="d-flex align-items-center gap-2 mb-4">
    <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#2563eb,#0ea5e9);display:grid;place-items:center;color:#fff;font-size:1.2rem"><i class="bi bi-clipboard2-check-fill"></i></div>
    <div>
      <div style="font-weight:700;font-size:1.1rem">ตัวติดตั้งระบบมอบหมายและติดตามงาน</div>
      <div class="text-secondary" style="font-size:.8rem">EduTask Tracking — Installer</div>
    </div>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= h($e) ?></div>
  <?php endforeach; ?>

  <?php if ($step === 0): ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
      <div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle"></i> ระบบได้รับการติดตั้งไปแล้ว</div>
      <p class="text-secondary" style="font-size:.9rem">พบไฟล์ <code>config/config.php</code> และ <code>config/install.lock</code> อยู่แล้ว หากต้องการติดตั้งใหม่ กรุณาลบไฟล์ทั้งสองนี้ออกจากเซิร์ฟเวอร์ก่อน (การทำเช่นนี้จะไม่ลบฐานข้อมูลเดิม)</p>
      <a href="./" class="btn btn-primary">ไปยังหน้าเข้าสู่ระบบ</a>
    </div></div>

  <?php elseif ($step === 1): ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
      <h5 class="mb-3">ขั้นตอนที่ 1 · ตรวจสอบความพร้อมของเซิร์ฟเวอร์</h5>
      <ul class="list-group mb-4">
        <?php foreach ($checks as $c): ?>
          <li class="list-group-item d-flex align-items-start gap-2">
            <i class="bi <?= $c['ok'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
            <div><div style="font-weight:500"><?= h($c['label']) ?></div><div class="text-secondary" style="font-size:.8rem"><?= h($c['detail']) ?></div></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post">
        <input type="hidden" name="step" value="2">
        <div class="mb-3">
          <label class="form-label">ขนาดไฟล์อัปโหลดสูงสุดต่อไฟล์ (MB)</label>
          <input type="number" min="1" max="1024" name="max_upload_mb" class="form-control" value="10" required>
          <div class="form-text">
            ขีดจำกัดสูงสุดที่ php.ini ของเซิร์ฟเวอร์นี้อนุญาตในขณะนี้คือประมาณ
            <?= $ceilingBytes > 0 ? number_format($ceilingBytes / 1024 / 1024, 1) . ' MB' : 'ไม่ทราบ' ?>
            (กำหนดจาก upload_max_filesize / post_max_size) — หากตั้งค่าสูงกว่านี้ ระบบจะปรับลดให้อัตโนมัติ
          </div>
        </div>
        <button type="submit" class="btn btn-primary" <?= $checksOk ? '' : 'disabled' ?>>ถัดไป <i class="bi bi-arrow-right"></i></button>
        <?php if (!$checksOk): ?><div class="text-danger mt-2" style="font-size:.85rem">กรุณาแก้ไขรายการที่ยังไม่ผ่านก่อนดำเนินการต่อ</div><?php endif; ?>
      </form>
    </div></div>

  <?php elseif ($step === 2): ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
      <h5 class="mb-3">ขั้นตอนที่ 2 · ตั้งค่าฐานข้อมูล (MariaDB 10+)</h5>
      <form method="post">
        <input type="hidden" name="step" value="3">
        <div class="row g-3">
          <div class="col-8"><label class="form-label">Host</label><input type="text" name="db_host" class="form-control" value="127.0.0.1" required></div>
          <div class="col-4"><label class="form-label">Port</label><input type="text" name="db_port" class="form-control" value="3306" required></div>
          <div class="col-12"><label class="form-label">ชื่อฐานข้อมูล</label><input type="text" name="db_name" class="form-control" value="rvc_wiak" required></div>
          <div class="col-6"><label class="form-label">Username</label><input type="text" name="db_user" class="form-control" value="root" required></div>
          <div class="col-6"><label class="form-label">Password</label><input type="password" name="db_pass" class="form-control"></div>
        </div>
        <div class="form-text mb-3">ระบบจะสร้างฐานข้อมูลนี้ให้อัตโนมัติหากยังไม่มี พร้อมสร้างตารางทั้งหมดตาม database/schema.sql</div>
        <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> ย้อนกลับ</a>
        <button type="submit" class="btn btn-primary">ทดสอบและสร้างตาราง <i class="bi bi-arrow-right"></i></button>
      </form>
    </div></div>

  <?php elseif ($step === 3): ?>
    <div class="card border-0 shadow-sm"><div class="card-body">
      <h5 class="mb-3">ขั้นตอนที่ 3 · สร้างบัญชีผู้ดูแลระบบ</h5>
      <form method="post">
        <input type="hidden" name="step" value="4">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">ชื่อ-นามสกุล</label><input type="text" name="admin_name" class="form-control" required></div>
          <div class="col-6"><label class="form-label">ชื่อผู้ใช้ (username)</label><input type="text" name="admin_username" class="form-control" required></div>
          <div class="col-6"><label class="form-label">อีเมล (ถ้ามี)</label><input type="email" name="admin_email" class="form-control"></div>
          <div class="col-12"><label class="form-label">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label><input type="password" name="admin_password" class="form-control" minlength="8" required></div>
        </div>
        <div class="form-text mb-3">บัญชีนี้จะได้รับบทบาท "ผู้ดูแลระบบ" และ "ผู้อำนวยการ" เพื่อให้เข้าถึงทุกเมนูได้ตั้งแต่เริ่มต้น</div>
        <button type="submit" class="btn btn-primary">สร้างบัญชีและติดตั้งให้เสร็จสมบูรณ์ <i class="bi bi-check2"></i></button>
      </form>
    </div></div>

  <?php elseif ($step === 5): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center">
      <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem"></i>
      <h5 class="mt-3">ติดตั้งระบบเรียบร้อยแล้ว</h5>
      <p class="text-secondary">คุณสามารถเข้าสู่ระบบด้วยบัญชีผู้ดูแลระบบที่สร้างไว้ได้ทันที</p>
      <a href="./" class="btn btn-primary">ไปยังหน้าเข้าสู่ระบบ</a>
    </div></div>
  <?php endif; ?>

  <div class="text-center text-secondary mt-4" style="font-size:.75rem">ระบบมอบหมายและติดตามงาน (EduTask Tracking) · ต้องการ PHP 8+ และ MariaDB 10+</div>
</div>
</body>
</html>
