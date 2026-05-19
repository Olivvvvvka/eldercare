<?php
// SMTP клиент для XAMPP — использует SSL на порту 465
class SimpleSMTP {
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $from;
    private string $fromName;

    public function __construct(array $cfg) {
        $this->host     = $cfg['host'];
        $this->port     = $cfg['port']     ?? 465;
        $this->user     = $cfg['user'];
        $this->pass     = $cfg['pass'];
        $this->from     = $cfg['from']     ?? $cfg['user'];
        $this->fromName = $cfg['fromName'] ?? 'ЗаботаОнлайн';
    }

    public function send(string $to, string $subject, string $body): bool {
        $errno = 0; $errstr = '';

        // Контекст SSL с отключённой проверкой сертификата (для XAMPP)
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $sock = stream_socket_client(
            'ssl://' . $this->host . ':' . $this->port,
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT, $ctx
        );

        if (!$sock) {
            error_log("SMTP connect failed: $errstr ($errno)");
            return false;
        }

        try {
            stream_set_timeout($sock, 15);
            $this->read($sock);
            $this->cmd($sock, "EHLO localhost");
            $this->cmd($sock, "AUTH LOGIN");
            $this->cmd($sock, base64_encode($this->user));
            $this->cmd($sock, base64_encode($this->pass));
            $this->cmd($sock, "MAIL FROM:<{$this->from}>");
            $this->cmd($sock, "RCPT TO:<{$to}>");
            $this->cmd($sock, "DATA");

            $date    = date('r');
            $subjectB64 = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $fromB64    = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';

            $msg = "Date: $date\r\n"
                 . "From: $fromB64 <{$this->from}>\r\n"
                 . "To: <$to>\r\n"
                 . "Subject: $subjectB64\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "\r\n"
                 . chunk_split(base64_encode($body))
                 . "\r\n.";

            $this->cmd($sock, $msg);
            $this->cmd($sock, "QUIT");
        } finally {
            fclose($sock);
        }
        return true;
    }

    private function cmd($sock, string $cmd): string {
        fputs($sock, $cmd . "\r\n");
        return $this->read($sock);
    }

    private function read($sock): string {
        $out = '';
        while ($line = fgets($sock, 512)) {
            $out .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $out;
    }
}
