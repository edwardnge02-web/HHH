<?php
// config/email_service.php
// Real SMTP delivery + database logging for system notifications.

function emailServiceTableExists($connection, $table_name) {
    $stmt = $connection->prepare(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table_name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return intval($row['count'] ?? 0) > 0;
}

function emailServiceTableColumns($connection, $table_name) {
    $stmt = $connection->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table_name]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($rows as $row) {
        $cols[$row['COLUMN_NAME']] = true;
    }
    return $cols;
}

function emailServiceParseEnvFile($path) {
    static $cache = [];
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }

    $values = [];
    if (!is_file($path)) {
        $cache[$path] = $values;
        return $values;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $cache[$path] = $values;
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $values[$key] = $value;
    }

    $cache[$path] = $values;
    return $values;
}

function emailServiceEnv($key, $default = '') {
    $env_value = getenv($key);
    if ($env_value !== false && trim((string) $env_value) !== '') {
        return trim((string) $env_value);
    }

    static $file_values = null;
    if ($file_values === null) {
        $file_values = emailServiceParseEnvFile(dirname(__DIR__) . '/.env');
    }

    if (isset($file_values[$key]) && trim((string) $file_values[$key]) !== '') {
        return trim((string) $file_values[$key]);
    }

    return $default;
}

function emailServiceHasSmtpSettings($settings) {
    if (!is_array($settings)) {
        return false;
    }

    $required = ['host', 'username', 'password', 'from_email'];
    foreach ($required as $field) {
        if (trim((string) ($settings[$field] ?? '')) === '') {
            return false;
        }
    }

    return intval($settings['port'] ?? 0) > 0;
}

function emailServiceEnvSmtpSettings() {
    $driver = strtolower(trim((string) emailServiceEnv('MAIL_DRIVER', 'smtp')));
    if ($driver !== '' && $driver !== 'smtp') {
        return null;
    }

    $settings = [
        'host' => emailServiceEnv('MAIL_HOST', ''),
        'port' => intval(emailServiceEnv('MAIL_PORT', '587')),
        'username' => emailServiceEnv('MAIL_USERNAME', ''),
        'password' => emailServiceEnv('MAIL_PASSWORD', ''),
        'encryption' => strtolower(emailServiceEnv('MAIL_ENCRYPTION', 'tls')),
        'from_email' => emailServiceEnv('MAIL_FROM_ADDRESS', ''),
        'from_name' => emailServiceEnv('MAIL_FROM_NAME', 'Ideas System'),
    ];

    if ($settings['port'] <= 0) {
        $settings['port'] = 587;
    }

    return emailServiceHasSmtpSettings($settings) ? $settings : null;
}

function emailServiceGetSmtpSettings($connection) {
    $env_settings = emailServiceEnvSmtpSettings();

    if (!emailServiceTableExists($connection, 'system_settings')) {
        return $env_settings;
    }

    $stmt = $connection->prepare(
        "SELECT setting_value
         FROM system_settings
         WHERE setting_key = 'smtp_settings'
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $env_settings;
    }

    $settings = json_decode($row['setting_value'] ?? '', true);
    if (!is_array($settings)) {
        return $env_settings;
    }

    $merged = [
        'host' => trim((string) ($settings['host'] ?? '')),
        'port' => intval($settings['port'] ?? 0),
        'username' => trim((string) ($settings['username'] ?? '')),
        'password' => strval($settings['password'] ?? ''),
        'encryption' => strtolower(trim((string) ($settings['encryption'] ?? ''))),
        'from_email' => trim((string) ($settings['from_email'] ?? '')),
        'from_name' => trim((string) ($settings['from_name'] ?? '')),
    ];

    if ($env_settings) {
        foreach ($env_settings as $key => $value) {
            $current = $merged[$key] ?? null;
            $is_empty = $key === 'port'
                ? intval($current) <= 0
                : trim((string) $current) === '';

            if ($is_empty) {
                $merged[$key] = $value;
            }
        }
    }

    return emailServiceHasSmtpSettings($merged) ? $merged : $env_settings;
}

function emailServiceReadResponse($socket) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }
    return $response;
}

function emailServiceExpectCode($response, $accepted_codes) {
    if (!preg_match('/^(\d{3})/m', $response, $matches)) {
        return false;
    }
    $code = intval($matches[1]);
    return in_array($code, $accepted_codes, true);
}

function emailServiceSendCommand($socket, $command, $accepted_codes = [250]) {
    fwrite($socket, $command . "\r\n");
    $response = emailServiceReadResponse($socket);
    return [emailServiceExpectCode($response, $accepted_codes), $response];
}

function emailServiceBuildPlainText($greeting, $intro, $details = [], $footer_lines = []) {
    $lines = [];

    if (trim((string) $greeting) !== '') {
        $lines[] = trim((string) $greeting);
        $lines[] = '';
    }

    if (trim((string) $intro) !== '') {
        $lines[] = trim((string) $intro);
    }

    if (!empty($details)) {
        $lines[] = '';
        foreach ($details as $label => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $lines[] = $label . ': ' . $value;
        }
    }

    if (!empty($footer_lines)) {
        $lines[] = '';
        foreach ($footer_lines as $footer_line) {
            $footer_line = trim((string) $footer_line);
            if ($footer_line === '') {
                continue;
            }
            $lines[] = $footer_line;
        }
    }

    return implode("\n", $lines);
}

function emailServiceSendSmtp($smtp, $to_email, $subject, $body_text) {
    $host = trim($smtp['host'] ?? '');
    $port = intval($smtp['port'] ?? 587);
    $username = trim($smtp['username'] ?? '');
    $password = strval($smtp['password'] ?? '');
    $encryption = strtolower(trim($smtp['encryption'] ?? 'tls'));
    $from_email = trim($smtp['from_email'] ?? $username);
    $from_name = trim($smtp['from_name'] ?? 'Ideas System');

    if ($host === '' || $username === '' || $password === '' || $from_email === '') {
        return [false, 'SMTP settings are incomplete'];
    }

    $transport_host = ($encryption === 'ssl') ? ("ssl://" . $host) : $host;
    $socket = @stream_socket_client(
        $transport_host . ":" . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [false, "SMTP connection failed: $errstr ($errno)"];
    }

    stream_set_timeout($socket, 20);
    $greeting = emailServiceReadResponse($socket);
    if (!emailServiceExpectCode($greeting, [220])) {
        fclose($socket);
        return [false, 'SMTP greeting failed: ' . trim($greeting)];
    }

    [$ok, $resp] = emailServiceSendCommand($socket, 'EHLO localhost', [250]);
    if (!$ok) {
        fclose($socket);
        return [false, 'EHLO failed: ' . trim($resp)];
    }

    if ($encryption === 'tls') {
        [$ok, $resp] = emailServiceSendCommand($socket, 'STARTTLS', [220]);
        if (!$ok) {
            fclose($socket);
            return [false, 'STARTTLS failed: ' . trim($resp)];
        }
        $crypto_ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto_ok) {
            fclose($socket);
            return [false, 'TLS negotiation failed'];
        }
        [$ok, $resp] = emailServiceSendCommand($socket, 'EHLO localhost', [250]);
        if (!$ok) {
            fclose($socket);
            return [false, 'EHLO after STARTTLS failed: ' . trim($resp)];
        }
    }

    [$ok, $resp] = emailServiceSendCommand($socket, 'AUTH LOGIN', [334]);
    if (!$ok) {
        fclose($socket);
        return [false, 'AUTH LOGIN failed: ' . trim($resp)];
    }
    [$ok, $resp] = emailServiceSendCommand($socket, base64_encode($username), [334]);
    if (!$ok) {
        fclose($socket);
        return [false, 'SMTP username rejected: ' . trim($resp)];
    }
    [$ok, $resp] = emailServiceSendCommand($socket, base64_encode($password), [235]);
    if (!$ok) {
        fclose($socket);
        return [false, 'SMTP password rejected: ' . trim($resp)];
    }

    [$ok, $resp] = emailServiceSendCommand($socket, 'MAIL FROM:<' . $from_email . '>', [250]);
    if (!$ok) {
        fclose($socket);
        return [false, 'MAIL FROM failed: ' . trim($resp)];
    }
    [$ok, $resp] = emailServiceSendCommand($socket, 'RCPT TO:<' . $to_email . '>', [250, 251]);
    if (!$ok) {
        fclose($socket);
        return [false, 'RCPT TO failed: ' . trim($resp)];
    }
    [$ok, $resp] = emailServiceSendCommand($socket, 'DATA', [354]);
    if (!$ok) {
        fclose($socket);
        return [false, 'DATA command failed: ' . trim($resp)];
    }

    $safe_subject = str_replace(["\r", "\n"], '', $subject);
    $headers = [];
    $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
    $headers[] = 'To: <' . $to_email . '>';
    $headers[] = 'Subject: ' . $safe_subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body_text . "\r\n.";
    fwrite($socket, $payload . "\r\n");
    $resp = emailServiceReadResponse($socket);
    if (!emailServiceExpectCode($resp, [250])) {
        fclose($socket);
        return [false, 'Message rejected: ' . trim($resp)];
    }

    emailServiceSendCommand($socket, 'QUIT', [221, 250]);
    fclose($socket);
    return [true, null];
}

function sendSystemEmail($connection, $notification) {
    $recipient_email = trim($notification['recipient_email'] ?? '');
    if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient_email'];
    }

    $subject = trim($notification['subject'] ?? 'Notification');
    $message = strval($notification['message'] ?? '');
    $smtp_settings = emailServiceGetSmtpSettings($connection);

    $sent_ok = false;
    $send_error = 'SMTP is not configured';
    if (is_array($smtp_settings)) {
        [$sent_ok, $send_error] = emailServiceSendSmtp($smtp_settings, $recipient_email, $subject, $message);
    }

    if (emailServiceTableExists($connection, 'email_notifications')) {
        $columns = emailServiceTableColumns($connection, 'email_notifications');
        $insert = [];
        $values = [];

        $base_map = [
            'recipient_email' => $recipient_email,
            'recipient_type' => strval($notification['recipient_type'] ?? 'Staff'),
            'notification_type' => strval($notification['notification_type'] ?? 'General'),
            'subject' => $subject,
            'message' => $message,
            'status' => $sent_ok ? 'Sent' : 'Failed',
        ];

        foreach ($base_map as $col => $val) {
            if (isset($columns[$col])) {
                $insert[] = $col;
                $values[] = $val;
            }
        }

        foreach (['idea_id', 'comment_id', 'session_id'] as $optional_col) {
            if (isset($columns[$optional_col]) && isset($notification[$optional_col])) {
                $insert[] = $optional_col;
                $values[] = $notification[$optional_col];
            }
        }

        if (isset($columns['sent_at'])) {
            $insert[] = 'sent_at';
            $values[] = date('Y-m-d H:i:s');
        }

        if (!empty($insert)) {
            $placeholders = implode(', ', array_fill(0, count($insert), '?'));
            $sql = "INSERT INTO email_notifications (" . implode(', ', $insert) . ") VALUES ($placeholders)";
            $stmt = $connection->prepare($sql);
            $stmt->execute($values);
        }
    }

    return ['ok' => $sent_ok, 'error' => $send_error];
}

?>
