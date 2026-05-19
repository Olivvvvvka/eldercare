<?php
// api.php — единый обработчик всех API запросов
// Вызов: api.php?do=login, api.php?do=records&pid=p-001 и т.д.
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/mail/notify.php';

// Клиент всегда передаёт локальное время явно (настроить в php.ini: date.timezone = Europe/Moscow)
// Клиент всегда передаёт локальное время явно чтобы избежать расхождений

$do  = $_GET['do'] ?? '';
$pid = $_GET['pid'] ?? ''; // patient_id

// ============================================================
// AUTH
// ============================================================

if ($do === 'login') {
    $b        = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = strtolower(trim($b['email'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$email || !$password) die_json(['error'=>'Введите email и пароль'], 400);

    $stmt = db()->prepare("SELECT * FROM users WHERE email=?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($password, $u['password_hash']))
        die_json(['error'=>'Неверный email или пароль'], 401);

    // Найти patient_id если роль пациент
    $patient_id = null;
    if ($u['role'] === 'patient') {
        $ps = db()->prepare("SELECT id FROM patients WHERE user_id=?");
        $ps->execute([$u['id']]);
        $p = $ps->fetch();
        $patient_id = $p['id'] ?? null;
    }

    $token = jwt_make(['uid'=>$u['id'], 'role'=>$u['role'], 'name'=>$u['full_name'], 'pid'=>$patient_id]);
    log_action('LOGIN', 'users', $u['id'], $u['id']);
    die_json(['token'=>$token, 'uid'=>$u['id'], 'role'=>$u['role'], 'name'=>$u['full_name'], 'pid'=>$patient_id]);
}

if ($do === 'register') {
    $b          = json_decode(file_get_contents('php://input'), true) ?? [];
    $name       = trim($b['full_name'] ?? '');
    $email      = strtolower(trim($b['email'] ?? ''));
    $password   = $b['password'] ?? '';
    $role       = $b['role'] ?? 'patient';
    $birth      = $b['birth_date'] ?? '';
    $norm_sys   = (int)($b['normal_systolic'] ?? 120);
    $norm_dia   = (int)($b['normal_diastolic'] ?? 80);

    if (!$name || !$email || !$password) die_json(['error'=>'Заполните все поля'], 400);
    if (!in_array($role,['patient','relative','doctor'])) die_json(['error'=>'Неверная роль'], 400);
    if (strlen($password) < 8) die_json(['error'=>'Пароль не менее 8 символов'], 400);

    $chk = db()->prepare("SELECT id FROM users WHERE email=?");
    $chk->execute([$email]);
    if ($chk->fetch()) die_json(['error'=>'Email уже зарегистрирован'], 409);

    $uid  = uuid();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    db()->prepare("INSERT INTO users(id,role,full_name,email,password_hash) VALUES(?,?,?,?,?)")
        ->execute([$uid, $role, $name, $email, $hash]);

    $patient_id = null;
    if ($role === 'patient') {
        $patient_id = uuid();
        db()->prepare("INSERT INTO patients(id,user_id,full_name,birth_date,normal_systolic,normal_diastolic) VALUES(?,?,?,?,?,?)")
            ->execute([$patient_id, $uid, $name, $birth ?: null, $norm_sys, $norm_dia]);
        db()->prepare("INSERT INTO access(id,patient_id,user_id) VALUES(?,?,?)")
            ->execute([uuid(), $patient_id, $uid]);
    }

    $token = jwt_make(['uid'=>$uid, 'role'=>$role, 'name'=>$name, 'pid'=>$patient_id]);
    log_action('CREATE', 'users', $uid, $uid);
    die_json(['token'=>$token, 'uid'=>$uid, 'role'=>$role, 'name'=>$name, 'pid'=>$patient_id]);
}

// ============================================================
// Все остальные запросы требуют авторизации
// ============================================================
$me = require_auth();
$uid  = $me['uid'];
$role = $me['role'];

// Проверка доступа к пациенту (если нужен pid)
if ($pid && !can_access($pid, $uid)) die_json(['error'=>'Нет доступа к этому пациенту'], 403);

// ============================================================
// PATIENTS
// ============================================================

if ($do === 'my_patient') {
    // Пациент смотрит свой профиль + код приглашения
    $s = db()->prepare("SELECT id, full_name, birth_date, normal_systolic, normal_diastolic FROM patients WHERE user_id=?");
    $s->execute([$uid]);
    $p = $s->fetch();
    if (!$p) die_json(['error'=>'Профиль не найден'], 404);
    $p['invite_code'] = $p['id'];
    die_json($p);
}

if ($do === 'my_patients') {
    // Список пациентов для родственника/врача
    $s = db()->prepare("SELECT p.id, p.full_name, p.birth_date, p.normal_systolic, p.normal_diastolic
                        FROM patients p JOIN access a ON a.patient_id=p.id
                        WHERE a.user_id=? AND a.is_active=1");
    $s->execute([$uid]);
    die_json($s->fetchAll());
}

if ($do === 'connect_patient') {
    // Привязаться к пациенту по коду (=patient_id)
    $b    = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = trim($b['code'] ?? '');
    if (!$code) die_json(['error'=>'Введите код'], 400);

    $s = db()->prepare("SELECT id, full_name FROM patients WHERE id=?");
    $s->execute([$code]);
    $p = $s->fetch();
    if (!$p) die_json(['error'=>'Пациент не найден. Проверьте код.'], 404);

    // Проверяем существующую связь
    $chk = db()->prepare("SELECT id, is_active FROM access WHERE patient_id=? AND user_id=?");
    $chk->execute([$p['id'], $uid]);
    $ex = $chk->fetch();
    if ($ex) {
        if ($ex['is_active']) die_json(['error'=>'Вы уже подключены к этому пациенту'], 409);
        db()->prepare("UPDATE access SET is_active=1 WHERE patient_id=? AND user_id=?")->execute([$p['id'],$uid]);
    } else {
        db()->prepare("INSERT INTO access(id,patient_id,user_id) VALUES(?,?,?)")->execute([uuid(),$p['id'],$uid]);
    }
    log_action('CREATE', 'access', $p['id'], $uid);
    die_json(['success'=>true, 'patient_id'=>$p['id'], 'patient_name'=>$p['full_name']]);
}

if ($do === 'patient_profile') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') log_action('READ', 'patients', $pid, $uid);
    $s = db()->prepare("SELECT id,full_name,birth_date,normal_systolic,normal_diastolic FROM patients WHERE id=?");
    $s->execute([$pid]);
    die_json($s->fetch());
}

// ============================================================
// RECORDS (дневник давления)
// ============================================================

if ($do === 'get_records') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') log_action('READ', 'records', $pid, $uid);
    $days     = (int)($_GET['days'] ?? 30);
    $daysPlus = $days + 1; // +1 день буфер для компенсации разницы UTC и локального времени
    $s = db()->prepare("SELECT r.*, u.full_name as author
                        FROM records r LEFT JOIN users u ON r.created_by=u.id
                        WHERE r.patient_id=? AND r.recorded_at>=datetime('now','-{$daysPlus} days')
                        ORDER BY r.recorded_at DESC");
    $s->execute([$pid]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['note'] = decryptField($r['note'] ?? '');
        $s = (int)$r['systolic']; $d = (int)$r['diastolic']; $p = (int)$r['pulse'];
        $r['crit_high']  = ($s > 160 || $d > 100);
        $r['warn_high']  = !$r['crit_high'] && ($s > 135 || $d > 85);
        $r['crit_low']   = ($s > 0 && $s < 80) || ($d > 0 && $d < 50);
        $r['warn_low']   = !$r['crit_low'] && (($s > 0 && $s <= 95) || ($d > 0 && $d <= 60));
        $r['crit_pulse'] = ($p > 0 && ($p < 40 || $p > 130));
        $r['warn_pulse'] = !$r['crit_pulse'] && ($p > 0 && ($p < 60 || $p > 100));
        $r['is_critical'] = $r['crit_high'] || $r['crit_low'] || $r['crit_pulse'];
        $r['is_warning']  = !$r['is_critical'] && ($r['warn_high'] || $r['warn_low'] || $r['warn_pulse']);
    }
    die_json($rows);
}

if ($do === 'add_record') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') die_json(['error'=>'Только пациент может вносить записи'], 403);
    $b   = json_decode(file_get_contents('php://input'), true) ?? [];
    $sys = isset($b['systolic'])  ? (int)$b['systolic']  : null;
    $dia = isset($b['diastolic']) ? (int)$b['diastolic'] : null;
    $pul = isset($b['pulse'])     ? (int)$b['pulse']     : null;
    $note= trim($b['note'] ?? '');

    if ($sys && ($sys < 50 || $sys > 300)) die_json(['error'=>'Верхнее давление: 50–300'], 400);
    if ($dia && ($dia < 30 || $dia > 200)) die_json(['error'=>'Нижнее давление: 30–200'], 400);

    $id         = uuid();
    $recordedAt = trim($b['recorded_at'] ?? '');
    // Валидируем дату если передана
    if ($recordedAt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $recordedAt);
        if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $recordedAt);
        $recordedAt = $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    } else {
        $recordedAt = date('Y-m-d H:i:s');
    }
    db()->prepare("INSERT INTO records(id,patient_id,created_by,recorded_at,systolic,diastolic,pulse,note) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$id, $pid, $uid, $recordedAt, $sys, $dia, $pul, encryptField($note)]);
    log_action('CREATE', 'records', $id, $uid);

    // Уровни давления
    $critHighBP = ($sys && $sys > 160) || ($dia && $dia > 100);
    $warnHighBP = !$critHighBP && (($sys && $sys > 140) || ($dia && $dia > 90));
    $critLowBP  = ($sys && $sys < 80)  || ($dia && $dia < 50);
    $warnLowBP  = !$critLowBP && (($sys && $sys < 90) || ($dia && $dia < 60));

    // Уровни пульса
    $critPulse  = $pul && ($pul < 40 || $pul > 130);
    $warnPulse  = !$critPulse && $pul && ($pul < 55 || $pul > 100);

    $isCritical = $critHighBP || $critLowBP || $critPulse;
    $isWarning  = !$isCritical && ($warnHighBP || $warnLowBP || $warnPulse);

    // Создаём события
    // Получаем имя пациента для уведомлений
    $patStmt = db()->prepare("SELECT full_name FROM patients WHERE id=?");
    $patStmt->execute([$pid]);
    $patName = $patStmt->fetch()['full_name'] ?? 'Пациент';

    // Создаём события и отправляем email родственникам
    if ($critHighBP) {
        insert_event($pid, 'critical_bp', 'critical', "Критически высокое давление: {$sys}/{$dia} мм рт. ст.!");
        try { notifyRelatives($pid, $patName,
            "🚨 Критическое давление — {$patName}",
            "🚨 Критически высокое давление!",
            "Зафиксировано давление {$sys}/{$dia} мм рт. ст." . ($pul ? ", пульс {$pul} уд/мин" : "") . ". Требуется немедленное внимание!",
            '#d94f3d'); } catch(Exception $e) { error_log("Email: ".$e->getMessage()); }
    } elseif ($warnHighBP) {
        insert_event($pid, 'critical_bp', 'normal', "Повышенное давление: {$sys}/{$dia} мм рт. ст. — обратите внимание");
        try { notifyRelatives($pid, $patName,
            "⚠️ Повышенное давление — {$patName}",
            "⚠️ Повышенное давление",
            "Зафиксировано давление {$sys}/{$dia} мм рт. ст." . ($pul ? ", пульс {$pul} уд/мин" : "") . ". Рекомендуется обратить внимание.",
            '#e6a817'); } catch(Exception $e) { error_log("Email: ".$e->getMessage()); }
    } elseif ($critLowBP) {
        insert_event($pid, 'critical_bp', 'critical', "Критически низкое давление: {$sys}/{$dia} мм рт. ст.!");
        try { notifyRelatives($pid, $patName,
            "🚨 Критически низкое давление — {$patName}",
            "🚨 Критически низкое давление!",
            "Зафиксировано давление {$sys}/{$dia} мм рт. ст." . ($pul ? ", пульс {$pul} уд/мин" : "") . ". Требуется немедленное внимание!",
            '#d94f3d'); } catch(Exception $e) { error_log("Email: ".$e->getMessage()); }
    } elseif ($warnLowBP) {
        insert_event($pid, 'critical_bp', 'normal', "Пониженное давление: {$sys}/{$dia} мм рт. ст. — обратите внимание");
        try { notifyRelatives($pid, $patName,
            "⚠️ Пониженное давление — {$patName}",
            "⚠️ Пониженное давление",
            "Зафиксировано давление {$sys}/{$dia} мм рт. ст." . ($pul ? ", пульс {$pul} уд/мин" : "") . ". Рекомендуется обратить внимание.",
            '#e6a817'); } catch(Exception $e) { error_log("Email: ".$e->getMessage()); }
    }
    if ($critPulse) {
        insert_event($pid, 'critical_bp', 'critical', "Критический пульс: {$pul} уд/мин!");
        try { notifyRelatives($pid, $patName,
            "🚨 Критический пульс — {$patName}",
            "🚨 Критический пульс!",
            "Зафиксирован пульс {$pul} уд/мин при давлении {$sys}/{$dia}. Требуется немедленное внимание!",
            '#d94f3d'); } catch(Exception $e) { error_log("Email: ".$e->getMessage()); }
    } elseif ($warnPulse) {
        insert_event($pid, 'critical_bp', 'normal', "Необычный пульс: {$pul} уд/мин — обратите внимание");
    }

    die_json([
        'id'          => $id,
        'recorded_at' => date('Y-m-d H:i:s'),
        'is_critical' => $isCritical,
        'is_warning'  => $isWarning,
        'crit_high'   => $critHighBP,
        'warn_high'   => $warnHighBP,
        'crit_low'    => $critLowBP,
        'warn_low'    => $warnLowBP,
        'crit_pulse'  => $critPulse,
        'warn_pulse'  => $warnPulse,
    ], 201);
}

// ============================================================
// MEDICINES + INTAKES
// ============================================================

if ($do === 'get_medicines') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') log_action('READ', 'medicines', $pid, $uid);
    $date = $_GET['date'] ?? date('Y-m-d');
    $s = db()->prepare("
        SELECT m.id, m.name, m.dose, m.times,
               i.id as intake_id, i.scheduled_at, i.status, i.taken_at, i.skip_reason
        FROM medicines m
        LEFT JOIN intakes i ON i.medicine_id=m.id AND date(i.scheduled_at)=?
        WHERE m.patient_id=? AND m.is_active=1
        ORDER BY i.scheduled_at ASC
    ");
    $s->execute([$date, $pid]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['times']       = json_decode($r['times'] ?? '[]', true);
        $r['skip_reason'] = decryptField($r['skip_reason'] ?? '');
    }
    die_json($rows);
}

if ($do === 'add_medicine') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if (!in_array($role,['patient','relative'])) die_json(['error'=>'Нет прав'], 403);
    $b     = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($b['name'] ?? '');
    $dose  = $b['dose'] ?? '';
    $times = $b['times'] ?? [];
    if (!$name || !$times) die_json(['error'=>'Название и время обязательны'], 400);

    $mid = uuid();
    db()->prepare("INSERT INTO medicines(id,patient_id,name,dose,times,created_by) VALUES(?,?,?,?,?,?)")
        ->execute([$mid, $pid, $name, $dose, json_encode($times), $uid]);
    log_action('CREATE', 'medicines', $mid, $uid);

    // Создаём отметки на 30 дней — все времена без пропусков
    for ($i = 0; $i < 30; $i++) {
        $day = date('Y-m-d', strtotime("+$i days"));
        foreach ($times as $t) {
            db()->prepare("INSERT INTO intakes(id,medicine_id,patient_id,scheduled_at) VALUES(?,?,?,?)")
                ->execute([uuid(), $mid, $pid, "$day $t:00"]);
        }
    }
    die_json(['id'=>$mid], 201);
    die_json(['id'=>$mid], 201);
}

if ($do === 'mark_intake') {
    $b        = json_decode(file_get_contents('php://input'), true) ?? [];
    $iid      = $b['intake_id'] ?? null;
    $status   = $b['status'] ?? 'taken';
    $reason   = $b['skip_reason'] ?? null;
    if (!$iid) die_json(['error'=>'intake_id обязателен'], 400);
    if (!in_array($status,['taken','missed'])) die_json(['error'=>'Статус: taken или missed'], 400);
    $takenAt = $status === 'taken' ? date('Y-m-d H:i:s') : null;
    db()->prepare("UPDATE intakes SET status=?,taken_at=?,skip_reason=? WHERE id=?")
        ->execute([$status, $takenAt, encryptField($reason), $iid]);
    log_action('UPDATE', 'intakes', $iid, $uid);

    // Email родственникам при пропуске лекарства
    if ($status === 'missed') {
        // Получаем данные о лекарстве и пациенте
        $iStmt = db()->prepare("SELECT i.patient_id, m.name as med_name, i.scheduled_at,
                                        p.full_name as pat_name
                                FROM intakes i JOIN medicines m ON i.medicine_id=m.id
                                JOIN patients p ON i.patient_id=p.id
                                WHERE i.id=?");
        $iStmt->execute([$iid]);
        $intake = $iStmt->fetch();
        if ($intake) {
            $time = substr($intake['scheduled_at'], 11, 5);
        try {
                notifyRelatives(
                    $intake['patient_id'],
                    $intake['pat_name'],
                    "💊 Пропущен приём лекарства — {$intake['pat_name']}",
                    "💊 Пропущен приём лекарства",
                    "Пациент пропустил приём препарата <strong>{$intake['med_name']}</strong>" .
                    " запланированный на {$time}." .
                    ($reason ? " Причина: {$reason}." : ''),
                    '#e6a817'
                );
        } catch(Exception $emailEx) { error_log("Email: ".$emailEx->getMessage()); }
        }
    }

    die_json(['success'=>true, 'status'=>$status]);
}

// ============================================================
// EVENTS (уведомления)
// ============================================================

if ($do === 'get_events') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    $status = $_GET['status'] ?? null;
    $sql = "SELECT * FROM events WHERE patient_id=?";
    $params = [$pid];
    if ($status) { $sql .= " AND status=?"; $params[] = $status; }
    $sql .= " ORDER BY created_at DESC LIMIT 50";
    $s = db()->prepare($sql);
    $s->execute($params);
    die_json($s->fetchAll());
}

if ($do === 'resolve_event') {
    $b   = json_decode(file_get_contents('php://input'), true) ?? [];
    $eid = $b['event_id'] ?? null;
    if (!$eid) die_json(['error'=>'event_id обязателен'], 400);
    db()->prepare("UPDATE events SET status='seen', resolved_by=? WHERE id=?")
        ->execute([$uid, $eid]);
    log_action('UPDATE', 'events', $eid, $uid);
    die_json(['success'=>true]);
}

if ($do === 'add_comment') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    $b          = json_decode(file_get_contents('php://input'), true) ?? [];
    $content    = trim($b['content'] ?? '');
    $type       = $b['type'] ?? 'comment';
    $visibility = $b['visibility'] ?? 'all';
    $severity   = $b['severity'] ?? 'info';
    if (!$content) die_json(['error'=>'Текст обязателен'], 400);
    // Рекомендации могут отправлять врач и родственник (не только врач)
    if ($type === 'recommendation' && !in_array($role, ['doctor','relative'])) die_json(['error'=>'Нет прав'], 403);

    $cid = insert_comment($pid, $uid, $type, $visibility, $content);
    log_action('CREATE', 'comments', $cid, $uid);

    // Создаём событие-уведомление
    $msg = match($type) {
        'recommendation' => "Рекомендация врача: $content",
        'urgent_visit'   => "Врач рекомендует очный приём: $content",
        default          => "Сообщение от родственника: $content",
    };
    $sev = ($type === 'urgent_visit') ? 'critical' : ($type === 'recommendation' ? $severity : 'info');
    $evtype = ($type === 'urgent_visit') ? 'urgent_visit' : 'doctor_recommendation';
    insert_event($pid, $evtype, $sev, $msg);
    die_json(['success'=>true], 201);
}

// ============================================================
// STATS (для родственника и врача)
// ============================================================

if ($do === 'get_stats') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') log_action('READ', 'stats', $pid, $uid);
    $days = (int)($_GET['days'] ?? 30);

    // BP averages
    $bp = db()->prepare("
        SELECT ROUND(AVG(systolic)) as avg_sys, ROUND(AVG(diastolic)) as avg_dia,
               ROUND(AVG(pulse)) as avg_pulse, MAX(systolic) as max_sys,
               COUNT(*) as total,
               SUM(CASE WHEN systolic>160 OR diastolic>100 THEN 1 ELSE 0 END) as critical
        FROM records WHERE patient_id=? AND recorded_at>=datetime('now','-{$days} days') AND systolic IS NOT NULL
    ");
    $bp->execute([$pid]);
    $bpData = $bp->fetch();

    // Adherence — только прошедшие отметки
    $adh = db()->prepare("
        SELECT SUM(CASE WHEN status='taken'  THEN 1 ELSE 0 END) as taken,
               SUM(CASE WHEN status='missed' THEN 1 ELSE 0 END) as missed,
               SUM(CASE WHEN status='pending' AND scheduled_at<=datetime('now') THEN 1 ELSE 0 END) as overdue
        FROM intakes WHERE patient_id=? AND scheduled_at>=datetime('now','-{$days} days') AND scheduled_at<=datetime('now')
    ");
    $adh->execute([$pid]);
    $adhData = $adh->fetch();
    $total   = (int)$adhData['taken'] + (int)$adhData['missed'] + (int)$adhData['overdue'];
    $pct     = $total > 0 ? round((int)$adhData['taken'] / $total * 100) : null;

    // Trend
    $trend = db()->prepare("
        SELECT date(recorded_at) as date, ROUND(AVG(systolic)) as sys, ROUND(AVG(diastolic)) as dia
        FROM records WHERE patient_id=? AND recorded_at>=datetime('now','-{$days} days') AND systolic IS NOT NULL
        GROUP BY date(recorded_at) ORDER BY date ASC
    ");
    $trend->execute([$pid]);

    // Complaints
    $notes = db()->prepare("
        SELECT note, recorded_at FROM records
        WHERE patient_id=? AND note!='' AND note IS NOT NULL
          AND recorded_at>=datetime('now','-{$days} days')
        ORDER BY recorded_at DESC LIMIT 10
    ");
    $notes->execute([$pid]);

    // Comments
    $comms = db()->prepare("
        SELECT c.*, u.full_name as author_name, u.role as author_role
        FROM comments c JOIN users u ON c.author_id=u.id
        WHERE c.patient_id=? ORDER BY c.created_at DESC LIMIT 20
    ");
    $comms->execute([$pid]);

    // Patient profile
    $prof = db()->prepare("SELECT full_name, birth_date, normal_systolic, normal_diastolic FROM patients WHERE id=?");
    $prof->execute([$pid]);

    // Расшифровываем зашифрованные поле note в жалобах пациента
    $complaints = $notes->fetchAll();
    foreach ($complaints as &$c) {
        $c['note'] = decryptField($c['note'] ?? '');
    }
    unset($c);

    die_json([
        'bp'         => $bpData,
        'adherence'  => array_merge($adhData, ['total'=>$total, 'pct'=>$pct]),
        'trend'      => $trend->fetchAll(),
        'complaints' => $complaints,
        'comments'   => $comms->fetchAll(),
        'patient'    => $prof->fetch(),
    ]);
}


// ============================================================
// HISTORY с фильтрами (поиск по датам)
// ============================================================

if ($do === 'get_history') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') log_action('READ', 'records', $pid, $uid);
    $from   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to     = $_GET['to']   ?? date('Y-m-d');
    $filter = $_GET['filter'] ?? 'all'; // all | critical | has_note

    $sql = "SELECT r.*, u.full_name as author FROM records r
            LEFT JOIN users u ON r.created_by=u.id
            WHERE r.patient_id=?
              AND date(r.recorded_at) >= ?
              AND date(r.recorded_at) <= ?";
    $params = [$pid, $from, $to];

    if ($filter === 'critical') {
        $sql .= " AND (r.systolic > 160 OR r.diastolic > 100)";
    } elseif ($filter === 'has_note') {
        $sql .= " AND r.note != '' AND r.note IS NOT NULL";
    }
    $sql .= " ORDER BY r.recorded_at DESC";

    $s = db()->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['note'] = decryptField($r['note'] ?? '');
        $s = (int)$r['systolic']; $d = (int)$r['diastolic']; $p = (int)$r['pulse'];
        $r['crit_high']  = ($s > 160 || $d > 100);
        $r['warn_high']  = !$r['crit_high'] && ($s > 135 || $d > 85);
        $r['crit_low']   = ($s > 0 && $s < 80) || ($d > 0 && $d < 50);
        $r['warn_low']   = !$r['crit_low'] && (($s > 0 && $s <= 95) || ($d > 0 && $d <= 60));
        $r['crit_pulse'] = ($p > 0 && ($p < 40 || $p > 130));
        $r['warn_pulse'] = !$r['crit_pulse'] && ($p > 0 && ($p < 60 || $p > 100));
        $r['is_critical'] = $r['crit_high'] || $r['crit_low'] || $r['crit_pulse'];
        $r['is_warning']  = !$r['is_critical'] && ($r['warn_high'] || $r['warn_low'] || $r['warn_pulse']);
    }
    die_json($rows);
}

// ============================================================
// ADHERENCE по неделям
// ============================================================

if ($do === 'adherence_weeks') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    $weeks = (int)($_GET['weeks'] ?? 8);

    $rows = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $wStart = date('Y-m-d', strtotime("-$i weeks monday this week"));
        $wEnd   = date('Y-m-d', strtotime("-$i weeks sunday this week"));
        // Не выходим за пределы сегодня
        $wEnd = min($wEnd, date('Y-m-d'));

        $s = db()->prepare("
            SELECT SUM(CASE WHEN status='taken' THEN 1 ELSE 0 END) as taken,
                   COUNT(CASE WHEN scheduled_at <= datetime('now') THEN 1 END) as total
            FROM intakes
            WHERE patient_id=? AND date(scheduled_at) BETWEEN ? AND ?
              AND scheduled_at <= datetime('now')
        ");
        $s->execute([$pid, $wStart, $wEnd]);
        $w = $s->fetch();
        $pct = ($w['total'] > 0) ? round($w['taken'] / $w['total'] * 100) : null;
        $rows[] = [
            'week_start' => $wStart,
            'week_end'   => $wEnd,
            'label'      => date('d.m', strtotime($wStart)),
            'taken'      => (int)$w['taken'],
            'total'      => (int)$w['total'],
            'pct'        => $pct,
        ];
    }
    die_json($rows);
}

// ============================================================
// PROFILE — получить и обновить
// ============================================================

if ($do === 'get_profile') {
    $u = db()->prepare("SELECT id, role, full_name, email, phone FROM users WHERE id=?");
    $u->execute([$uid]);
    $user = $u->fetch();

    $extra = null;
    if ($role === 'patient') {
        $p = db()->prepare("SELECT id, birth_date, normal_systolic, normal_diastolic FROM patients WHERE user_id=?");
        $p->execute([$uid]);
        $extra = $p->fetch();
    }
    die_json(['user' => $user, 'patient' => $extra]);
}

if ($do === 'update_profile') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $name     = trim($b['full_name'] ?? '');
    $phone    = trim($b['phone'] ?? '');
    $password = $b['password'] ?? '';

    if ($name) {
        db()->prepare("UPDATE users SET full_name=?, phone=? WHERE id=?")
            ->execute([$name, $phone, $uid]);
    }

    // Смена пароля
    if ($password) {
        if (strlen($password) < 8) die_json(['error'=>'Пароль не менее 8 символов'], 400);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $uid]);
    }

    // Для пациента — обновляем норму давления и дату рождения
    if ($role === 'patient') {
        $birth   = $b['birth_date']        ?? null;
        $normSys = (int)($b['normal_systolic']  ?? 120);
        $normDia = (int)($b['normal_diastolic'] ?? 80);
        db()->prepare("UPDATE patients SET birth_date=?, normal_systolic=?, normal_diastolic=? WHERE user_id=?")
            ->execute([$birth ?: null, $normSys, $normDia, $uid]);
    }

    // Обновляем токен с новым именем
    $newToken = jwt_make(['uid'=>$uid, 'role'=>$role, 'name'=>$name ?: $me['name'], 'pid'=>$me['pid'] ?? null]);
    log_action('UPDATE', 'users', $uid, $uid);
    die_json(['success'=>true, 'token'=>$newToken, 'name'=>$name]);
}


// ============================================================
// ANALYTICS — линейная регрессия, паттерны, риск-скор
// ============================================================
if ($do === 'analytics') {
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    if ($role !== 'patient') log_action('READ', 'stats', $pid, $uid);
    $days = (int)($_GET['days'] ?? 30);

    // Все записи за период
    $s = db()->prepare("
        SELECT recorded_at, systolic, diastolic, pulse,
               strftime('%H', recorded_at) as hour,
               strftime('%w', recorded_at) as weekday
        FROM records WHERE patient_id=? AND systolic IS NOT NULL
          AND recorded_at >= datetime('now','-{$days} days')
        ORDER BY recorded_at ASC
    ");
    $s->execute([$pid]);
    $rows = $s->fetchAll();

    // --- Линейная регрессия (least squares) ---
    $n = count($rows);
    $regression = null;
    if ($n >= 3) {
        $x = range(0, $n-1);
        $ySys = array_column($rows, 'systolic');
        $yDia = array_column($rows, 'diastolic');

        $calcReg = function($x, $y) {
            $n = count($x);
            $sumX = array_sum($x); $sumY = array_sum($y);
            $sumXY = 0; $sumX2 = 0;
            for ($i=0;$i<$n;$i++) { $sumXY += $x[$i]*$y[$i]; $sumX2 += $x[$i]*$x[$i]; }
            $denom = $n*$sumX2 - $sumX*$sumX;
            if ($denom == 0) return ['slope'=>0,'intercept'=>$sumY/$n];
            $slope = ($n*$sumXY - $sumX*$sumY) / $denom;
            $intercept = ($sumY - $slope*$sumX) / $n;
            return ['slope'=>round($slope,3), 'intercept'=>round($intercept,2)];
        };

        $regSys = $calcReg($x, $ySys);
        $regDia = $calcReg($x, $yDia);

        // Предсказание на 7 дней вперёд (7 точек)
        $predictions = [];
        for ($i = 1; $i <= 7; $i++) {
            $xNext = $n - 1 + $i;
            $predictions[] = [
                'day'      => $i,
                'date'     => date('Y-m-d', strtotime("+$i days")),
                'sys_pred' => round($regSys['slope'] * $xNext + $regSys['intercept']),
                'dia_pred' => round($regDia['slope'] * $xNext + $regDia['intercept']),
            ];
        }
        $trend = $regSys['slope'] > 0.3 ? 'rising' : ($regSys['slope'] < -0.3 ? 'falling' : 'stable');
        $regression = ['sys'=>$regSys,'dia'=>$regDia,'trend'=>$trend,'predictions'=>$predictions];
    }

    // --- Паттерны по времени суток ---
    $timeSlots = ['morning'=>[],'afternoon'=>[],'evening'=>[],'night'=>[]];
    foreach ($rows as $r) {
        $h = (int)$r['hour'];
        if ($h>=6&&$h<12) $timeSlots['morning'][] = $r['systolic'];
        elseif ($h>=12&&$h<18) $timeSlots['afternoon'][] = $r['systolic'];
        elseif ($h>=18&&$h<23) $timeSlots['evening'][] = $r['systolic'];
        else $timeSlots['night'][] = $r['systolic'];
    }
    $avgByTime = [];
    $timeLabels = ['morning'=>'Утро (6-12)','afternoon'=>'День (12-18)','evening'=>'Вечер (18-23)','night'=>'Ночь'];
    foreach ($timeSlots as $slot => $vals) {
        if (count($vals)>0) {
            $avg = round(array_sum($vals)/count($vals));
            $avgByTime[] = ['slot'=>$slot,'label'=>$timeLabels[$slot],'avg_sys'=>$avg,'count'=>count($vals)];
        }
    }
    usort($avgByTime, fn($a,$b)=>$b['avg_sys']-$a['avg_sys']);
    $peakTime = $avgByTime[0] ?? null;

    // --- Риск-скор гипертонического криза (0-100) ---
    $risk = 0; $riskFactors = [];
    if ($n > 0) {
        $recentRows = array_slice($rows, -7);
        $avgSys7 = count($recentRows) ? round(array_sum(array_column($recentRows,'systolic'))/count($recentRows)) : 0;
        $critCount = count(array_filter($rows, fn($r)=>$r['systolic']>160));
        $maxSys = max(array_column($rows,'systolic'));

        if ($avgSys7 > 155) { $risk+=30; $riskFactors[]="Среднее за 7 дней: {$avgSys7} мм рт.ст."; }
        elseif ($avgSys7 > 145) { $risk+=15; $riskFactors[]="Повышенное среднее: {$avgSys7} мм рт.ст."; }

        if ($critCount > 3) { $risk+=25; $riskFactors[]="Критических эпизодов: {$critCount}"; }
        elseif ($critCount > 0) { $risk+=10; $riskFactors[]="Были критические эпизоды: {$critCount}"; }

        if (isset($regression) && $regression['trend']==='rising') { $risk+=20; $riskFactors[]="Тренд: рост давления"; }

        if ($maxSys > 180) { $risk+=25; $riskFactors[]="Максимальное: {$maxSys} мм рт.ст."; }

        // Приверженность
        $adhS = db()->prepare("SELECT SUM(CASE WHEN status='taken' THEN 1 ELSE 0 END) as t, COUNT(*) as total FROM intakes WHERE patient_id=? AND scheduled_at<=datetime('now') AND scheduled_at>=datetime('now','-7 days')");
        $adhS->execute([$pid]);
        $adh = $adhS->fetch();
        $adhPct = ($adh['total']>0) ? round($adh['t']/$adh['total']*100) : 100;
        if ($adhPct < 50) { $risk+=20; $riskFactors[]="Низкая приверженность: {$adhPct}%"; }
        elseif ($adhPct < 75) { $risk+=10; $riskFactors[]="Приверженность: {$adhPct}%"; }

        $risk = min(100, $risk);
    }
    $riskLevel = $risk>=70?'high':($risk>=40?'medium':'low');
    $riskLabels=['high'=>'Высокий','medium'=>'Умеренный','low'=>'Низкий'];

    die_json([
        'regression'   => $regression,
        'time_patterns'=> $avgByTime,
        'peak_time'    => $peakTime,
        'risk'         => ['score'=>$risk,'level'=>$riskLevel,'label'=>$riskLabels[$riskLevel],'factors'=>$riskFactors],
        'data_points'  => $n,
    ]);
}

// ============================================================
// AUDIT LOG — просмотр журнала действий
// ============================================================
if ($do === 'get_audit') {
    if ($role !== 'doctor') die_json(['error'=>'Только для врача'], 403);
    log_action('READ', 'audit', null, $uid);
    $pid2 = $_GET['pid'] ?? null;
    $limit= (int)($_GET['limit'] ?? 50);
    $s = db()->prepare("
        SELECT a.*, u.full_name, u.role as user_role
        FROM audit_log a LEFT JOIN users u ON a.user_id=u.id
        WHERE 1=1
        ORDER BY a.ts DESC LIMIT ?
    ");
    $s->execute([$limit]);
    die_json($s->fetchAll());
}

// ============================================================
// SSE — Server-Sent Events для реал-тайм сообщений
// ============================================================
if ($do === 'poll') {
    // Long polling — надёжно работает в XAMPP без настроек
    set_time_limit(30); // даём PHP достаточно времени
    if (!$pid) die_json(['error'=>'pid обязателен'], 400);
    $lastId      = (int)($_GET['last_id']  ?? 0);
    $lastComment = (int)($_GET['last_cid'] ?? 0);

    // Ждём до 8 сек новых данных (безопасно для XAMPP)
    $start = time();
    while (time() - $start < 8) {
        // Новые события
        $es = db()->prepare("SELECT rowid as rid, * FROM events WHERE patient_id=? AND rowid>? ORDER BY created_at DESC LIMIT 5");
        $es->execute([$pid, $lastId]);
        $newEvents = $es->fetchAll();

        // Новые комментарии
        $cs = db()->prepare("SELECT rowid as rid, c.*, u.full_name as author_name, u.role as author_role FROM comments c JOIN users u ON c.author_id=u.id WHERE c.patient_id=? AND c.rowid>? ORDER BY c.created_at DESC LIMIT 5");
        $cs->execute([$pid, $lastComment]);
        $newComments = $cs->fetchAll();

        if (!empty($newEvents) || !empty($newComments)) {
            $maxEid = $lastId;
            $maxCid = $lastComment;
            foreach ($newEvents   as $e) { if ((int)$e['rid'] > $maxEid) $maxEid = (int)$e['rid']; }
            foreach ($newComments as $c) { if ((int)$c['rid'] > $maxCid) $maxCid = (int)$c['rid']; }
            die_json(['events'=>$newEvents,'comments'=>$newComments,'last_id'=>$maxEid,'last_cid'=>$maxCid]);
        }
        sleep(1);
    }
    // Timeout — нет новых данных
    die_json(['events'=>[],'comments'=>[],'last_id'=>$lastId,'last_cid'=>$lastComment]);
}

// ============================================================
// 2FA — TOTP (совместим с Google Authenticator)
// ============================================================
if ($do === 'totp_setup') {
    // Генерируем секрет и QR-код
    $secret  = strtoupper(substr(str_replace(['+','/','='], ['','',''], base64_encode(random_bytes(20))), 0, 16));
    // Используем ASCII чтобы QR-код не был слишком длинным
    $nameRaw = $me['name'] ?? 'user';
    $name    = rawurlencode(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nameRaw) ?: 'user');
    $issuer  = 'ZabotaOnline';
    // Минимальный URL без лишних параметров (SHA1+6+30 — дефолты)
    $otpUrl = "otpauth://totp/{$issuer}:{$name}?secret={$secret}&issuer={$issuer}";
    // Сохраняем секрет (pending до подтверждения)
    db()->prepare("UPDATE users SET totp_secret=?, totp_enabled=0 WHERE id=?")->execute([$secret, $uid]);
    log_action('UPDATE', 'users', $uid, $uid);
    die_json(['secret'=>$secret,'otp_url'=>$otpUrl]);
}

if ($do === 'totp_verify') {
    $b    = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = $b['code'] ?? '';
    // Читаем секрет
    $s = db()->prepare("SELECT totp_secret FROM users WHERE id=?");
    $s->execute([$uid]);
    $row = $s->fetch();
    if (!$row || !$row['totp_secret']) die_json(['error'=>'2FA не настроена'], 400);
    $valid = verifyTotp($row['totp_secret'], $code);
    if ($valid) {
        db()->prepare("UPDATE users SET totp_enabled=1 WHERE id=?")->execute([$uid]);
        log_action('UPDATE', 'users', $uid, $uid);
        die_json(['success'=>true]);
    }
    die_json(['error'=>'Неверный код. Проверьте время на устройстве.'], 400);
}

if ($do === 'totp_disable') {
    db()->prepare("UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?")->execute([$uid]);
    log_action('UPDATE', 'users', $uid, $uid);
    die_json(['success'=>true]);
}


die_json(['error'=>'Неизвестный запрос: '.$do], 404);
