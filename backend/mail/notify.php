<?php
require_once __DIR__ . '/smtp.php';

// ============================================================
// НАСТРОЙКИ EMAIL — заполни свои данные!
// ============================================================
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      465); // SSL — работает в XAMPP
define('SMTP_USER',      'kingwarr.149@gmail.com');
define('SMTP_PASS',      'glqy znyp pcle oucq');
define('SMTP_FROM_NAME', 'ЗаботаОнлайн');
define('NOTIFY_ENABLED', true);
// ============================================================

function sendEmailNotification(string $to, string $subject, string $html): bool {
    if (!NOTIFY_ENABLED) return false;
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    try {
        $mailer = new SimpleSMTP([
            'host'     => SMTP_HOST, 'port' => SMTP_PORT,
            'user'     => SMTP_USER, 'pass' => SMTP_PASS,
            'from'     => SMTP_USER, 'fromName' => SMTP_FROM_NAME,
        ]);
        return $mailer->send($to, $subject, $html);
    } catch (Exception $e) {
        error_log('Email notify error: ' . $e->getMessage());
        return false;
    }
}

// Получаем email только родственников пациента
function getRelativeEmails(string $patientId): array {
    try {
        require_once dirname(__DIR__) . '/core.php';
        $s = db()->prepare("
            SELECT u.email, u.full_name
            FROM users u JOIN access a ON a.user_id = u.id
            WHERE a.patient_id = ? AND a.is_active = 1
              AND u.role = 'relative'
              AND u.email IS NOT NULL AND u.email != ''
        ");
        $s->execute([$patientId]);
        return $s->fetchAll();
    } catch (Exception $e) { return []; }
}

// HTML-шаблон письма
function buildHtml(string $title, string $message, string $patientName, string $color): string {
    $date = date('d.m.Y H:i');
    return "<!DOCTYPE html><html lang='ru'><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif'>
<div style='max-width:560px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)'>
  <div style='background:{$color};padding:24px 32px'>
    <div style='font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:4px'>💚 ЗаботаОнлайн</div>
    <div style='color:#fff;opacity:.9;font-size:.9rem'>Система контроля здоровья</div>
  </div>
  <div style='padding:28px 32px'>
    <h2 style='margin:0 0 12px;color:#111;font-size:1.2rem'>{$title}</h2>
    <div style='background:#f8f8f8;border-left:4px solid {$color};padding:16px;border-radius:0 8px 8px 0;margin-bottom:20px;font-size:1rem;color:#333'>{$message}</div>
    <p style='color:#666;font-size:.9rem;margin:0'>Пациент: <strong>{$patientName}</strong><br>Дата: {$date}</p>
  </div>
  <div style='background:#f0f0f0;padding:16px 32px;font-size:.8rem;color:#999;text-align:center'>
    Это автоматическое уведомление от ЗаботаОнлайн. Войдите на сайт для подробностей.
  </div>
</div>
</body></html>";
}

// Отправить уведомление всем родственникам
function notifyRelatives(string $patientId, string $patientName, string $subject, string $title, string $message, string $color = '#d94f3d'): void {
    $relatives = getRelativeEmails($patientId);
    foreach ($relatives as $r) {
        $html = buildHtml($title, $message, $patientName, $color);
        sendEmailNotification($r['email'], $subject, $html);
    }
}
