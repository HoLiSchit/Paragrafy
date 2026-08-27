<?php
/**
 * Paragrafy v1.5.3 - Database, Helper & SMTP Core
 */
declare(strict_types=1);

define('PARAGRAFY_VERSION', '1.5.3');
define('PARAGRAFY_DIR', __DIR__);
define('DB_FILE', PARAGRAFY_DIR . '/paragrafy_data.sqlite');
define('CONFIG_FILE', PARAGRAFY_DIR . '/config.php');

function load_env_file(): array {
    $env = [];
    $candidates = [PARAGRAFY_DIR . '/.env.local', PARAGRAFY_DIR . '/.env'];
    foreach ($candidates as $file) {
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    if (str_contains($line, '=')) {
                        [$k, $v] = explode('=', $line, 2);
                        $k = trim($k);
                        $v = trim($v, " \t\n\r\0\x0B\"\'");
                        $env[$k] = $v;
                    }
                }
            }
            break;
        }
    }
    return $env;
}

function is_installed(): bool {
    return file_exists(CONFIG_FILE) && file_exists(DB_FILE);
}

function get_config(): array {
    if (!file_exists(CONFIG_FILE)) {
        return [];
    }
    return require CONFIG_FILE;
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        ensure_schema_migrations($pdo);
    }
    return $pdo;
}

function ensure_schema_migrations(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='projects'");
        if ($stmt && $stmt->fetch()) {
            $cols = $pdo->query("PRAGMA table_info(projects)")->fetchAll();
            $colNames = array_column($cols, 'name');
            $newCols = [
                'deepl_api_key' => "TEXT DEFAULT ''",
                'logo_url' => "TEXT DEFAULT ''",
                'cookie_banner_enabled' => "INTEGER DEFAULT 0",
                'cookie_banner_text' => "TEXT DEFAULT ''",
                'webhook_url' => "TEXT DEFAULT ''",
                'webhook_secret' => "TEXT DEFAULT ''",
                'audit_interval_months' => "INTEGER DEFAULT 12",
                'smtp_host' => "TEXT DEFAULT ''",
                'smtp_port' => "INTEGER DEFAULT 587",
                'smtp_user' => "TEXT DEFAULT ''",
                'smtp_pass' => "TEXT DEFAULT ''",
                'smtp_secure' => "TEXT DEFAULT 'tls'",
                'smtp_from' => "TEXT DEFAULT ''",
                'audit_email_recipient' => "TEXT DEFAULT ''"
            ];
            foreach ($newCols as $c => $type) {
                if (!in_array($c, $colNames)) {
                    $pdo->exec("ALTER TABLE projects ADD COLUMN " . $c . " " . $type);
                }
            }
        }

        $stmtTrans = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='translations'");
        if ($stmtTrans && $stmtTrans->fetch()) {
            $colsTrans = $pdo->query("PRAGMA table_info(translations)")->fetchAll();
            $transColNames = array_column($colsTrans, 'name');
            if (!in_array('change_note', $transColNames)) {
                $pdo->exec("ALTER TABLE translations ADD COLUMN change_note TEXT DEFAULT ''");
            }
            if (!in_array('source_hash', $transColNames)) {
                $pdo->exec("ALTER TABLE translations ADD COLUMN source_hash TEXT DEFAULT ''");
            }
            if (!in_array('previous_content', $transColNames)) {
                $pdo->exec("ALTER TABLE translations ADD COLUMN previous_content TEXT DEFAULT ''");
            }
        }
    } catch (Throwable $e) {
    }
}

function init_database_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            primary_lang TEXT DEFAULT 'de',
            active_languages TEXT DEFAULT 'de,en',
            brand_color TEXT DEFAULT '#e11d48',
            logo_url TEXT DEFAULT '',
            deepl_api_key TEXT DEFAULT '',
            webhook_url TEXT DEFAULT '',
            webhook_secret TEXT DEFAULT '',
            audit_interval_months INTEGER DEFAULT 12,
            smtp_host TEXT DEFAULT '',
            smtp_port INTEGER DEFAULT 587,
            smtp_user TEXT DEFAULT '',
            smtp_pass TEXT DEFAULT '',
            smtp_secure TEXT DEFAULT 'tls',
            smtp_from TEXT DEFAULT '',
            audit_email_recipient TEXT DEFAULT '',
            cookie_banner_enabled INTEGER DEFAULT 0,
            cookie_banner_text TEXT DEFAULT '',
            company_name TEXT DEFAULT '',
            address TEXT DEFAULT '',
            email TEXT DEFAULT '',
            phone TEXT DEFAULT '',
            representative TEXT DEFAULT '',
            register_info TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS doc_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            description TEXT DEFAULT '',
            is_required INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            doc_type_id INTEGER NOT NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (doc_type_id) REFERENCES doc_types(id) ON DELETE CASCADE,
            UNIQUE(project_id, doc_type_id)
        );

        CREATE TABLE IF NOT EXISTS translations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            lang TEXT NOT NULL,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            content TEXT NOT NULL,
            previous_content TEXT DEFAULT '',
            status TEXT DEFAULT 'draft',
            change_note TEXT DEFAULT '',
            source_hash TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            UNIQUE(document_id, lang)
        );
    ");
}

function svg_icon(string $name, string $extraClass = '', int $size = 16): string {
    $icons = [
        'check' => '<path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>',
        'warning' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'clock' => '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'lightning' => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>',
        'disk' => '<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM17 21v-8H7v8M7 3v5h8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'folder' => '<path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'link' => '<path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'external' => '<path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6m4-3h6v6m-11 5L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2"/><path d="M22 6l-10 7L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'print' => '<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'edit' => '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'search' => '<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'sync' => '<path d="M23 4v6h-6M1 20v-6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
    ];
    $path = $icons[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" class="' . $extraClass . '" style="vertical-align:middle; display:inline-block;">' . $path . '</svg>';
}

function replace_placeholders(string $content, array $project): string {
    $map = [
        '{{company_name}}' => htmlspecialchars($project['company_name'] ?? ''),
        '{{address}}' => nl2br(htmlspecialchars($project['address'] ?? '')),
        '{{email}}' => htmlspecialchars($project['email'] ?? ''),
        '{{phone}}' => htmlspecialchars($project['phone'] ?? ''),
        '{{representative}}' => htmlspecialchars($project['representative'] ?? ''),
        '{{register_info}}' => htmlspecialchars($project['register_info'] ?? ''),
        '{{year}}' => date('Y'),
    ];
    return str_replace(array_keys($map), array_values($map), $content);
}

function check_unfilled_placeholders(string $content, array $project): array {
    $tokens = ['{{company_name}}', '{{address}}', '{{email}}', '{{phone}}', '{{representative}}', '{{register_info}}'];
    $keys = [
        '{{company_name}}' => 'company_name',
        '{{address}}' => 'address',
        '{{email}}' => 'email',
        '{{phone}}' => 'phone',
        '{{representative}}' => 'representative',
        '{{register_info}}' => 'register_info',
    ];
    $unfilled = [];
    foreach ($tokens as $t) {
        if (str_contains($content, $t)) {
            $prop = $keys[$t];
            if (empty(trim($project[$prop] ?? ''))) {
                $unfilled[] = $t;
            }
        }
    }
    return array_unique($unfilled);
}

function send_smtp_mail(array $project, string $to, string $subject, string $bodyHtml): array {
    $host = trim($project['smtp_host'] ?? '');
    $port = (int)($project['smtp_port'] ?? 587);
    $user = trim($project['smtp_user'] ?? '');
    $pass = trim($project['smtp_pass'] ?? '');
    $secure = strtolower(trim($project['smtp_secure'] ?? 'tls'));
    $from = trim($project['smtp_from'] ?? '') ?: ($project['email'] ?? 'noreply@' . $project['domain']);

    if (empty($host)) {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . $from;
        $sent = @mail($to, $subject, $bodyHtml, $headers);
        return $sent ? ['success' => true] : ['success' => false, 'error' => 'mail() Funktion fehlgeschlagen. Bitte SMTP-Server konfigurieren.'];
    }

    $socketHost = ($secure === 'ssl') ? 'ssl://' . $host : $host;
    $socket = @fsockopen($socketHost, $port, $errno, $errstr, 15);
    if (!$socket) {
        return ['success' => false, 'error' => "Verbindung zu $host:$port fehlgeschlagen: $errstr ($errno)"];
    }

    $read = function() use ($socket) {
        $res = '';
        while ($str = fgets($socket, 515)) {
            $res .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $res;
    };

    $write = function(string $cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $read();
    $write("EHLO " . gethostname());
    $read();

    if ($secure === 'tls') {
        $write("STARTTLS");
        $tlsRes = $read();
        if (!str_starts_with($tlsRes, '220')) {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS fehlgeschlagen: $tlsRes"];
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write("EHLO " . gethostname());
        $read();
    }

    if (!empty($user) && !empty($pass)) {
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $authRes = $read();
        if (!str_starts_with($authRes, '235')) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP Authentifizierung fehlgeschlagen: $authRes"];
        }
    }

    $write("MAIL FROM: <$from>");
    $read();
    $write("RCPT TO: <$to>");
    $rcptRes = $read();
    if (!str_starts_with($rcptRes, '250')) {
        fclose($socket);
        return ['success' => false, 'error' => "Empfänger abgelehnt: $rcptRes"];
    }

    $write("DATA");
    $read();

    $headers = [
        "From: " . $project['name'] . " <$from>",
        "To: <$to>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Date: " . date('r')
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $bodyHtml . "\r\n.";
    $write($emailData);
    $dataRes = $read();
    $write("QUIT");
    fclose($socket);

    if (str_starts_with($dataRes, '250')) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => "E-Mail Senden fehlgeschlagen: $dataRes"];
}

function dispatch_webhook(array $project, array $eventData): void {
    $url = trim($project['webhook_url'] ?? '');
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return;
    }

    $payload = json_encode([
        'event' => 'legal_text.updated',
        'timestamp' => date('c'),
        'project' => [
            'id' => $project['id'],
            'name' => $project['name'],
            'domain' => $project['domain']
        ],
        'data' => $eventData
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $secret = trim($project['webhook_secret'] ?? '');
    $signature = $secret !== '' ? hash_hmac('sha256', (string)$payload, $secret) : '';

    $headers = [
        'Content-Type: application/json',
        'User-Agent: Paragrafy-Webhook/1.5.3',
        'X-Paragrafy-Event: legal_text.updated'
    ];
    if ($signature !== '') {
        $headers[] = 'X-Paragrafy-Signature: ' . $signature;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

function translate_with_deepl(string $text, string $sourceLang, string $targetLang, string $apiKey): array {
    if (empty(trim($apiKey))) {
        return ['success' => false, 'error' => 'Kein DeepL API-Key hinterlegt.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL PHP-Erweiterung (php-curl) ist auf dem Server nicht aktiv.'];
    }

    $isFreeTier = str_ends_with(trim($apiKey), ':fx');
    $endpoint = $isFreeTier ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';

    $targetCode = strtoupper($targetLang);
    if ($targetCode === 'EN') $targetCode = 'EN-US';
    if ($targetCode === 'PT') $targetCode = 'PT-PT';

    $tokens = ['{{company_name}}', '{{address}}', '{{email}}', '{{phone}}', '{{representative}}', '{{register_info}}', '{{year}}'];
    $protectedText = $text;
    foreach ($tokens as $t) {
        $protectedText = str_replace($t, '<span translate="no">' . $t . '</span>', $protectedText);
    }

    $postData = http_build_query([
        'text' => $protectedText,
        'source_lang' => strtoupper($sourceLang),
        'target_lang' => $targetCode,
        'tag_handling' => 'html'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Authorization: DeepL-Auth-Key ' . trim($apiKey),
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'cURL Fehler: ' . $curlError];
    }

    $data = json_decode((string)$response, true);
    if ($httpCode !== 200 || !isset($data['translations'][0]['text'])) {
        $msg = $data['message'] ?? ("HTTP " . $httpCode . ": " . substr((string)$response, 0, 150));
        return ['success' => false, 'error' => 'DeepL API Fehler: ' . $msg];
    }

    $translated = $data['translations'][0]['text'];
    $translated = preg_replace('/<span translate="no">({{.*?}})<\/span>/i', '$1', $translated);

    return ['success' => true, 'text' => $translated];
}

function get_current_host(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return explode(':', $host)[0];
}
