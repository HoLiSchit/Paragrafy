<?php
/**
 * Paragrafy v1.6.2 - Database, Helper, Scheduled Publishing, SMTP & Full-Spec Webhook Logger Core
 */
declare(strict_types=1);

define('PARAGRAFY_VERSION', '1.6.2');
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
                        $v = trim($v, " \t\n\r\0\x0B\"'");
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
            $transNewCols = [
                'change_note' => "TEXT DEFAULT ''",
                'source_hash' => "TEXT DEFAULT ''",
                'previous_content' => "TEXT DEFAULT ''",
                'scheduled_at' => "DATETIME DEFAULT NULL",
                'scheduled_title' => "TEXT DEFAULT ''",
                'scheduled_slug' => "TEXT DEFAULT ''",
                'scheduled_content' => "TEXT DEFAULT ''",
                'scheduled_note' => "TEXT DEFAULT ''"
            ];
            foreach ($transNewCols as $tc => $ttype) {
                if (!in_array($tc, $transColNames)) {
                    $pdo->exec("ALTER TABLE translations ADD COLUMN " . $tc . " " . $ttype);
                }
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                event_name TEXT NOT NULL,
                url TEXT NOT NULL,
                status_code INTEGER DEFAULT 0,
                request_payload TEXT DEFAULT '',
                response_body TEXT DEFAULT '',
                error_message TEXT DEFAULT '',
                duration_ms INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT DEFAULT '',
                status TEXT DEFAULT 'invited',
                invite_token TEXT DEFAULT '',
                invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                activated_at DATETIME DEFAULT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER DEFAULT NULL,
                project_name TEXT DEFAULT '',
                user_name TEXT DEFAULT 'Admin',
                action TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS translation_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                lang TEXT NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                content TEXT NOT NULL,
                change_note TEXT DEFAULT '',
                status TEXT DEFAULT 'published',
                user_name TEXT DEFAULT 'Admin',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
            );
        ");
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
            scheduled_at DATETIME DEFAULT NULL,
            scheduled_title TEXT DEFAULT '',
            scheduled_slug TEXT DEFAULT '',
            scheduled_content TEXT DEFAULT '',
            scheduled_note TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
            UNIQUE(document_id, lang)
        );

        CREATE TABLE IF NOT EXISTS webhook_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            event_name TEXT NOT NULL,
            url TEXT NOT NULL,
            status_code INTEGER DEFAULT 0,
            request_payload TEXT DEFAULT '',
            response_body TEXT DEFAULT '',
            error_message TEXT DEFAULT '',
            duration_ms INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT DEFAULT '',
            status TEXT DEFAULT 'invited',
            invite_token TEXT DEFAULT '',
            invited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            activated_at DATETIME DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER DEFAULT NULL,
            project_name TEXT DEFAULT '',
            user_name TEXT DEFAULT 'Admin',
            action TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS translation_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            document_id INTEGER NOT NULL,
            lang TEXT NOT NULL,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            content TEXT NOT NULL,
            change_note TEXT DEFAULT '',
            status TEXT DEFAULT 'published',
            user_name TEXT DEFAULT 'Admin',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
        );
    ");
}

function check_and_publish_scheduled(PDO $pdo, ?array $project = null): array {
    $publishedCount = 0;
    try {
        $nowStr = date('Y-m-d H:i:s');
        $sql = "
            SELECT t.id, t.document_id, t.lang, t.title, t.slug, t.content, t.scheduled_title, t.scheduled_slug, t.scheduled_content, t.scheduled_note, t.scheduled_at,
                   d.project_id, p.name as project_name, p.domain, p.webhook_url, p.webhook_secret
            FROM translations t
            JOIN documents d ON t.document_id = d.id
            JOIN projects p ON d.project_id = p.id
            WHERE t.scheduled_at IS NOT NULL AND t.scheduled_at <= ?
        ";
        $params = [$nowStr];
        if ($project !== null) {
            $sql .= " AND p.id = ?";
            $params[] = $project['id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $due = $stmt->fetchAll();

        foreach ($due as $row) {
            $finalTitle = $row['scheduled_title'] !== '' ? $row['scheduled_title'] : $row['title'];
            $finalSlug = $row['scheduled_slug'] !== '' ? $row['scheduled_slug'] : $row['slug'];

            $upd = $pdo->prepare("
                UPDATE translations SET
                    title = CASE WHEN scheduled_title != '' THEN scheduled_title ELSE title END,
                    slug = CASE WHEN scheduled_slug != '' THEN scheduled_slug ELSE slug END,
                    previous_content = content,
                    content = CASE WHEN scheduled_content != '' THEN scheduled_content ELSE content END,
                    change_note = scheduled_note,
                    status = 'published',
                    scheduled_at = NULL,
                    scheduled_title = '',
                    scheduled_slug = '',
                    scheduled_content = '',
                    scheduled_note = '',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $upd->execute([$row['id']]);
            $publishedCount++;

            record_translation_version(
                $pdo,
                (int)$row['document_id'],
                $row['lang'],
                $finalTitle,
                $finalSlug,
                $row['scheduled_content'] !== '' ? $row['scheduled_content'] : $row['content'],
                $row['scheduled_note'],
                'published',
                'Geplante Veröffentlichung'
            );

            dispatch_webhook($row, [
                'event_type' => 'legal_text.updated',
                'document_id' => (int)$row['document_id'],
                'slug' => $finalSlug,
                'lang' => $row['lang'],
                'title' => $finalTitle,
                'status' => 'published',
                'change_note' => $row['scheduled_note'],
                'was_scheduled' => true,
                'effective_date' => date('c'),
                'updated_at' => date('c')
            ]);
        }
    } catch (Throwable $e) {
    }
    return ['published_count' => $publishedCount];
}

function svg_icon(string $name, string $extraClass = '', int $size = 16): string {
    $icons = [
        'check' => '<path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>',
        'warning' => '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'clock' => '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
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
        'sync' => '<path d="M23 4v6h-6M1 20v-6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="19" x2="20" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
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

function dispatch_webhook(array $project, array $eventData): array {
    $url = trim($project['webhook_url'] ?? '');
    $projectId = (int)($project['id'] ?? ($project['project_id'] ?? 1));
    $projectName = $project['name'] ?? ($project['project_name'] ?? 'Paragrafy');
    $projectDomain = $project['domain'] ?? '';
    $eventName = $eventData['event_type'] ?? 'legal_text.updated';

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => 'Keine gültige Webhook-URL konfiguriert.'];
    }

    // Vollständiges Daten-Mapping gemäß Spezifikation
    $lang = $eventData['lang'] ?? ($project['primary_lang'] ?? 'de');
    $slug = $eventData['slug'] ?? '';
    
    if (!isset($eventData['url']) && !empty($slug) && !empty($projectDomain)) {
        $eventData['url'] = 'https://' . $projectDomain . '/' . $lang . '/' . $slug;
    }
    if (!isset($eventData['api_url']) && !empty($slug) && !empty($projectDomain)) {
        $eventData['api_url'] = 'https://' . $projectDomain . '/api/' . $lang . '/' . $slug;
    }
    if (!isset($eventData['status'])) {
        $eventData['status'] = ($eventName === 'legal_text.scheduled') ? 'scheduled' : 'published';
    }
    if (!isset($eventData['effective_date'])) {
        $eventData['effective_date'] = $eventData['scheduled_at'] ?? ($eventData['updated_at'] ?? date('c'));
    }
    if (!isset($eventData['was_scheduled'])) {
        $eventData['was_scheduled'] = false;
    }

    unset($eventData['event_type']);

    $payload = json_encode([
        'event' => $eventName,
        'timestamp' => date('c'),
        'project' => [
            'id' => $projectId,
            'name' => $projectName,
            'domain' => $projectDomain
        ],
        'data' => $eventData
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $secret = trim($project['webhook_secret'] ?? '');
    $signature = $secret !== '' ? hash_hmac('sha256', (string)$payload, $secret) : '';

    $headers = [
        'Content-Type: application/json',
        'User-Agent: Paragrafy-Webhook/1.6.2',
        'X-Paragrafy-Event: ' . $eventName
    ];
    if ($signature !== '') {
        $headers[] = 'X-Paragrafy-Signature: ' . $signature;
    }

    $startTime = microtime(true);
    $statusCode = 0;
    $responseBody = '';
    $errorMessage = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3
        ]);
        $responseBody = (string)curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMessage = curl_error($ch);
        curl_close($ch);
    } else {
        $errorMessage = 'cURL PHP-Erweiterung nicht verfügbar';
    }

    $durationMs = (int)round((microtime(true) - $startTime) * 1000);

    try {
        $db = get_db();
        $ins = $db->prepare("
            INSERT INTO webhook_logs (project_id, event_name, url, status_code, request_payload, response_body, error_message, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $projectId,
            $eventName,
            $url,
            $statusCode,
            $payload,
            substr($responseBody, 0, 1000),
            $errorMessage,
            $durationMs
        ]);
    } catch (Throwable $e) {
    }

    $success = ($statusCode >= 200 && $statusCode < 300);
    return [
        'success' => $success,
        'status_code' => $statusCode,
        'duration_ms' => $durationMs,
        'response' => substr($responseBody, 0, 500),
        'error' => $errorMessage ?: ($success ? '' : "HTTP-Status $statusCode erhalten")
    ];
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

function lang_meta(string $code): array {
    $map = [
        'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
        'en' => ['flag' => '🇬🇧', 'label' => 'English'],
        'es' => ['flag' => '🇪🇸', 'label' => 'Español'],
        'fr' => ['flag' => '🇫🇷', 'label' => 'Français'],
        'it' => ['flag' => '🇮🇹', 'label' => 'Italiano'],
        'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
    ];
    return $map[$code] ?? ['flag' => '', 'label' => strtoupper($code)];
}

function log_audit(?int $projectId, string $projectName, string $action): void {
    try {
        $db = get_db();
        $userName = $_SESSION['paragrafy_user_name'] ?? 'Admin';
        $stmt = $db->prepare("INSERT INTO audit_log (project_id, project_name, user_name, action) VALUES (?, ?, ?, ?)");
        $stmt->execute([$projectId, $projectName, $userName, $action]);
    } catch (Throwable $e) {
    }
}

function record_translation_version(PDO $db, int $documentId, string $lang, string $title, string $slug, string $content, string $changeNote, string $status, ?string $userName = null): void {
    try {
        $stmt = $db->prepare("INSERT INTO translation_versions (document_id, lang, title, slug, content, change_note, status, user_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$documentId, $lang, $title, $slug, $content, $changeNote, $status, $userName ?? ($_SESSION['paragrafy_user_name'] ?? 'Admin')]);
    } catch (Throwable $e) {
    }
}

/**
 * Shared visual theme (fonts, base tokens, admin shell) for the redesigned UI.
 * $accent is the project's brand_color (hex) and drives primary buttons/links.
 */
function theme_head_tags(): string {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">';
}

function theme_base_css(string $accent = '#e11d48'): string {
    $accent = htmlspecialchars($accent, ENT_QUOTES);
    return <<<CSS
    <style>
        :root {
            --accent: {$accent};
            --accent-bg: color-mix(in srgb, {$accent} 12%, white);
            --bg: #FAF9F6; --card: #ffffff; --border: #E8E4DC; --border-strong: #D8D2C6; --border-soft: #F1EEE7;
            --text: #201C24; --text-muted: #746E78; --text-faint: #9C96A0; --text-faintest: #B8B2AA;
            --green: #16814f; --green-bg: #E8F6EE; --amber: #92601a; --amber-bg: #FBF0DC; --red: #b3223a;
        }
        * { box-sizing: border-box; }
        body { font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg); color: var(--text); margin: 0; }
        h1, h2, h3, .heading-font { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -0.01em; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { opacity: 0.85; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 4px; }

        .pg-topbar { height: 56px; background: #fff; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 28px; flex-shrink: 0; }
        .pg-crumb { font-size: 13.5px; color: var(--text-faint); }
        .pg-crumb strong { color: var(--text); font-weight: 600; }

        .pg-shell { display: flex; min-height: 100vh; }
        .pg-sidebar { width: 236px; flex-shrink: 0; background: #fff; border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 20px 14px; box-sizing: border-box; position: sticky; top: 0; height: 100vh; }
        .pg-logo-row { display: flex; align-items: center; gap: 9px; padding: 4px 8px 18px; }
        .pg-logo-badge { width: 28px; height: 28px; border-radius: 7px; overflow: hidden; flex-shrink: 0; }
        .pg-logo-badge img { width: 100%; height: 100%; display: block; }
        .pg-logo-text { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 16.5px; letter-spacing: -0.01em; color: var(--text); }
        .pg-proj-select { width: 100%; box-sizing: border-box; border: 1px solid var(--border); background: var(--bg); border-radius: 8px; padding: 8px 10px; font-size: 13px; font-weight: 600; color: var(--text); font-family: 'IBM Plex Sans', sans-serif; cursor: pointer; margin-bottom: 16px; }
        .pg-nav { display: flex; flex-direction: column; gap: 2px; padding: 0 4px; }
        .pg-nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; cursor: pointer; font-size: 13.5px; font-weight: 600; color: var(--text-muted); }
        .pg-nav a:hover { background: var(--bg); opacity: 1; }
        .pg-nav a.active { background: var(--accent-bg); color: var(--accent); }
        .pg-nav-dot { width: 13px; height: 13px; border-radius: 50%; border: 2px solid currentColor; box-sizing: border-box; flex-shrink: 0; opacity: .85; }
        .pg-nav-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 2px; width: 13px; height: 13px; flex-shrink: 0; }
        .pg-nav-grid span { background: currentColor; border-radius: 2px; opacity: .85; }
        .pg-sidebar-spacer { flex: 1; }
        .pg-viewer-link { display: flex; align-items: center; justify-content: space-between; padding: 9px 10px; border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 10px; text-decoration: none; }
        .pg-viewer-link:hover { background: var(--bg); opacity: 1; }
        .pg-settings-link { margin-bottom: 0; }
        .pg-settings-link.active { background: var(--accent-bg); color: var(--accent); }
        .pg-user-row { display: flex; align-items: center; gap: 9px; padding: 6px 8px; }
        .pg-user-avatar { width: 26px; height: 26px; border-radius: 50%; background: #2b2732; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
        .pg-user-name { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pg-logout { color: var(--text-faint); font-size: 12px; }

        .pg-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .pg-content { padding: 32px 32px 80px; }
        .pg-footer-note { padding: 14px 32px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text-faintest); line-height: 1.5; }

        .pg-card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; margin-bottom: 18px; }
        .pg-card-pad { padding: 22px; }
        .pg-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 5px; }
        .pg-card .pg-card-sub { font-size: 12.5px; color: var(--text-muted); margin: 0; }

        .pg-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
        @media (max-width: 980px) { .pg-kpi-grid { grid-template-columns: 1fr 1fr; } }
        .pg-kpi { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
        .pg-kpi-label { font-size: 11px; font-weight: 700; letter-spacing: .06em; color: var(--text-faint); text-transform: uppercase; margin-bottom: 10px; }
        .pg-kpi-val { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 26px; font-weight: 800; }
        .pg-kpi-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        table.pg-table { width: 100%; border-collapse: collapse; }
        .pg-table th { text-align: left; padding: 9px 22px; font-size: 10.5px; font-weight: 700; letter-spacing: .05em; color: var(--text-faint); text-transform: uppercase; background: var(--bg); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
        .pg-table td { padding: 13px 22px; border-bottom: 1px solid var(--border-soft); font-size: 13.5px; vertical-align: middle; }
        .pg-table tr:hover td { background: var(--bg); }

        .pg-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        .pg-pill-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .pg-pill-green { color: var(--green); background: var(--green-bg); }
        .pg-pill-green .pg-pill-dot { background: #2fa06a; }
        .pg-pill-amber { color: var(--amber); background: var(--amber-bg); }
        .pg-pill-amber .pg-pill-dot { background: #d9a441; }
        .pg-pill-red { color: var(--red); background: #FBE7EA; }
        .pg-pill-red .pg-pill-dot { background: #d9536b; }
        .pg-pill-muted { color: var(--text-faint); background: var(--border-soft); font-weight: 500; }
        .pg-req-label { font-size: 12px; font-weight: 700; color: var(--accent); }
        .pg-opt-label { font-size: 12px; font-weight: 500; color: var(--text-faint); }

        .pg-icon-btn { border: 1px solid var(--border); background: #fff; border-radius: 7px; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted); }
        .pg-icon-btn:hover { border-color: var(--border-strong); color: var(--text); }
        .pg-icon-btn.danger:hover { border-color: #d99; color: var(--red); }
        .pg-copy-btn { border: none; background: transparent; padding: 2px; cursor: pointer; color: var(--text-faint); display: inline-flex; align-items: center; }
        .pg-copy-btn:hover { color: var(--text-muted); }

        input[type=text], input[type=password], input[type=email], input[type=number], input[type=datetime-local], textarea, select {
            font-family: 'IBM Plex Sans', sans-serif; border: 1px solid var(--border-strong); border-radius: 8px; padding: 9px 12px; font-size: 13px; background: #fff; color: var(--text);
        }
        label.pg-label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; margin-top: 14px; }
        .pg-hint { font-size: 11.5px; color: var(--text-faint); margin-top: 6px; }

        .pg-btn { border: none; border-radius: 8px; padding: 10px 18px; background: var(--accent); color: #fff; font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: 'IBM Plex Sans', sans-serif; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn:hover { filter: brightness(0.92); }
        .pg-btn-secondary { border: 1px solid var(--border-strong); background: #fff; border-radius: 8px; padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer; color: var(--text); display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn-secondary:hover { border-color: var(--text-faint); }
        .pg-btn-dark { border: none; background: #17141b; color: #fff; border-radius: 7px; padding: 6px 10px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .pg-btn-dark:hover { background: #2b2732; }
        .pg-btn-danger { border: none; background: #7f1d1d; color: #fecaca; border-radius: 8px; padding: 9px 15px; font-size: 13px; font-weight: 700; cursor: pointer; }
        .pg-btn-danger:hover { background: #991b1b; }

        .pg-alert { border-radius: 10px; padding: 13px 16px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 10px; font-size: 13px; }
        .pg-alert-amber { background: #FCF3E1; border: 1px solid #EAD2A0; color: #6b4a13; }
        .pg-alert-red { background: #FBE9EB; border: 1px solid #E9B7BE; color: #7a2431; }
        .pg-alert ul { margin: 6px 0 0 18px; padding: 0; }

        .pg-modal-backdrop { position: fixed; inset: 0; background: rgba(20,16,22,.5); display: none; align-items: center; justify-content: center; z-index: 300; padding: 1rem; }
        .pg-modal-backdrop.show { display: flex; }
        .pg-modal { background: #fff; border-radius: 16px; padding: 32px; width: 480px; max-width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.25); box-sizing: border-box; }

        .pg-toast { position: fixed; bottom: 2rem; right: 2rem; background: #17141b; color: #fff; padding: 0.85rem 1.5rem; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 99999; font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; transform: translateY(100px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .pg-toast.show { transform: translateY(0); opacity: 1; }
    </style>
    CSS;
}

function render_sidebar(string $active, array $project, array $projects): string {
    $items = [
        'dashboard' => ['/admin', 'Dashboard', 'grid'],
        'users' => ['/admin/users', 'Benutzer', 'users'],
        'audit' => ['/admin/audit?project_id=' . $project['id'], 'Protokoll', 'clock'],
    ];
    $currentUserName = $_SESSION['paragrafy_user_name'] ?? 'Admin';
    $initials = '';
    foreach (preg_split('/\s+/', trim($currentUserName)) as $part) {
        if ($part !== '') { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
        if (mb_strlen($initials) >= 2) break;
    }
    if ($initials === '') { $initials = 'A'; }
    ob_start();
    ?>
    <aside class="pg-sidebar">
        <div class="pg-logo-row">
            <div class="pg-logo-badge"><img src="/paragrafy.svg" alt="Paragrafy"></div>
            <span class="pg-logo-text">Paragrafy</span>
        </div>

        <select class="pg-proj-select" onchange="location.href='/admin?project_id=' + this.value">
            <?php foreach ($projects as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === (int)$project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <nav class="pg-nav">
            <?php foreach ($items as $key => [$href, $label, $icon]): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="<?= $key === $active ? 'active' : '' ?>">
                    <?php if ($icon === 'grid'): ?>
                        <span class="pg-nav-grid"><span></span><span></span><span></span><span></span></span>
                    <?php elseif ($icon === 'users'): ?>
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><circle cx="6" cy="5.2" r="2.2"/><path d="M1.6 13c.5-2.6 2.4-4 4.4-4s3.9 1.4 4.4 4"/><circle cx="11.3" cy="5.8" r="1.7"/><path d="M10.5 9.3c1.7.2 3 1.4 3.4 3.7"/></svg>
                    <?php elseif ($icon === 'clock'): ?>
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><circle cx="8" cy="8" r="6.3"/><path d="M8 4.6V8l2.6 1.6"/></svg>
                    <?php elseif ($icon === 'gear'): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;opacity:.85"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <?php else: ?>
                        <span class="pg-nav-dot"></span>
                    <?php endif; ?>
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="pg-sidebar-spacer"></div>

        <div class="pg-user-row">
            <div class="pg-user-avatar"><?= htmlspecialchars($initials) ?></div>
            <div style="flex:1;min-width:0">
                <div class="pg-user-name"><?= htmlspecialchars($currentUserName) ?></div>
            </div>
            <a href="/admin?logout=1" class="pg-logout" title="Abmelden">⏻</a>
        </div>

        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:13px;margin-bottom:10px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                <span style="width:6px;height:6px;border-radius:50%;background:var(--text-faint);display:inline-block"></span>
                <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em">Self-Hosted &middot; v<?= PARAGRAFY_VERSION ?></span>
            </div>
            <p style="font-size:12px;color:var(--text-faint);margin:0;line-height:1.5">Open Source &amp; unter deiner Kontrolle.</p>
        </div>

        <a href="https://<?= htmlspecialchars($project['domain']) ?>" target="_blank" class="pg-viewer-link">Öffentliche Seite ansehen<span>↗</span></a>

        <a href="/admin/settings?project_id=<?= $project['id'] ?>" class="pg-viewer-link pg-settings-link <?= $active === 'settings' ? 'active' : '' ?>">
            <span style="display:flex;align-items:center;gap:10px">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                Einstellungen
            </span>
        </a>
    </aside>
    <?php
    return ob_get_clean();
}
