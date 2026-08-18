<?php

declare(strict_types=1);

function sanitizeSmtp(array $smtp): array {
    $enc = (string) ($smtp['encryption'] ?? 'tls');
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        $enc = 'tls';
    }

    $hostRaw = trim((string) ($smtp['host'] ?? ''));
    $hostRaw = preg_replace('#^(ssl|tls|tcp)://#i', '', $hostRaw) ?? $hostRaw;
    $host = $hostRaw;
    $port = (int) ($smtp['port'] ?? 0);

    $hasSingleColon = substr_count($hostRaw, ':') === 1;
    if ($hasSingleColon && preg_match('/^(.+):(\d+)$/', $hostRaw, $m)) {
        $host = (string) ($m[1] ?? $hostRaw);
        $maybePort = (int) ($m[2] ?? 0);
        if ($maybePort > 0 && $port <= 0) {
            $port = $maybePort;
        }
    }

    $host = trim($host);

    return [
        'host' => $host,
        'port' => $port,
        'encryption' => $enc,
        'user' => trim((string) ($smtp['user'] ?? '')),
        'pass' => (string) ($smtp['pass'] ?? ''),
        'from_email' => trim((string) ($smtp['from_email'] ?? '')),
        'from_name' => trim((string) ($smtp['from_name'] ?? 'Dashboard XAMPP')),
    ];
}

function smtpConfigured(array $smtp): bool {
    return $smtp['host'] !== '' && $smtp['port'] > 0 && $smtp['user'] !== '' && $smtp['from_email'] !== '';
}

function smtpRead($fp): string {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4) break;
        if ($line[3] === ' ') break;
    }
    return $data;
}

function smtpExpect(string $resp, array $codes): bool {
    $code = (int) substr($resp, 0, 3);
    return in_array($code, $codes, true);
}

function smtpCmd($fp, string $cmd, array $expectCodes): bool {
    fwrite($fp, $cmd . "\r\n");
    $resp = smtpRead($fp);
    return smtpExpect($resp, $expectCodes);
}

function sendMailViaSmtp(array $smtp, string $to, string $subject, string $body, string &$error = ''): bool {
    $host = $smtp['host'];
    $port = (int) $smtp['port'];
    $enc = $smtp['encryption'];
    $user = $smtp['user'];
    $pass = $smtp['pass'];
    $fromEmail = $smtp['from_email'];
    $fromName = $smtp['from_name'] !== '' ? $smtp['from_name'] : 'Dashboard XAMPP';

    if ($host === '' || $port <= 0) {
        $error = 'Host o puerto SMTP invalidos.';
        return false;
    }

    // Corrige configuraciones SMTP comunes mal seleccionadas en UI.
    if ($enc === 'ssl' && $port === 587) {
        $enc = 'tls';
    } elseif ($enc === 'tls' && $port === 465) {
        $enc = 'ssl';
    }

    $transport = $enc === 'ssl' ? 'ssl://' . $host : 'tcp://' . $host;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $last = error_get_last();
        $details = trim((string) $errstr);
        if ($details === '' && is_array($last) && isset($last['message'])) {
            $details = trim((string) $last['message']);
        }
        if ($details === '') {
            $details = 'Fallo de socket sin detalle del sistema.';
        }

        $hint = ($port === 587 || $port === 465)
            ? ' Verifica cifrado/puerto: 587->TLS (STARTTLS), 465->SSL.'
            : '';

        $error = "No se pudo conectar a {$host}:{$port} ({$transport}). errno={$errno}. {$details}.{$hint}";
        return false;
    }

    stream_set_timeout($fp, 20);

    $banner = smtpRead($fp);
    if (!smtpExpect($banner, [220])) {
        fclose($fp);
        $error = 'El servidor SMTP rechazo la conexion.';
        return false;
    }

    if (!smtpCmd($fp, 'EHLO localhost', [250])) {
        fclose($fp);
        $error = 'EHLO fallo.';
        return false;
    }

    if ($enc === 'tls') {
        if (!smtpCmd($fp, 'STARTTLS', [220])) {
            fclose($fp);
            $error = 'STARTTLS no disponible.';
            return false;
        }

        $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            fclose($fp);
            $error = 'No se pudo negociar TLS.';
            return false;
        }

        if (!smtpCmd($fp, 'EHLO localhost', [250])) {
            fclose($fp);
            $error = 'EHLO post-TLS fallo.';
            return false;
        }
    }

    if ($user !== '') {
        if (!smtpCmd($fp, 'AUTH LOGIN', [334])) {
            fclose($fp);
            $error = 'AUTH LOGIN no permitido por el servidor.';
            return false;
        }
        if (!smtpCmd($fp, base64_encode($user), [334])) {
            fclose($fp);
            $error = 'Usuario SMTP rechazado.';
            return false;
        }
        if (!smtpCmd($fp, base64_encode($pass), [235])) {
            fclose($fp);
            $error = 'Password SMTP rechazado.';
            return false;
        }
    }

    if (!smtpCmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
        fclose($fp);
        $error = 'MAIL FROM rechazado.';
        return false;
    }
    if (!smtpCmd($fp, 'RCPT TO:<' . $to . '>', [250, 251])) {
        fclose($fp);
        $error = 'RCPT TO rechazado.';
        return false;
    }
    if (!smtpCmd($fp, 'DATA', [354])) {
        fclose($fp);
        $error = 'DATA rechazado.';
        return false;
    }

    $safeBody = str_replace("\n.", "\n..", $body);
    $headers =
        'From: ' . $fromName . ' <' . $fromEmail . ">\r\n" .
        'To: <' . $to . ">\r\n" .
        'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n" .
        "MIME-Version: 1.0\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n\r\n";

    fwrite($fp, $headers . $safeBody . "\r\n.\r\n");
    $dataResp = smtpRead($fp);
    if (!smtpExpect($dataResp, [250])) {
        fclose($fp);
        $error = 'El servidor no acepto el mensaje.';
        return false;
    }

    smtpCmd($fp, 'QUIT', [221]);
    fclose($fp);
    return true;
}
