<?php
// setup.php — запусти один раз: http://localhost/eldercare2/setup.php
$dbFile = __DIR__ . '/backend/db/eldercare.sqlite';
if (file_exists($dbFile)) unlink($dbFile);

$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON;');

$tables = [
"users" => "CREATE TABLE users (
    id TEXT PRIMARY KEY, role TEXT NOT NULL, full_name TEXT NOT NULL,
    phone TEXT, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now'))
)",
"patients" => "CREATE TABLE patients (
    id TEXT PRIMARY KEY, user_id TEXT NOT NULL,
    full_name TEXT NOT NULL, birth_date TEXT,
    normal_systolic INTEGER DEFAULT 120, normal_diastolic INTEGER DEFAULT 80,
    created_at TEXT DEFAULT (datetime('now'))
)",
"access" => "CREATE TABLE access (
    id TEXT PRIMARY KEY, patient_id TEXT NOT NULL, user_id TEXT NOT NULL,
    is_active INTEGER DEFAULT 1, granted_at TEXT DEFAULT (datetime('now')),
    UNIQUE(patient_id, user_id)
)",
"medicines" => "CREATE TABLE medicines (
    id TEXT PRIMARY KEY, patient_id TEXT NOT NULL, name TEXT NOT NULL,
    dose TEXT, times TEXT NOT NULL, is_active INTEGER DEFAULT 1,
    created_by TEXT, created_at TEXT DEFAULT (datetime('now'))
)",
"intakes" => "CREATE TABLE intakes (
    id TEXT PRIMARY KEY, medicine_id TEXT NOT NULL, patient_id TEXT NOT NULL,
    scheduled_at TEXT NOT NULL, status TEXT DEFAULT 'pending',
    taken_at TEXT, skip_reason TEXT, comment TEXT
)",
"records" => "CREATE TABLE records (
    id TEXT PRIMARY KEY, patient_id TEXT NOT NULL, created_by TEXT,
    recorded_at TEXT DEFAULT (datetime('now')),
    systolic INTEGER, diastolic INTEGER, pulse INTEGER, note TEXT
)",
"events" => "CREATE TABLE events (
    id TEXT PRIMARY KEY, patient_id TEXT NOT NULL,
    type TEXT NOT NULL, severity TEXT DEFAULT 'normal',
    message TEXT NOT NULL, status TEXT DEFAULT 'new',
    created_at TEXT DEFAULT (datetime('now')), resolved_by TEXT
)",
"comments" => "CREATE TABLE comments (
    id TEXT PRIMARY KEY, patient_id TEXT NOT NULL, author_id TEXT NOT NULL,
    type TEXT DEFAULT 'comment', visibility TEXT DEFAULT 'all',
    content TEXT NOT NULL, created_at TEXT DEFAULT (datetime('now'))
)",
"audit_log" => "CREATE TABLE audit_log (
    id TEXT PRIMARY KEY, user_id TEXT, action TEXT, entity TEXT,
    entity_id TEXT, ip TEXT, ts TEXT
)",
];

$ok = []; $errors = [];
foreach ($tables as $name => $sql) {
    try { $pdo->exec($sql); $ok[] = "Таблица $name"; }
    catch (Exception $e) { $errors[] = "$name: " . $e->getMessage(); }
}

// Тестовые данные — пароль demo123
$hash = password_hash('demo123', PASSWORD_BCRYPT);
$data = [
    // Users
    ["INSERT OR IGNORE INTO users(id,role,full_name,phone,email,password_hash) VALUES(?,?,?,?,?,?)",
     ['u-patient','patient','Иванова Мария Петровна','+7-900-111-22-33','patient@test.com',$hash],
     'Пользователь пациент'],
    ["INSERT OR IGNORE INTO users(id,role,full_name,phone,email,password_hash) VALUES(?,?,?,?,?,?)",
     ['u-relative','relative','Иванов Сергей Александрович','+7-900-222-33-44','relative@test.com',$hash],
     'Пользователь родственник'],
    ["INSERT OR IGNORE INTO users(id,role,full_name,phone,email,password_hash) VALUES(?,?,?,?,?,?)",
     ['u-doctor','doctor','Петров Андрей Николаевич','+7-900-333-44-55','doctor@test.com',$hash],
     'Пользователь врач'],
    // Patient profile
    ["INSERT OR IGNORE INTO patients(id,user_id,full_name,birth_date,normal_systolic,normal_diastolic) VALUES(?,?,?,?,?,?)",
     ['p-001','u-patient','Иванова Мария Петровна','1948-03-15',130,85],
     'Профиль пациента'],
    // Access
    ["INSERT OR IGNORE INTO access(id,patient_id,user_id) VALUES(?,?,?)",['a-1','p-001','u-patient'],'Доступ пациента'],
    ["INSERT OR IGNORE INTO access(id,patient_id,user_id) VALUES(?,?,?)",['a-2','p-001','u-relative'],'Доступ родственника'],
    ["INSERT OR IGNORE INTO access(id,patient_id,user_id) VALUES(?,?,?)",['a-3','p-001','u-doctor'],'Доступ врача'],
    // Medicines
    ["INSERT OR IGNORE INTO medicines(id,patient_id,name,dose,times,created_by) VALUES(?,?,?,?,?,?)",
     ['m-1','p-001','Атенолол','50 мг','["08:00"]','u-relative'],'Лекарство Атенолол'],
    ["INSERT OR IGNORE INTO medicines(id,patient_id,name,dose,times,created_by) VALUES(?,?,?,?,?,?)",
     ['m-2','p-001','Рамиприл','5 мг','["08:00","20:00"]','u-relative'],'Лекарство Рамиприл'],
    // Intakes today
    ["INSERT OR IGNORE INTO intakes(id,medicine_id,patient_id,scheduled_at,status) VALUES(?,?,?,?,?)",
     ['i-1','m-1','p-001',date('Y-m-d').' 08:00:00','taken'],'Приём 1 (принят)'],
    ["INSERT OR IGNORE INTO intakes(id,medicine_id,patient_id,scheduled_at,status) VALUES(?,?,?,?,?)",
     ['i-2','m-2','p-001',date('Y-m-d').' 08:00:00','missed'],'Приём 2 (пропущен)'],
    ["INSERT OR IGNORE INTO intakes(id,medicine_id,patient_id,scheduled_at,status) VALUES(?,?,?,?,?)",
     ['i-3','m-2','p-001',date('Y-m-d').' 20:00:00','pending'],'Приём 3 (ожидает)'],
];

// Records last 7 days
$bpData = [
    [145,90,78,'Небольшая усталость',-6],
    [138,85,72,'Чувствую себя хорошо',-5],
    [162,100,88,'Головная боль',-4],
    [150,95,80,'Давление высокое с утра',-3],
    [142,88,75,'Норм',-2],
    [135,82,70,'Хорошо, гуляла',-1],
    [148,92,76,'Немного кружится голова',0],
];
foreach ($bpData as $i => [$sys,$dia,$pul,$note,$doff]) {
    $dt = date('Y-m-d H:i:s', strtotime("$doff days"));
    $data[] = ["INSERT OR IGNORE INTO records(id,patient_id,created_by,recorded_at,systolic,diastolic,pulse,note) VALUES(?,?,?,?,?,?,?,?)",
               ["r-$i",'p-001','u-patient',$dt,$sys,$dia,$pul,$note], "Запись давления $i"];
}

// Events
$data[] = ["INSERT OR IGNORE INTO events(id,patient_id,type,severity,message,status) VALUES(?,?,?,?,?,?)",
           ['e-1','p-001','critical_bp','critical','🚨 Критическое давление: 162/100 мм рт. ст.','new'],'Событие критическое АД'];
$data[] = ["INSERT OR IGNORE INTO events(id,patient_id,type,severity,message,status) VALUES(?,?,?,?,?,?)",
           ['e-2','p-001','missed_dose','normal','💊 Рамиприл 08:00 — пропущен приём','new'],'Событие пропуск'];
$data[] = ["INSERT OR IGNORE INTO events(id,patient_id,type,severity,message,status) VALUES(?,?,?,?,?,?)",
           ['e-3','p-001','doctor_recommendation','info','🩺 Рекомендация врача: Фиксируйте давление дважды в день','seen'],'Рекомендация врача'];

// Comments
$data[] = ["INSERT OR IGNORE INTO comments(id,patient_id,author_id,type,content) VALUES(?,?,?,?,?)",
           ['c-1','p-001','u-doctor','recommendation','Продолжайте фиксировать давление дважды в день. При систолическом выше 160 — немедленно сообщите родственникам.'],
           'Комментарий врача'];

foreach ($data as [$sql, $params, $label]) {
    try { $pdo->prepare($sql)->execute($params); $ok[] = $label; }
    catch (Exception $e) { $errors[] = "$label: " . $e->getMessage(); }
}

// Удаляем дублирующиеся записи лекарств и приёмов (если БД уже существовала)
try {
    // Оставляем только первую запись для каждой комбинации medicine_id + scheduled_at
    $pdo->exec("DELETE FROM intakes WHERE id NOT IN (
        SELECT MIN(id) FROM intakes GROUP BY medicine_id, scheduled_at
    )");
    $ok[] = "Очистка дублей приёмов";
} catch(Exception $e) { $errors[] = "Очистка: " . $e->getMessage(); }

// Check password
$row = $pdo->query("SELECT password_hash FROM users WHERE email='patient@test.com'")->fetch(PDO::FETCH_ASSOC);
$pwOk = $row && password_verify('demo123', $row['password_hash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Настройка ElderCare</title>
<style>
body{font-family:Arial,sans-serif;max-width:700px;margin:40px auto;padding:20px}
.ok  {background:#e8f5e9;border-left:4px solid #2e7d6e;padding:10px 16px;margin:6px 0;border-radius:4px;color:#1b5e20;font-size:0.95rem}
.err {background:#fdecea;border-left:4px solid #d94f3d;padding:10px 16px;margin:6px 0;border-radius:4px;color:#b71c1c}
.btn {display:inline-block;margin-top:24px;padding:16px 32px;background:#2e7d6e;color:#fff;text-decoration:none;border-radius:8px;font-size:1.1rem;font-weight:bold}
h2{margin-bottom:16px}
</style></head><body>
<h2><?= empty($errors) ? '✅ База данных создана!' : '⚠️ Частичная ошибка' ?></h2>
<?php foreach($ok as $m): ?><div class="ok">✓ <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<?php foreach($errors as $m): ?><div class="err">✗ <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<div class="ok" style="margin-top:16px"><?= $pwOk ? '✅ Пароль demo123 — проверен, вход будет работать!' : '❌ Ошибка проверки пароля!' ?></div>
<hr style="margin:24px 0">
<strong>Тестовые аккаунты (пароль: demo123)</strong>
<div class="ok">👴 Пациент — patient@test.com</div>
<div class="ok">👨‍👩‍👧 Родственник — relative@test.com</div>
<div class="ok">🩺 Врач — doctor@test.com</div>
<br><strong>Код для привязки тестового пациента: <span style="font-family:monospace;font-size:1.2rem;color:#2e7d6e">p-001</span></strong>
<br><a href="frontend/index.html" class="btn">🚀 Открыть сайт →</a>
</body></html>
