<?php
/**
 * Paragrafy v1.6.1 - Admin Command Center, Multi-Project Manager, Compliance Engine & Webhook Logger
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!is_installed()) {
    header('Location: /install.php');
    exit;
}

$config = get_config();
$db = get_db();
$error = null;

$earlyRoute = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (str_starts_with($earlyRoute, '/admin/accept-invite')) {
    handle_accept_invite($db);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass = $_POST['password'] ?? '';
    $loggedIn = false;

    if ($email !== '') {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && !empty($user['password_hash']) && password_verify($pass, $user['password_hash'])) {
            $_SESSION['paragrafy_admin'] = true;
            $_SESSION['paragrafy_user_id'] = (int)$user['id'];
            $_SESSION['paragrafy_user_name'] = $user['name'];
            $_SESSION['paragrafy_user_email'] = $user['email'];
            $loggedIn = true;
        }
    } elseif (password_verify($pass, $config['admin_password_hash'] ?? '')) {
        $_SESSION['paragrafy_admin'] = true;
        $_SESSION['paragrafy_user_name'] = 'Admin';
        unset($_SESSION['paragrafy_user_id'], $_SESSION['paragrafy_user_email']);
        $loggedIn = true;
    }

    if ($loggedIn) {
        header('Location: /admin');
        exit;
    }
    $error = "Falsche Zugangsdaten.";
}

if (isset($_GET['logout'])) {
    unset($_SESSION['paragrafy_admin'], $_SESSION['paragrafy_user_id'], $_SESSION['paragrafy_user_name'], $_SESSION['paragrafy_user_email']);
    header('Location: /admin');
    exit;
}

if (empty($_SESSION['paragrafy_admin'])) {
    render_login_view($error);
    exit;
}

$projects = $db->query("SELECT * FROM projects ORDER BY name ASC")->fetchAll();
$projectId = (int)($_GET['project_id'] ?? $_SESSION['admin_project_id'] ?? ($projects[0]['id'] ?? 0));
$_SESSION['admin_project_id'] = $projectId;

$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

// 1. SQLite Datenbank-Backup herunterladen
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    if (file_exists(DB_FILE)) {
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="paragrafy_backup_' . date('Y-m-d_His') . '.sqlite"');
        header('Content-Length: ' . filesize(DB_FILE));
        readfile(DB_FILE);
        exit;
    }
}

// 2. Markdown Export herunterladen
if (isset($_GET['action']) && $_GET['action'] === 'export_markdown') {
    $stmt = $db->prepare("
        SELECT t.title, t.slug, t.lang, t.content, t.updated_at, dt.title as type_title
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE d.project_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$projectId]);
    $docs = $stmt->fetchAll();

    if (class_exists('ZipArchive')) {
        $zipFile = tempnam(sys_get_temp_dir(), 'pg_md_');
        $zip = new ZipArchive();
        $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($docs as $d) {
            $filename = $d['lang'] . '/' . $d['slug'] . '.md';
            $md = "# " . $d['title'] . "\n\n> Stand: " . $d['updated_at'] . "\n\n" . strip_tags($d['content']);
            $zip->addFromString($filename, $md);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $project['name']) . '_legal_markdown.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        @unlink($zipFile);
        exit;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $project['name']) . '_legal_export.txt"');
        foreach ($docs as $d) {
            echo "========================================\n";
            echo "DOKUMENT: " . $d['title'] . " (" . strtoupper($d['lang']) . ") - /" . $d['lang'] . "/" . $d['slug'] . "\n";
            echo "STAND: " . $d['updated_at'] . "\n";
            echo "========================================\n\n";
            echo strip_tags($d['content']) . "\n\n\n";
        }
        exit;
    }
}

// 3. Webhook Test Trigger mit detailliertem Protokoll
if (isset($_POST['action']) && $_POST['action'] === 'test_webhook') {
    header('Content-Type: application/json');
    $result = dispatch_webhook($project, [
        'test' => true,
        'message' => 'Paragrafy Webhook Test erfolgreich ausgelöst',
        'triggered_at' => date('c')
    ]);
    echo json_encode($result);
    exit;
}

// 4. SMTP Test-Mail Trigger
if (isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
    header('Content-Type: application/json');
    $recipient = trim($project['audit_email_recipient'] ?? '') ?: ($project['email'] ?? '');
    if (empty($recipient)) {
        echo json_encode(['success' => false, 'error' => 'Bitte zuerst eine Empfänger-E-Mail angeben.']);
        exit;
    }
    $html = "<h2>Paragrafy SMTP-Test</h2><p>Deine E-Mail-Serverkonfiguration für <strong>" . htmlspecialchars($project['name']) . "</strong> funktioniert einwandfrei!</p>";
    $res = send_smtp_mail($project, $recipient, "[Paragrafy] SMTP Test-E-Mail", $html);
    echo json_encode($res);
    exit;
}

// 5. Webhook Logs leeren
if (isset($_POST['action']) && $_POST['action'] === 'clear_webhook_logs') {
    $del = $db->prepare("DELETE FROM webhook_logs WHERE project_id = ?");
    $del->execute([$projectId]);
    header("Location: /admin/settings?project_id=$projectId&msg=logs_cleared");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Neues Projekt anlegen
    if ($action === 'create_project') {
        $newName = trim($_POST['new_project_name'] ?? '');
        $newDomain = trim($_POST['new_project_domain'] ?? '');
        $newLang = trim($_POST['new_primary_lang'] ?? 'de');
        $newColor = trim($_POST['new_brand_color'] ?? '#e11d48');
        if (!str_starts_with($newColor, '#')) $newColor = '#' . $newColor;

        if (!empty($newName) && !empty($newDomain)) {
            $stmt = $db->prepare("INSERT INTO projects (name, domain, brand_color, primary_lang, active_languages) VALUES (?, ?, ?, ?, 'de,en')");
            $stmt->execute([$newName, $newDomain, $newColor, $newLang]);
            $newProjectId = (int)$db->lastInsertId();

            $docTypes = $db->query("SELECT id FROM doc_types")->fetchAll();
            foreach ($docTypes as $dt) {
                $insDoc = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $insDoc->execute([$newProjectId, $dt['id']]);
            }

            log_audit($newProjectId, $newName, 'Projekt erstellt');
            header("Location: /admin?project_id=$newProjectId&msg=project_created");
            exit;
        }
    }

    // Projekt löschen
    if ($action === 'delete_project') {
        $delId = (int)$_POST['delete_project_id'];
        if (count($projects) > 1) {
            $delName = '';
            foreach ($projects as $p) { if ((int)$p['id'] === $delId) { $delName = $p['name']; break; } }
            $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$delId]);
            log_audit(null, $delName, 'Projekt gelöscht');
            header("Location: /admin?msg=project_deleted");
            exit;
        }
    }

    // AJAX / Post Toggle für Pflichtstatus
    if ($action === 'toggle_required') {
        $typeId = (int)$_POST['doc_type_id'];
        $stmt = $db->prepare("UPDATE doc_types SET is_required = CASE WHEN is_required = 1 THEN 0 ELSE 1 END WHERE id = ?");
        $stmt->execute([$typeId]);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            $stmtNew = $db->prepare("SELECT is_required FROM doc_types WHERE id = ?");
            $stmtNew->execute([$typeId]);
            echo json_encode(['success' => true, 'is_required' => (int)$stmtNew->fetchColumn()]);
            exit;
        }
        header("Location: /admin?project_id=$projectId&msg=toggled");
        exit;
    }

    if ($action === 'save_project') {
        $activeLangs = implode(',', array_filter(array_map('trim', explode(',', $_POST['active_languages'] ?? 'de,en'))));
        $brandColor = trim($_POST['brand_color'] ?? '#e11d48');
        $deeplKey = trim($_POST['deepl_api_key'] ?? '');
        $logoUrl = trim($_POST['logo_url'] ?? '');
        $webhookUrl = trim($_POST['webhook_url'] ?? '');
        $webhookSecret = trim($_POST['webhook_secret'] ?? '');
        $auditMonths = max(1, (int)($_POST['audit_interval_months'] ?? 12));
        
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = (int)($_POST['smtp_port'] ?? 587);
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $smtpPass = trim($_POST['smtp_pass'] ?? '');
        $smtpSecure = trim($_POST['smtp_secure'] ?? 'tls');
        $smtpFrom = trim($_POST['smtp_from'] ?? '');
        $auditRecipient = trim($_POST['audit_email_recipient'] ?? '');

        $cookieBanner = !empty($_POST['cookie_banner_enabled']) ? 1 : 0;
        if (!str_starts_with($brandColor, '#')) {
            $brandColor = '#' . $brandColor;
        }

        $stmt = $db->prepare("
            UPDATE projects SET 
                name=?, domain=?, brand_color=?, primary_lang=?, active_languages=?,
                deepl_api_key=?, logo_url=?, webhook_url=?, webhook_secret=?, audit_interval_months=?,
                smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass=?, smtp_secure=?, smtp_from=?, audit_email_recipient=?,
                cookie_banner_enabled=?,
                company_name=?, address=?, email=?, phone=?, representative=?, register_info=?
            WHERE id=?
        ");
        $stmt->execute([
            $_POST['name'], $_POST['domain'], $brandColor, $_POST['primary_lang'], $activeLangs,
            $deeplKey, $logoUrl, $webhookUrl, $webhookSecret, $auditMonths,
            $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure, $smtpFrom, $auditRecipient,
            $cookieBanner,
            $_POST['company_name'], $_POST['address'], $_POST['email'], $_POST['phone'], $_POST['representative'], $_POST['register_info'],
            $projectId
        ]);
        log_audit($projectId, $_POST['name'], 'Einstellungen aktualisiert');
        header("Location: /admin/settings?project_id=$projectId&msg=saved");
        exit;
    }

    if ($action === 'create_doc_type') {
        $title = trim($_POST['doc_title'] ?? '');
        $slug = trim($_POST['doc_slug'] ?? '');
        $isReq = !empty($_POST['is_required']) ? 1 : 0;

        if ($slug === '' && $title !== '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title));
        }

        if ($title !== '' && $slug !== '') {
            $stmt = $db->prepare("INSERT INTO doc_types (title, slug, is_required) VALUES (?, ?, ?)");
            $stmt->execute([$title, $slug, $isReq]);
            $newTypeId = (int)$db->lastInsertId();

            foreach ($projects as $p) {
                $docStmt = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $docStmt->execute([$p['id'], $newTypeId]);
            }

            log_audit(null, '', "Rechtstext-Typ „$title\" angelegt");
        }
        header("Location: /admin?project_id=$projectId&msg=type_created");
        exit;
    }

    if ($action === 'delete_doc_type') {
        $typeId = (int)$_POST['doc_type_id'];
        $typeTitle = (string)($db->query("SELECT title FROM doc_types WHERE id = " . (int)$typeId)->fetchColumn() ?: '');
        $stmt = $db->prepare("DELETE FROM doc_types WHERE id = ?");
        $stmt->execute([$typeId]);
        log_audit(null, '', "Rechtstext-Typ „$typeTitle\" gelöscht");
        header("Location: /admin?project_id=$projectId&msg=type_deleted");
        exit;
    }

    // Benutzer einladen
    if ($action === 'invite_user') {
        $name = trim($_POST['invite_name'] ?? '');
        $email = trim(strtolower($_POST['invite_email'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: /admin/users?msg=invalid");
            exit;
        }

        $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            header("Location: /admin/users?msg=email_exists");
            exit;
        }

        $token = bin2hex(random_bytes(32));
        $ins = $db->prepare("INSERT INTO users (name, email, status, invite_token) VALUES (?, ?, 'invited', ?)");
        $ins->execute([$name, $email, $token]);
        log_audit(null, '', "Person eingeladen: $name ($email)");

        $res = send_invite_mail($project, $name, $email, $token);
        header("Location: /admin/users?msg=" . ($res['success'] ? 'invited' : 'invite_mail_failed'));
        exit;
    }

    // Einladung erneut senden
    if ($action === 'resend_invite') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'invited'");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();

        if ($u) {
            $token = bin2hex(random_bytes(32));
            $upd = $db->prepare("UPDATE users SET invite_token = ? WHERE id = ?");
            $upd->execute([$token, $uid]);
            $res = send_invite_mail($project, $u['name'], $u['email'], $token);
            header("Location: /admin/users?msg=" . ($res['success'] ? 'invited' : 'invite_mail_failed'));
            exit;
        }
        header("Location: /admin/users");
        exit;
    }

    // Benutzer entfernen
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== (int)($_SESSION['paragrafy_user_id'] ?? 0)) {
            $delUserName = (string)($db->query("SELECT name FROM users WHERE id = " . (int)$uid)->fetchColumn() ?: '');
            $del = $db->prepare("DELETE FROM users WHERE id = ?");
            $del->execute([$uid]);
            log_audit(null, '', "Zugang entfernt: $delUserName");
        }
        header("Location: /admin/users?msg=user_deleted");
        exit;
    }
}

function send_invite_mail(array $project, string $name, string $email, string $token): array {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $project['domain'];
    $inviteLink = $scheme . '://' . $host . '/admin/accept-invite?token=' . $token;

    $html = "<h2>Einladung zu Paragrafy</h2>"
        . "<p>Hallo " . htmlspecialchars($name) . ",</p>"
        . "<p>du wurdest eingeladen, dem Paragrafy Admin-Panel beizutreten. Jede eingeladene Person hat vollen Zugriff auf alle Projekte — es gibt keine Rollen oder Rechte einzustellen.</p>"
        . "<p><a href='" . htmlspecialchars($inviteLink) . "' style='background:#e11d48;color:#fff;padding:0.6rem 1.2rem;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold;'>Zugang aktivieren</a></p>"
        . "<p>Oder kopiere diesen Link in deinen Browser:<br>" . htmlspecialchars($inviteLink) . "</p>";

    return send_smtp_mail($project, $email, "Einladung zu Paragrafy", $html);
}

function handle_accept_invite(PDO $db): void {
    $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    $stmt = $db->prepare("SELECT * FROM users WHERE invite_token = ? AND status = 'invited'");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    $error = null;
    if ($user && isset($_POST['action']) && $_POST['action'] === 'set_password') {
        $pass = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';
        if (strlen($pass) < 8) {
            $error = "Das Passwort muss mindestens 8 Zeichen lang sein.";
        } elseif ($pass !== $confirm) {
            $error = "Die Passwörter stimmen nicht überein.";
        } else {
            $upd = $db->prepare("UPDATE users SET password_hash = ?, status = 'active', invite_token = '', activated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);

            $_SESSION['paragrafy_admin'] = true;
            $_SESSION['paragrafy_user_id'] = (int)$user['id'];
            $_SESSION['paragrafy_user_name'] = $user['name'];
            $_SESSION['paragrafy_user_email'] = $user['email'];
            header('Location: /admin');
            exit;
        }
    }

    render_accept_invite_view($user, $error);
}

function render_accept_invite_view(?array $user, ?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Zugang aktivieren - Paragrafy</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags() ?>
        <?= theme_base_css() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: #fff; padding: 2.25rem; border-radius: 16px; width: 360px; box-shadow: 0 20px 40px rgba(0,0,0,0.06); border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: 8px; }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
            .err { color: var(--red); font-size: 0.8125rem; margin-bottom: 0.5rem; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2>Zugang aktivieren</h2>
            </div>
            <?php if (!$user): ?>
                <p style="font-size:13px;color:var(--text-muted)">Dieser Einladungslink ist ungültig oder wurde bereits verwendet. Bitte wende dich an eine andere Person mit Zugriff auf das Admin-Panel, um erneut eingeladen zu werden.</p>
            <?php else: ?>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 6px">Willkommen, <strong style="color:var(--text)"><?= htmlspecialchars($user['name']) ?></strong>. Lege ein Passwort für <strong style="color:var(--text)"><?= htmlspecialchars($user['email']) ?></strong> fest.</p>
                <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="set_password">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">
                    <label class="pg-label" style="margin-top:0;">Passwort (mind. 8 Zeichen)</label>
                    <input type="password" name="password" required style="width:100%;margin-bottom:10px;">
                    <label class="pg-label" style="margin-top:0;">Passwort bestätigen</label>
                    <input type="password" name="password_confirm" required style="width:100%;margin-bottom:1rem;">
                    <button type="submit" class="pg-btn" style="width:100%;justify-content:center;">Zugang aktivieren &rarr;</button>
                </form>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

$subRoute = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (str_starts_with($subRoute, '/admin/edit')) {
    require_once __DIR__ . '/editor.php';
    exit;
}

if (str_starts_with($subRoute, '/admin/settings')) {
    render_settings_view($db, $project, $projects);
    exit;
}

if (str_starts_with($subRoute, '/admin/users')) {
    render_users_view($db, $project, $projects);
    exit;
}

if (str_starts_with($subRoute, '/admin/audit')) {
    render_audit_view($db, $project, $projects);
    exit;
}

render_matrix_view($db, $project, $projects);

function render_login_view(?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Paragrafy Admin Login</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags() ?>
        <?= theme_base_css() ?>
        <style>
            body { display: flex; flex-direction: column; min-height: 100vh; align-items: center; justify-content: center; gap: 20px; }
            .login-card { background: #fff; padding: 2.25rem; border-radius: 16px; width: 340px; box-shadow: 0 20px 40px rgba(0,0,0,0.06); border: 1px solid var(--border); }
            .logo-header { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.4rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: 8px; }
            .logo-header h2 { margin: 0; font-size: 1.3rem; font-weight: 800; }
            .err { color: var(--red); font-size: 0.8125rem; margin-bottom: 0.5rem; }
            .login-disclaimer { max-width: 340px; text-align: center; font-size: 11px; color: var(--text-faintest); line-height: 1.5; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2>Paragrafy Admin</h2>
            </div>
            <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label class="pg-label" style="margin-top:0;">E-Mail <span style="color:var(--text-faint);font-weight:400">(persönlicher Zugang, sonst leer lassen)</span></label>
                <input type="email" name="email" placeholder="name@firma.de" style="width:100%;margin-bottom:10px;">
                <label class="pg-label" style="margin-top:0;">Passwort</label>
                <input type="password" name="password" placeholder="Passwort eingeben" autofocus required style="width:100%;margin-bottom:1rem;">
                <button type="submit" class="pg-btn" style="width:100%;justify-content:center;">Anmelden &rarr;</button>
            </form>
        </div>
        <div class="login-disclaimer">Paragrafy ist ein rein technisches Verwaltungswerkzeug (CMS/API) für Rechtstexte. Es stellt keine Rechtsberatung dar und übernimmt keine Haftung für Richtigkeit, Vollständigkeit oder Aktualität der eingepflegten Inhalte.</div>
    </body>
    </html>
    <?php
}

function render_matrix_view(PDO $db, array $project, array $projects): void {
    $docTypes = $db->query("SELECT * FROM doc_types ORDER BY is_required DESC, title ASC")->fetchAll();
    $activeLangs = array_filter(array_map('trim', explode(',', $project['active_languages'] ?? 'de,en')));
    $primaryLang = $project['primary_lang'] ?: 'de';
    $auditIntervalMonths = (int)($project['audit_interval_months'] ?? 12);

    $unfilledWarnings = [];
    $auditWarnings = [];
    $totalRequired = 0;
    $publishedRequired = 0;

    $stmt = $db->prepare("
        SELECT t.content, dt.title, t.lang, t.updated_at, t.source_hash, dt.is_required, t.document_id
        FROM translations t 
        JOIN documents d ON t.document_id = d.id 
        JOIN doc_types dt ON d.doc_type_id = dt.id 
        WHERE d.project_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$project['id']]);
    $allPublished = $stmt->fetchAll();

    $now = new DateTime();
    foreach ($allPublished as $row) {
        $unfilled = check_unfilled_placeholders($row['content'], $project);
        if (!empty($unfilled)) {
            $unfilledWarnings[] = $row['title'] . " (" . strtoupper($row['lang']) . "): " . implode(', ', $unfilled);
        }

        if (!empty($row['updated_at'])) {
            $updated = new DateTime($row['updated_at']);
            $diffMonths = (($now->format('Y') - $updated->format('Y')) * 12) + ($now->format('m') - $updated->format('m'));
            if ($diffMonths >= $auditIntervalMonths) {
                $days = $now->diff($updated)->days;
                $auditWarnings[] = $row['title'] . " (" . strtoupper($row['lang']) . ") seit " . $days . " Tagen ungeprüft";
            }
        }
    }

    foreach ($docTypes as $t) {
        if ($t['is_required']) {
            $totalRequired++;
            $chk = $db->prepare("SELECT COUNT(*) FROM translations t JOIN documents d ON t.document_id = d.id WHERE d.project_id = ? AND d.doc_type_id = ? AND t.lang = ? AND t.status = 'published'");
            $chk->execute([$project['id'], $t['id'], $primaryLang]);
            if ((int)$chk->fetchColumn() > 0) {
                $publishedRequired++;
            }
        }
    }
    $complianceScore = $totalRequired > 0 ? (int)round(($publishedRequired / $totalRequired) * 100) : 100;
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Paragrafy - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
        <style>
            .grid-add { display: grid; grid-template-columns: 2fr 1.5fr auto auto; gap: 0.75rem; align-items: center; margin-top: 10px; }
            .api-pill { background: var(--border-soft); padding: 2px 6px; border-radius: 5px; font-family: ui-monospace, monospace; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('dashboard', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong>Dashboard</strong></div>
                </div>

                <div class="pg-content">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;gap:20px;flex-wrap:wrap">
                        <div>
                            <h1 style="font-size:26px;font-weight:800;margin:0 0 6px;"><?= htmlspecialchars($project['name']) ?></h1>
                            <div style="font-size:13px;color:var(--text-muted);display:flex;gap:14px;flex-wrap:wrap">
                                <span>Domain: <strong style="color:var(--text);font-weight:600"><?= htmlspecialchars($project['domain']) ?></strong></span>
                                <span style="color:var(--border-strong)">&middot;</span>
                                <span>API: <span class="api-pill">/api/:lang/:slug</span></span>
                            </div>
                        </div>
                        <button type="button" class="pg-btn" onclick="openNewProjectModal()">+ Neues Projekt</button>
                    </div>

                    <!-- Health KPI Cards -->
                    <div class="pg-kpi-grid">
                        <div class="pg-kpi">
                            <div class="pg-kpi-label">Compliance-Score</div>
                            <div class="pg-kpi-val" style="color: <?= $complianceScore === 100 ? 'var(--green)' : 'var(--accent)' ?>;"><?= $complianceScore ?>%</div>
                            <div class="pg-kpi-sub"><?= $publishedRequired ?> von <?= $totalRequired ?> Pflichtseiten live</div>
                        </div>
                        <div class="pg-kpi">
                            <div class="pg-kpi-label">Aktive Sprachen</div>
                            <div class="pg-kpi-val"><?= count($activeLangs) ?></div>
                            <div class="pg-kpi-sub"><?= strtoupper(implode(', ', $activeLangs)) ?></div>
                        </div>
                        <div class="pg-kpi">
                            <div class="pg-kpi-label">Sync-Status</div>
                            <div class="pg-kpi-val" style="color:var(--green);display:flex;align-items:center;gap:6px;font-size:20px;">
                                <span style="width:8px;height:8px;border-radius:50%;background:#2fa06a;display:inline-block"></span>Aktiv
                            </div>
                            <div class="pg-kpi-sub">Quelltext-Hash Engine aktiv</div>
                        </div>
                        <div class="pg-kpi">
                            <div class="pg-kpi-label">Audit-Status</div>
                            <div class="pg-kpi-val" style="font-size:20px;color: <?= empty($auditWarnings) ? 'var(--green)' : '#b4650f' ?>;">
                                <?= empty($auditWarnings) ? 'Aktuell' : count($auditWarnings) . ' Fällig' ?>
                            </div>
                            <div class="pg-kpi-sub"><?= $auditIntervalMonths ?> Monate Prüfintervall</div>
                        </div>
                    </div>

                    <?php if (!empty($auditWarnings)): ?>
                        <div class="pg-alert pg-alert-red">
                            <div><strong>Audit-Prüfung fällig (älter als <?= $auditIntervalMonths ?> Monate):</strong>
                                <ul>
                                    <?php foreach (array_slice($auditWarnings, 0, 3) as $aw): ?>
                                        <li><?= htmlspecialchars($aw) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($unfilledWarnings)): ?>
                        <div class="pg-alert pg-alert-amber">
                            <div><strong>Fehlende Stammdaten in Texten:</strong> Folgende Platzhalter werden in veröffentlichten Rechtstexten verwendet, sind aber in den Einstellungen noch leer:
                                <ul>
                                    <?php foreach (array_slice($unfilledWarnings, 0, 3) as $w): ?>
                                        <li><?= htmlspecialchars($w) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Matrix -->
                    <div class="pg-card">
                        <div style="padding:20px 22px 14px">
                            <h2>Pflichtseiten &amp; Status-Matrix</h2>
                            <p class="pg-card-sub">Klicke auf einen Status, um die Seite in dieser Sprache zu bearbeiten. Über die Symbole rechts bearbeitest oder entfernst du den gesamten Rechtstext.</p>
                        </div>
                        <table class="pg-table">
                            <thead>
                                <tr>
                                    <th>Dokumententyp</th>
                                    <th>Pflicht</th>
                                    <?php foreach ($activeLangs as $lang): ?>
                                        <th><?= strtoupper(htmlspecialchars($lang)) ?></th>
                                    <?php endforeach; ?>
                                    <th style="text-align:right">Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($docTypes as $type): ?>
                                    <?php
                                    $stmt = $db->prepare("SELECT id FROM documents WHERE project_id = ? AND doc_type_id = ?");
                                    $stmt->execute([$project['id'], $type['id']]);
                                    $doc = $stmt->fetch();
                                    if (!$doc) {
                                        $ins = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                                        $ins->execute([$project['id'], $type['id']]);
                                        $docId = (int)$db->lastInsertId();
                                    } else {
                                        $docId = (int)$doc['id'];
                                    }

                                    $stmt = $db->prepare("SELECT source_hash, content FROM translations WHERE document_id = ? AND lang = ?");
                                    $stmt->execute([$docId, $primaryLang]);
                                    $sourceRow = $stmt->fetch();
                                    $currentSourceHash = $sourceRow ? md5($sourceRow['content']) : '';

                                    $primaryUrl = "https://" . $project['domain'] . "/" . $type['slug'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;font-size:13.5px"><?= htmlspecialchars($type['title']) ?></div>
                                            <div style="display:flex;align-items:center;gap:6px;margin-top:2px;white-space:nowrap">
                                                <a href="/<?= htmlspecialchars($type['slug']) ?>" target="_blank" style="font-size:11.5px;color:var(--text-faint);font-family:ui-monospace,monospace;" title="Direktlink in Hauptsprache">/<?= htmlspecialchars($type['slug']) ?></a>
                                                <button type="button" class="pg-copy-btn" title="Link kopieren" onclick="copyToClipboard('<?= htmlspecialchars($primaryUrl) ?>', 'Direktlink kopiert!')"><?= svg_icon('link', '', 13) ?></button>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" style="background:none;border:none;cursor:pointer;padding:0;" id="toggle_btn_<?= $type['id'] ?>" onclick="ajaxToggleRequired(<?= $type['id'] ?>)" title="Klicken zum Umschalten">
                                                <?php if ($type['is_required']): ?>
                                                    <span class="pg-req-label" id="span_req_<?= $type['id'] ?>">Pflichtseite</span>
                                                <?php else: ?>
                                                    <span class="pg-opt-label" id="span_req_<?= $type['id'] ?>">Optional</span>
                                                <?php endif; ?>
                                            </button>
                                        </td>
                                        <?php foreach ($activeLangs as $lang): ?>
                                            <?php
                                            $stmt = $db->prepare("SELECT * FROM translations WHERE document_id = ? AND lang = ?");
                                            $stmt->execute([$docId, $lang]);
                                            $trans = $stmt->fetch();

                                            $isOutdated = false;
                                            if ($trans && $lang !== $primaryLang && !empty($trans['source_hash']) && !empty($currentSourceHash)) {
                                                if ($trans['source_hash'] !== $currentSourceHash) {
                                                    $isOutdated = true;
                                                }
                                            }
                                            ?>
                                            <td>
                                                <?php if ($trans && $trans['status'] === 'published'): ?>
                                                    <?php if ($isOutdated): ?>
                                                        <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-amber" title="Das Original wurde geändert!"><span class="pg-pill-dot"></span>Veraltet</a>
                                                    <?php else: ?>
                                                        <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-green" title="<?= htmlspecialchars($trans['title']) ?> - Bearbeiten"><span class="pg-pill-dot"></span>Live</a>
                                                    <?php endif; ?>
                                                <?php elseif ($trans && $trans['status'] === 'draft'): ?>
                                                    <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-amber"><span class="pg-pill-dot"></span>Entwurf</a>
                                                <?php else: ?>
                                                    <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="pg-pill pg-pill-red">+ Erstellen</a>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td style="text-align:right">
                                            <div style="display:flex;justify-content:flex-end;gap:4px">
                                                <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= htmlspecialchars($primaryLang) ?>" class="pg-icon-btn" title="Bearbeiten">
                                                    <?= svg_icon('edit', '', 14) ?>
                                                </a>
                                                <?php if (!in_array($type['slug'], ['impressum', 'privacy'])): ?>
                                                    <form method="post" onsubmit="return confirm('Diesen Rechtstext wirklich löschen?');" style="margin:0;">
                                                        <input type="hidden" name="action" value="delete_doc_type">
                                                        <input type="hidden" name="doc_type_id" value="<?= $type['id'] ?>">
                                                        <button type="submit" class="pg-icon-btn danger" title="Löschen">
                                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 4.5h9"/><path d="M6.2 4.5V3.2c0-.4.3-.7.7-.7h2.2c.4 0 .7.3.7.7v1.3"/><path d="M4.8 4.5 5.2 13c0 .4.3.7.7.7h4c.4 0 .7-.3.7-.7l.4-8.5"/><path d="M6.7 7.3v3.6"/><path d="M9.3 7.3v3.6"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Backup & Export Box -->
                    <div class="pg-card pg-card-pad">
                        <h2>Backups &amp; Exporte</h2>
                        <p class="pg-card-sub" style="margin-bottom:14px">Sichere deine SQLite-Datenbank oder lade alle Rechtstexte als Markdown-Archiv herunter.</p>
                        <div style="display:flex;gap:10px;flex-wrap:wrap">
                            <a href="/admin?action=download_backup" class="pg-btn-secondary"><?= svg_icon('disk', '', 14) ?> Datenbank herunterladen</a>
                            <a href="/admin?action=export_markdown" class="pg-btn-secondary"><?= svg_icon('folder', '', 14) ?> Markdown-Export</a>
                            <button type="button" class="pg-btn-secondary" onclick="triggerAuditReport()"><?= svg_icon('mail', '', 14) ?> Audit-Report per E-Mail</button>
                        </div>
                    </div>

                    <!-- Neuer Rechtstext hinzufügen -->
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <h2>Neuen Rechtstext hinzufügen</h2>
                        <p class="pg-card-sub" style="margin-bottom:14px">Füge beliebig viele spezifische Rechtstexte hinzu (z. B. AGB B2B, Sponsoring, Lizenzvereinbarung).</p>
                        <form method="post">
                            <input type="hidden" name="action" value="create_doc_type">
                            <div class="grid-add">
                                <input type="text" name="doc_title" placeholder="Titel (z. B. AGB für Geschäftskunden / B2B)" required>
                                <input type="text" name="doc_slug" placeholder="URL-Slug (z. B. agb-b2b)" required>
                                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--text-muted);white-space:nowrap;cursor:pointer;">
                                    <input type="checkbox" name="is_required" value="1"> Pflichtseite
                                </label>
                                <button type="submit" class="pg-btn">+ Hinzufügen</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="pg-footer-note">Paragrafy ist ein rein technisches Verwaltungswerkzeug (CMS/API) für Rechtstexte. Es stellt keine Rechtsberatung dar und übernimmt keine Haftung für Richtigkeit, Vollständigkeit oder Aktualität der eingepflegten Inhalte.</div>
            </div>
        </div>

        <!-- Modal: Neues Projekt anlegen -->
        <div id="newProjectModal" class="pg-modal-backdrop" onclick="if(event.target === this) closeNewProjectModal()">
            <div class="pg-modal">
                <h2 style="font-size:19px;font-weight:800;margin:0 0 6px">Neues Projekt anlegen</h2>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px">Füge eine weitere Web-App, Landingpage oder SaaS zu deiner Paragrafy-Instanz hinzu.</p>

                <form method="post">
                    <input type="hidden" name="action" value="create_project">

                    <label class="pg-label" style="margin-top:0">Projektname</label>
                    <input type="text" name="new_project_name" placeholder="z. B. Beispiel App" required style="width:100%">

                    <label class="pg-label">Subdomain / Domain</label>
                    <input type="text" name="new_project_domain" placeholder="z. B. legal.beispielapp.de" required style="width:100%">

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div>
                            <label class="pg-label">Primärsprache</label>
                            <select name="new_primary_lang" style="width:100%">
                                <option value="de" selected>Deutsch (DE)</option>
                                <option value="en">Englisch (EN)</option>
                                <option value="es">Spanisch (ES)</option>
                                <option value="fr">Französisch (FR)</option>
                            </select>
                        </div>
                        <div>
                            <label class="pg-label">Akzentfarbe</label>
                            <input type="text" name="new_brand_color" value="#e11d48" style="width:100%;font-family:ui-monospace,monospace">
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:26px">
                        <button type="button" class="pg-btn-secondary" onclick="closeNewProjectModal()">Abbrechen</button>
                        <button type="submit" class="pg-btn">Projekt erstellen &rarr;</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="toastNotification" class="pg-toast">
            <?= svg_icon('check', '', 16) ?> <span id="toastMsg">Status aktualisiert</span>
        </div>

        <script>
            function openNewProjectModal() {
                document.getElementById('newProjectModal').style.display = 'flex';
            }

            function closeNewProjectModal() {
                document.getElementById('newProjectModal').style.display = 'none';
            }

            function showToast(msg) {
                const toast = document.getElementById('toastNotification');
                document.getElementById('toastMsg').innerText = msg;
                toast.classList.add('show');
                setTimeout(() => { toast.classList.remove('show'); }, 2500);
            }

            function copyToClipboard(text, successMsg = 'In Zwischenablage kopiert!') {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(() => {
                        showToast(successMsg);
                    }).catch(() => {
                        fallbackCopy(text, successMsg);
                    });
                } else {
                    fallbackCopy(text, successMsg);
                }
            }

            function fallbackCopy(text, successMsg) {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-999999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast(successMsg);
                } catch (err) {
                    alert('Kopieren fehlgeschlagen.');
                }
                document.body.removeChild(textArea);
            }

            async function ajaxToggleRequired(id) {
                const fd = new FormData();
                fd.append('action', 'toggle_required');
                fd.append('doc_type_id', id);

                try {
                    const res = await fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();
                    if (data.success) {
                        const span = document.getElementById('span_req_' + id);
                        if (data.is_required === 1) {
                            span.className = 'pg-req-label';
                            span.innerHTML = 'Pflichtseite';
                            showToast('Als Pflichtseite markiert');
                        } else {
                            span.className = 'pg-opt-label';
                            span.innerHTML = 'Optional';
                            showToast('Als optional markiert');
                        }
                    }
                } catch(e) {
                    location.reload();
                }
            }

            async function triggerAuditReport() {
                try {
                    const res = await fetch('/api/cron/audit');
                    const data = await res.json();
                    if (data.success) {
                        showToast(data.message || 'Audit-Report per E-Mail gesendet!');
                    } else {
                        alert('Fehler: ' + (data.error || 'Konnte Report nicht senden.'));
                    }
                } catch(e) {
                    alert('Fehler: ' + e.message);
                }
            }
        </script>
    </body>
    </html>
    <?php
}

function render_settings_view(PDO $db, array $project, array $projects): void {
    $env = load_env_file();
    $envDeepl = $env['DEEPL_API_KEY'] ?? '';

    // Letzte Webhook-Logs für dieses Projekt laden
    $stmtLogs = $db->prepare("SELECT * FROM webhook_logs WHERE project_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmtLogs->execute([$project['id']]);
    $logs = $stmtLogs->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Einstellungen - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
        <style>
            .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
            label.pg-label { margin-top: 14px; }
            hr.pg-sep { margin: 24px 0 18px; border: none; border-top: 1px solid var(--border-soft); }
            .btn-test { background: #17141b; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.8125rem; cursor: pointer; font-weight: 700; margin-top: 0.5rem; display: inline-flex; align-items: center; gap: 0.35rem; }
            .btn-test:hover { background: #2b2732; }

            /* Webhook Log Table */
            .log-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 13px; }
            .log-table th, .log-table td { padding: 10px 0; border-bottom: 1px solid var(--border-soft); text-align: left; }
            .log-table th { color: var(--text-faint); font-weight: 700; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; }
            .status-badge { padding: 3px 9px; border-radius: 20px; font-weight: 700; font-family: ui-monospace, monospace; font-size: 12px; }
            .status-200 { background: var(--green-bg); color: var(--green); }
            .status-err { background: #FBE7EA; color: var(--red); }
            .payload-preview { font-family: ui-monospace, monospace; font-size: 12px; color: var(--text-muted); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('settings', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong>Einstellungen</strong></div>
                </div>

                <div class="pg-content" style="max-width:840px">
                    <div class="pg-card pg-card-pad">
                        <h2 style="margin-bottom:18px">Projekt &amp; API-Konfiguration</h2>
                        <form method="post">
                            <input type="hidden" name="action" value="save_project">

                            <div class="grid">
                                <div>
                                    <label class="pg-label" style="margin-top:0">Projekt-Name</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0">Domain / Subdomain</label>
                                    <input type="text" name="domain" value="<?= htmlspecialchars($project['domain']) ?>" required style="width:100%">
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label">Primärsprache</label>
                                    <input type="text" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>" required style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label">Aktive Sprachen (kommagetrennt, z. B. de,en,es)</label>
                                    <input type="text" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>" required style="width:100%">
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label">Akzentfarbe</label>
                                    <div style="display:flex;gap:8px;align-items:center">
                                        <input type="color" id="adm_cp" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>" style="width:34px;height:34px;padding:0;border:1px solid var(--border-strong);border-radius:8px;cursor:pointer;flex-shrink:0" oninput="document.getElementById('adm_ct').value = this.value.toUpperCase();">
                                        <input type="text" id="adm_ct" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>" maxlength="7" style="flex:1;font-family:ui-monospace,monospace;text-transform:uppercase" oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('adm_cp').value = this.value;">
                                    </div>
                                </div>
                                <div>
                                    <label class="pg-label">Logo-URL <span style="color:var(--text-faint);font-weight:400">(optional)</span></label>
                                    <input type="text" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>" placeholder="/paragrafy.svg" style="width:100%">
                                </div>
                            </div>

                            <div style="display:flex;align-items:center;gap:24px;margin-top:18px;padding-top:16px;border-top:1px solid var(--border-soft)">
                                <div style="flex:1">
                                    <label class="pg-label" style="margin-top:0">Audit-Intervall (Monate)</label>
                                    <input type="number" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>" min="1" max="36" required style="width:100px">
                                    <div class="pg-hint">Warnt im Dashboard nach X Monaten vor ungeprüften Texten.</div>
                                </div>
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;cursor:pointer">
                                    <input type="checkbox" name="cookie_banner_enabled" value="1" <?= !empty($project['cookie_banner_enabled']) ? 'checked' : '' ?>>
                                    DSGVO-Cookie-Banner (/consent.js) aktivieren
                                </label>
                            </div>

                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="<?= htmlspecialchars($project['smtp_pass'] ?? '') ?>">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="<?= htmlspecialchars($project['webhook_secret'] ?? '') ?>">
                            <input type="hidden" name="deepl_api_key" value="<?= htmlspecialchars($project['deepl_api_key'] ?? '') ?>">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <div style="margin-top:18px">
                                <button type="submit" class="pg-btn"><?= svg_icon('disk', '', 16) ?> Speichern</button>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2>E-Mail-Versand &amp; Audit-Benachrichtigung</h2>
                        <p class="pg-card-sub" style="margin-bottom:16px">Damit verschickt Paragrafy Prüf-Erinnerungen und Testmails. Zugangsdaten bekommst du von deinem E-Mail-Anbieter (SMTP).</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="<?= htmlspecialchars($project['webhook_secret'] ?? '') ?>">
                            <input type="hidden" name="deepl_api_key" value="<?= htmlspecialchars($project['deepl_api_key'] ?? '') ?>">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <div class="grid">
                                <div>
                                    <label class="pg-label" style="margin-top:0">SMTP-Server</label>
                                    <input type="text" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>" placeholder="z. B. mail.deinefirma.de" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0">Port &amp; Verschlüsselung</label>
                                    <div style="display:flex;gap:8px">
                                        <input type="number" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>" style="width:90px">
                                        <select name="smtp_secure" style="flex:1">
                                            <option value="tls" <?= ($project['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                                            <option value="ssl" <?= ($project['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                                            <option value="none" <?= ($project['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>Keine (25)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label">Benutzername</label>
                                    <input type="text" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>" placeholder="absender@deinedomain.de" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label">Passwort</label>
                                    <input type="password" name="smtp_pass" value="<?= htmlspecialchars($project['smtp_pass'] ?? '') ?>" placeholder="Passwort eingeben" style="width:100%">
                                </div>
                            </div>

                            <div class="grid">
                                <div>
                                    <label class="pg-label">Absender-Adresse</label>
                                    <input type="email" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>" placeholder="legal@deinefirma.de" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label">Empfänger für Audit-Reports</label>
                                    <input type="email" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>" placeholder="deine-mail@domain.de" style="width:100%">
                                </div>
                            </div>

                            <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                <button type="submit" class="pg-btn-secondary"><?= svg_icon('disk', '', 14) ?> Speichern</button>
                                <?php if (!empty($project['smtp_host'])): ?>
                                    <button type="button" class="btn-test" onclick="triggerTestMail()"><?= svg_icon('mail', '', 14) ?> Test-E-Mail jetzt senden</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2>Webhooks &amp; Übersetzung</h2>
                        <p class="pg-card-sub" style="margin-bottom:16px">Ein Webhook benachrichtigt deine Haupt-App automatisch, sobald sich ein Rechtstext ändert — kein manuelles Nachpflegen nötig.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="<?= htmlspecialchars($project['smtp_pass'] ?? '') ?>">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                            <input type="hidden" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                            <input type="hidden" name="address" value="<?= htmlspecialchars($project['address'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                            <input type="hidden" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                            <input type="hidden" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                            <label class="pg-label" style="margin-top:0">Webhook-URL (POST bei Textänderungen)</label>
                            <input type="text" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>" placeholder="https://app.deinefirma.de/api/legal-webhook" style="width:100%;margin-bottom:14px">

                            <label class="pg-label" style="margin-top:0">Webhook-Secret <span style="color:var(--text-faint);font-weight:400">(optional, für Signaturprüfung)</span></label>
                            <input type="text" name="webhook_secret" value="<?= htmlspecialchars($project['webhook_secret'] ?? '') ?>" placeholder="z. B. ein geheimer Schlüssel" style="width:100%;margin-bottom:14px">

                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px">
                                <button type="submit" class="pg-btn-secondary"><?= svg_icon('disk', '', 14) ?> Speichern</button>
                                <?php if (!empty($project['webhook_url'])): ?>
                                    <button type="button" class="btn-test" onclick="triggerTestWebhook()"><?= svg_icon('lightning', '', 14) ?> Test-Webhook senden &amp; prüfen</button>
                                <?php endif; ?>
                            </div>

                            <div style="border-top:1px solid var(--border-soft);padding-top:16px">
                                <label class="pg-label" style="margin-top:0">DeepL API-Key <span style="color:var(--text-faint);font-weight:400">— ermöglicht 1-Klick-Übersetzung im Editor</span></label>
                                <input type="text" name="deepl_api_key" value="<?= htmlspecialchars($project['deepl_api_key'] ?? '') ?>" placeholder="z. B. xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx" style="width:100%">
                                <?php if (!empty($envDeepl)): ?>
                                    <div class="pg-hint" style="color:var(--green)">In .env.local hinterlegt (wird automatisch als Fallback genutzt).</div>
                                <?php else: ?>
                                    <div class="pg-hint">Unterstützt Free- &amp; Pro-Keys (z. B. <code>...:fx</code>).</div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <div class="pg-card pg-card-pad">
                        <h2>Unternehmensdaten</h2>
                        <p class="pg-card-sub" style="margin-bottom:16px">Diese Angaben ersetzen automatisch Platzhalter wie <code style="background:var(--border-soft);padding:1px 5px;border-radius:4px">{{company_name}}</code> in allen deinen Rechtstexten.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_project">
                            <input type="hidden" name="name" value="<?= htmlspecialchars($project['name']) ?>">
                            <input type="hidden" name="domain" value="<?= htmlspecialchars($project['domain']) ?>">
                            <input type="hidden" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>">
                            <input type="hidden" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>">
                            <input type="hidden" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>">
                            <input type="hidden" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>">
                            <input type="hidden" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>">
                            <?php if (!empty($project['cookie_banner_enabled'])): ?><input type="hidden" name="cookie_banner_enabled" value="1"><?php endif; ?>
                            <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>">
                            <input type="hidden" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>">
                            <input type="hidden" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>">
                            <input type="hidden" name="smtp_pass" value="<?= htmlspecialchars($project['smtp_pass'] ?? '') ?>">
                            <input type="hidden" name="smtp_secure" value="<?= htmlspecialchars($project['smtp_secure'] ?? 'tls') ?>">
                            <input type="hidden" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>">
                            <input type="hidden" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>">
                            <input type="hidden" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>">
                            <input type="hidden" name="webhook_secret" value="<?= htmlspecialchars($project['webhook_secret'] ?? '') ?>">
                            <input type="hidden" name="deepl_api_key" value="<?= htmlspecialchars($project['deepl_api_key'] ?? '') ?>">

                            <div class="grid">
                                <div>
                                    <label class="pg-label" style="margin-top:0">Firmenname</label>
                                    <input type="text" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0">Vertretungsberechtigte Person</label>
                                    <input type="text" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>" style="width:100%">
                                </div>
                                <div style="grid-column:1/-1">
                                    <label class="pg-label" style="margin-top:0">Anschrift</label>
                                    <textarea name="address" rows="2" style="width:100%"><?= htmlspecialchars($project['address'] ?? '') ?></textarea>
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0">E-Mail-Adresse</label>
                                    <input type="text" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>" style="width:100%">
                                </div>
                                <div>
                                    <label class="pg-label" style="margin-top:0">Telefonnummer</label>
                                    <input type="text" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>" style="width:100%">
                                </div>
                                <div style="grid-column:1/-1">
                                    <label class="pg-label" style="margin-top:0">Register-Informationen</label>
                                    <input type="text" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>" style="width:100%">
                                </div>
                            </div>

                            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:18px">
                                <button type="submit" class="pg-btn"><?= svg_icon('disk', '', 16) ?> Einstellungen speichern</button>

                                <?php if (count($projects) > 1): ?>
                                    <button type="button" class="pg-btn-danger" onclick="document.getElementById('deleteProjectForm').submit()">Projekt löschen</button>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if (count($projects) > 1): ?>
                            <form id="deleteProjectForm" method="post" onsubmit="return confirm('Möchtest du dieses Projekt (<?= htmlspecialchars($project['name']) ?>) und alle zugehörigen Texte wirklich löschen?');" style="display:none">
                                <input type="hidden" name="action" value="delete_project">
                                <input type="hidden" name="delete_project_id" value="<?= $project['id'] ?>">
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Webhook Protokoll & Delivery Logs -->
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                            <h2 style="margin:0">Webhook-Protokoll</h2>
                            <?php if (!empty($logs)): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="clear_webhook_logs">
                                    <button type="submit" class="pg-btn-secondary" style="padding:6px 12px;font-size:12px">Logs leeren</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <p class="pg-card-sub" style="margin-bottom:14px">Die letzten Zustellungen inklusive Status, Antwort und Latenz.</p>

                        <?php if (empty($logs)): ?>
                            <div style="color:var(--text-faint);font-size:13px;font-style:italic;padding:1rem 0;">Noch keine Webhook-Aktivitäten für dieses Projekt protokolliert. Klicke oben auf „Test-Webhook senden“, um einen ersten Eintrag zu erzeugen.</div>
                        <?php else: ?>
                            <table class="log-table">
                                <thead>
                                    <tr>
                                        <th>Zeitpunkt</th>
                                        <th>Event</th>
                                        <th>Status</th>
                                        <th>Latenz</th>
                                        <th>Antwort</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $l): ?>
                                        <?php $isSuccess = ($l['status_code'] >= 200 && $l['status_code'] < 300); ?>
                                        <tr>
                                            <td style="color:var(--text-muted)"><?= date('d.m. H:i:s', strtotime($l['created_at'])) ?></td>
                                            <td style="font-weight:500"><?= htmlspecialchars($l['event_name']) ?></td>
                                            <td>
                                                <span class="status-badge <?= $isSuccess ? 'status-200' : 'status-err' ?>">
                                                    <?= $l['status_code'] ?: 'ERR' ?>
                                                </span>
                                            </td>
                                            <td style="color:var(--text-muted)"><?= $l['duration_ms'] ?> ms</td>
                                            <td>
                                                <?php if (!empty($l['error_message'])): ?>
                                                    <span style="color:var(--red);font-weight:600;"><?= htmlspecialchars($l['error_message']) ?></span>
                                                <?php elseif (!empty($l['response_body'])): ?>
                                                    <div class="payload-preview" title="<?= htmlspecialchars($l['response_body']) ?>">
                                                        <?= htmlspecialchars($l['response_body']) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color:var(--text-faint);font-style:italic;">(Leere Antwort)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pg-footer-note">Paragrafy ist ein rein technisches Verwaltungswerkzeug (CMS/API) für Rechtstexte. Es stellt keine Rechtsberatung dar und übernimmt keine Haftung für Richtigkeit, Vollständigkeit oder Aktualität der eingepflegten Inhalte.</div>
            </div>
        </div>

        <script>
            async function triggerTestWebhook() {
                const formData = new FormData();
                formData.append('action', 'test_webhook');
                try {
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        alert(`Webhook erfolgreich zugestellt!\nHTTP-Status: ${data.status_code}\nDauer: ${data.duration_ms} ms\nAntwort: ${data.response || '(leer)'}`);
                        location.reload();
                    } else {
                        alert(`Fehler bei der Webhook-Zustellung:\nHTTP-Status: ${data.status_code}\nFehler: ${data.error || 'Unbekannt'}\nAntwort: ${data.response || '(leer)'}`);
                        location.reload();
                    }
                } catch (e) {
                    alert('Netzwerkfehler: ' + e.message);
                }
            }

            async function triggerTestMail() {
                const formData = new FormData();
                formData.append('action', 'test_smtp');
                try {
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        alert('Test-E-Mail erfolgreich via SMTP versendet!');
                    } else {
                        alert('Fehler: ' + (data.error || 'SMTP Versand fehlgeschlagen.'));
                    }
                } catch (e) {
                    alert('Netzwerkfehler: ' + e.message);
                }
            }
        </script>
    </body>
    </html>
    <?php
}

function render_users_view(PDO $db, array $project, array $projects): void {
    $users = $db->query("SELECT * FROM users ORDER BY (status = 'active') DESC, name ASC")->fetchAll();
    $msg = $_GET['msg'] ?? '';
    $msgMap = [
        'invited' => ['ok', 'Einladung erfolgreich per E-Mail versendet.'],
        'invite_mail_failed' => ['err', 'Die Person wurde angelegt, aber der Versand der Einladungs-E-Mail ist fehlgeschlagen. Bitte SMTP-Einstellungen prüfen oder erneut senden.'],
        'email_exists' => ['err', 'Für diese E-Mail-Adresse existiert bereits ein Zugang.'],
        'invalid' => ['err', 'Bitte Name und eine gültige E-Mail-Adresse angeben.'],
        'user_deleted' => ['ok', 'Zugang entfernt.'],
    ];
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Benutzer - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
        <style>
            .users-table th { text-align:left; }
        </style>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('users', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong>Benutzer</strong></div>
                </div>

                <div class="pg-content" style="max-width:840px">
                    <?php if ($msg && isset($msgMap[$msg])): ?>
                        <div class="pg-alert <?= $msgMap[$msg][0] === 'ok' ? 'pg-alert-amber' : 'pg-alert-red' ?>" style="<?= $msgMap[$msg][0] === 'ok' ? 'background:var(--green-bg);border-color:#bfe6cf;color:#125c3a' : '' ?>">
                            <div><?= htmlspecialchars($msgMap[$msg][1]) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="pg-card pg-card-pad">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                            <h2 style="margin:0">Benutzerverwaltung</h2>
                            <button type="button" class="pg-btn" style="padding:8px 14px;font-size:12.5px" onclick="openInviteModal()">+ Person einladen</button>
                        </div>
                        <p class="pg-card-sub" style="margin-bottom:16px">Jede eingeladene Person hat vollen Zugriff auf das gesamte Admin-Panel (alle Projekte) — es gibt keine Rollen oder Rechte einzustellen.</p>

                        <table class="pg-table users-table" style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>E-Mail</th>
                                    <th>Status</th>
                                    <th style="width:90px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="4" style="color:var(--text-faint);font-style:italic">Noch keine Personen eingeladen.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($users as $u): ?>
                                    <?php $isSelf = (int)$u['id'] === (int)($_SESSION['paragrafy_user_id'] ?? 0); ?>
                                    <tr>
                                        <td style="font-weight:600"><?= htmlspecialchars($u['name']) ?><?= $isSelf ? ' <span style="color:var(--text-faint);font-weight:500">(Du)</span>' : '' ?></td>
                                        <td style="color:var(--text-muted)"><?= htmlspecialchars($u['email']) ?></td>
                                        <td>
                                            <?php if ($u['status'] === 'active'): ?>
                                                <span class="pg-pill pg-pill-green"><span class="pg-pill-dot"></span>Aktiv</span>
                                            <?php else: ?>
                                                <span class="pg-pill pg-pill-muted">Eingeladen</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:flex;justify-content:flex-end;gap:4px">
                                                <?php if ($u['status'] === 'invited'): ?>
                                                    <form method="post" style="margin:0">
                                                        <input type="hidden" name="action" value="resend_invite">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="pg-icon-btn" title="Einladung erneut senden">
                                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8a6 6 0 1 1 1.8 4.3"/><path d="M2 12v-3h3"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($isSelf): ?>
                                                    <span style="color:var(--text-faintest);padding:6px 8px">&mdash;</span>
                                                <?php else: ?>
                                                    <form method="post" onsubmit="return confirm('Zugang für <?= htmlspecialchars($u['name']) ?> wirklich entfernen?');" style="margin:0">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                        <button type="submit" class="pg-icon-btn danger" title="Entfernen">
                                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 4.5h9"/><path d="M6.2 4.5V3.2c0-.4.3-.7.7-.7h2.2c.4 0 .7.3.7.7v1.3"/><path d="M4.8 4.5 5.2 13c0 .4.3.7.7.7h4c.4 0 .7-.3.7-.7l.4-8.5"/><path d="M6.7 7.3v3.6"/><path d="M9.3 7.3v3.6"/></svg>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="pg-footer-note">Paragrafy ist ein rein technisches Verwaltungswerkzeug (CMS/API) für Rechtstexte. Es stellt keine Rechtsberatung dar und übernimmt keine Haftung für Richtigkeit, Vollständigkeit oder Aktualität der eingepflegten Inhalte.</div>
            </div>
        </div>

        <!-- Modal: Person einladen -->
        <div id="inviteModal" class="pg-modal-backdrop" onclick="if(event.target === this) closeInviteModal()">
            <div class="pg-modal">
                <h2 style="font-size:19px;font-weight:800;margin:0 0 6px">Person einladen</h2>
                <p style="font-size:13px;color:var(--text-muted);margin:0 0 20px">Die Person erhält eine E-Mail mit einem Link, um ihr eigenes Passwort festzulegen.</p>

                <form method="post">
                    <input type="hidden" name="action" value="invite_user">

                    <label class="pg-label" style="margin-top:0">Name</label>
                    <input type="text" name="invite_name" placeholder="z. B. Lena Fischer" required style="width:100%">

                    <label class="pg-label">E-Mail</label>
                    <input type="email" name="invite_email" placeholder="z. B. lena@firma.de" required style="width:100%">

                    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:26px">
                        <button type="button" class="pg-btn-secondary" onclick="closeInviteModal()">Abbrechen</button>
                        <button type="submit" class="pg-btn">Einladung senden &rarr;</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openInviteModal() {
                document.getElementById('inviteModal').style.display = 'flex';
            }
            function closeInviteModal() {
                document.getElementById('inviteModal').style.display = 'none';
            }
        </script>
    </body>
    </html>
    <?php
}

function render_audit_view(PDO $db, array $project, array $projects): void {
    $stmt = $db->prepare("SELECT * FROM audit_log WHERE project_id = ? OR project_id IS NULL ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$project['id']]);
    $entries = $stmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Protokoll - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
    </head>
    <body>
        <div class="pg-shell">
            <?= render_sidebar('audit', $project, $projects) ?>

            <div class="pg-main">
                <div class="pg-topbar">
                    <div class="pg-crumb"><?= htmlspecialchars($project['name']) ?> <span style="margin:0 4px">/</span> <strong>Protokoll</strong></div>
                </div>

                <div class="pg-content" style="max-width:840px">
                    <div class="pg-card pg-card-pad" style="margin-bottom:0">
                        <h2>Änderungsprotokoll</h2>
                        <p class="pg-card-sub" style="margin-bottom:16px">Wer hat wann was geändert — Projekteinstellungen, Rechtstexte und Benutzerverwaltung. Zeigt die letzten 200 Einträge.</p>

                        <?php if (empty($entries)): ?>
                            <div style="color:var(--text-faint);font-size:13px;font-style:italic;padding:1rem 0;">Noch keine Einträge vorhanden.</div>
                        <?php else: ?>
                            <table class="pg-table" style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
                                <thead>
                                    <tr>
                                        <th>Zeitpunkt</th>
                                        <th>Benutzer</th>
                                        <th>Aktion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entries as $e): ?>
                                        <tr>
                                            <td style="color:var(--text-muted);white-space:nowrap"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></td>
                                            <td style="font-weight:600"><?= htmlspecialchars($e['user_name']) ?></td>
                                            <td><?= htmlspecialchars($e['action']) ?><?php if (!empty($e['project_name'])): ?> <span style="color:var(--text-faint)">&middot; <?= htmlspecialchars($e['project_name']) ?></span><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pg-footer-note">Paragrafy ist ein rein technisches Verwaltungswerkzeug (CMS/API) für Rechtstexte. Es stellt keine Rechtsberatung dar und übernimmt keine Haftung für Richtigkeit, Vollständigkeit oder Aktualität der eingepflegten Inhalte.</div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
