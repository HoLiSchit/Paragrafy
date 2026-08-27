<?php
/**
 * Paragrafy v1.5.4 - Admin Command Center, Multi-Project Manager & Compliance Engine
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

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $pass = $_POST['password'] ?? '';
    if (password_verify($pass, $config['admin_password_hash'] ?? '')) {
        $_SESSION['paragrafy_admin'] = true;
        header('Location: /admin');
        exit;
    }
    $error = "Falsches Passwort.";
}

if (isset($_GET['logout'])) {
    unset($_SESSION['paragrafy_admin']);
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

// 3. Webhook Test Trigger
if (isset($_POST['action']) && $_POST['action'] === 'test_webhook') {
    header('Content-Type: application/json');
    dispatch_webhook($project, [
        'test' => true,
        'message' => 'Paragrafy Webhook Test erfolgreich ausgelöst',
        'triggered_at' => date('c')
    ]);
    echo json_encode(['success' => true]);
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

            // Automatisch alle vorhandenen Dokumenttypen für das neue Projekt verknüpfen
            $docTypes = $db->query("SELECT id FROM doc_types")->fetchAll();
            foreach ($docTypes as $dt) {
                $insDoc = $db->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $insDoc->execute([$newProjectId, $dt['id']]);
            }

            header("Location: /admin?project_id=$newProjectId&msg=project_created");
            exit;
        }
    }

    // Projekt löschen
    if ($action === 'delete_project') {
        $delId = (int)$_POST['delete_project_id'];
        if (count($projects) > 1) {
            $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$delId]);
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
        }
        header("Location: /admin?project_id=$projectId&msg=type_created");
        exit;
    }

    if ($action === 'delete_doc_type') {
        $typeId = (int)$_POST['doc_type_id'];
        $stmt = $db->prepare("DELETE FROM doc_types WHERE id = ?");
        $stmt->execute([$typeId]);
        header("Location: /admin?project_id=$projectId&msg=type_deleted");
        exit;
    }
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

render_matrix_view($db, $project, $projects);

function render_login_view(?string $error): void {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Paragrafy Admin Login</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #090d16; color: #f8fafc; display: flex; height: 100vh; align-items: center; justify-content: center; margin: 0; }
            .card { background: #131b2e; padding: 2.5rem; border-radius: 16px; width: 340px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); border: 1px solid #1e293b; }
            .logo-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1.25rem; }
            .logo-header img { width: 34px; height: 34px; border-radius: 8px; }
            h2 { margin: 0; font-size: 1.35rem; color:#fff; font-weight: 800; }
            input[type=password] { width: 100%; padding: 0.75rem; box-sizing: border-box; margin: 1rem 0; border-radius: 8px; border: 1px solid #334155; background: #0b1120; color: #fff; font-size: 0.95rem; }
            button { width: 100%; padding: 0.8rem; background: #e11d48; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(225,29,72,0.3); }
            button:hover { background: #be123c; }
            .err { color: #f87171; font-size: 0.875rem; margin-bottom: 0.5rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="logo-header">
                <img src="/paragrafy.svg" alt="Paragrafy">
                <h2>Paragrafy Admin</h2>
            </div>
            <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label style="font-size: 0.8125rem; color:#94a3b8; font-weight:600;">Admin-Passwort:</label>
                <input type="password" name="password" placeholder="Passwort eingeben" autofocus required>
                <button type="submit">Anmelden &rarr;</button>
            </form>
        </div>
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
        <meta charset="utf-8"><title>Paragrafy - Command Center</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <style>
            :root { --primary: #e11d48; --card-bg: #ffffff; --border: #e2e8f0; --text-muted: #64748b; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; }
            
            .nav { background: #090d16; color: #fff; padding: 0.85rem 1.75rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #1e293b; }
            .brand-box { display: flex; align-items: center; gap: 0.65rem; }
            .brand-box img { width: 28px; height: 28px; border-radius: 6px; }
            .nav a { color: #94a3b8; text-decoration: none; margin-left: 1.25rem; font-size: 0.9rem; font-weight: 600; transition: color 0.2s; }
            .nav a:hover, .nav a.active { color: #fff; }
            
            .container { max-width: 1240px; margin: 2rem auto; padding: 0 1.5rem; }
            .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem; }
            .project-controls { display: flex; align-items: center; gap: 0.6rem; }
            
            /* Metric / Health KPI Cards */
            .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-bottom: 2rem; }
            @media (max-width: 900px) { .kpi-grid { grid-template-columns: 1fr 1fr; } }
            @media (max-width: 550px) { .kpi-grid { grid-template-columns: 1fr; } }
            
            .kpi-card { background: #ffffff; border: 1px solid var(--border); border-radius: 14px; padding: 1.25rem 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; }
            .kpi-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 0.5rem; }
            .kpi-val { font-size: 1.6rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 0.5rem; }
            .kpi-sub { font-size: 0.8125rem; color: var(--text-muted); margin-top: 0.35rem; }

            .card { background: #fff; border-radius: 14px; border: 1px solid var(--border); padding: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 2rem; }
            
            table { width: 100%; border-collapse: collapse; margin-top: 1.25rem; }
            th, td { text-align: left; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
            th { background: #f8fafc; font-size: 0.75rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
            
            .badge { padding: 0.35rem 0.85rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none; transition: all 0.2s; }
            .badge-green { background: #dcfce7; color: #166534; }
            .badge-yellow { background: #fef9c3; color: #854d0e; }
            .badge-red { background: #fee2e2; color: #991b1b; }
            .badge-outdated { background: #fed7aa; color: #9a3412; border: 1px dashed #ea580c; }
            
            .pulse-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
            .dot-green { background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.3); }
            .dot-yellow { background: #eab308; }
            .dot-red { background: #ef4444; }
            .dot-outdated { background: #ea580c; animation: pulse 1.5s infinite; }
            @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }

            .toggle-btn { background: none; border: none; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 0.3rem; }
            .toggle-required { color: #e11d48; font-weight: 700; font-size: 0.75rem; border-bottom: 1px dashed #e11d48; }
            .toggle-optional { color: #94a3b8; font-size: 0.75rem; border-bottom: 1px dashed #cbd5e1; }
            
            select, input[type=text] { padding: 0.6rem 0.85rem; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 0.875rem; }
            .btn { background: #e11d48; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 0.875rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; box-shadow: 0 2px 6px rgba(225,29,72,0.2); }
            .btn:hover { background: #be123c; transform: translateY(-1px); }
            .btn-secondary { background: #1e293b; color: #f8fafc; }
            .btn-secondary:hover { background: #334155; }
            .btn-new-proj { background: #0f172a; color: #38bdf8; border: 1px solid #334155; padding: 0.6rem 1rem; border-radius: 8px; font-weight: 700; font-size: 0.875rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; }
            .btn-new-proj:hover { background: #1e293b; color: #7dd3fc; }
            
            .grid-add { display: grid; grid-template-columns: 2fr 1.5fr auto auto; gap: 0.75rem; align-items: center; margin-top: 1rem; }
            
            .alert-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; color: #92400e; font-size: 0.875rem; }
            .alert-audit { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
            .api-pill { background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 6px; font-family: monospace; font-size: 0.75rem; color: #475569; }
            .action-bar { display: flex; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap; }

            .toast { position: fixed; bottom: 2rem; right: 2rem; background: #0f172a; color: #fff; padding: 0.85rem 1.5rem; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 99999; font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; transform: translateY(100px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
            .toast.show { transform: translateY(0); opacity: 1; }
            
            /* Modal Backdrop */
            .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 999990; display: none; align-items: center; justify-content: center; padding: 1rem; }
            .modal { background: #fff; border-radius: 16px; padding: 2rem; width: 100%; max-width: 520px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); box-sizing: border-box; }
        </style>
    </head>
    <body>
        <div class="nav">
            <div class="brand-box">
                <img src="/paragrafy.svg" alt="Logo">
                <strong style="color:#fb7185; font-size:1.1rem;">Paragrafy</strong>
            </div>
            <div>
                <a href="/admin" class="active">Matrix</a>
                <a href="/admin/settings?project_id=<?= $project['id'] ?>">Einstellungen & Stammdaten</a>
                <a href="/admin?logout=1" style="color:#f87171;">Abmelden</a>
            </div>
        </div>

        <div class="container">
            <div class="header-bar">
                <div>
                    <h1 style="margin:0 0 0.25rem 0; font-size: 1.75rem; font-weight:800; letter-spacing:-0.02em;"><?= htmlspecialchars($project['name']) ?></h1>
                    <small style="color:#64748b;">Domain: <code><?= htmlspecialchars($project['domain']) ?></code> &bull; Headless API: <span class="api-pill">/api/:lang/:slug</span></small>
                </div>
                <div class="project-controls">
                    <select onchange="location.href='/admin?project_id=' + this.value" style="font-weight:700;">
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $p['id'] === (int)$project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['domain']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-new-proj" onclick="openNewProjectModal()">+ Neues Projekt</button>
                </div>
            </div>

            <!-- Health KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Compliance-Score</div>
                    <div class="kpi-val" style="color: <?= $complianceScore === 100 ? '#16a34a' : '#e11d48' ?>;">
                        <?= $complianceScore ?>%
                    </div>
                    <div class="kpi-sub"><?= $publishedRequired ?> von <?= $totalRequired ?> Pflichtseiten live</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Aktive Sprachen</div>
                    <div class="kpi-val"><?= count($activeLangs) ?></div>
                    <div class="kpi-sub"><?= strtoupper(implode(', ', $activeLangs)) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Sync-Status</div>
                    <div class="kpi-val" style="color:#16a34a;">
                        <?= svg_icon('sync', '', 20) ?> Aktiv
                    </div>
                    <div class="kpi-sub">Quelltext-Hash Engine aktiv</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Audit-Status</div>
                    <div class="kpi-val" style="color: <?= empty($auditWarnings) ? '#16a34a' : '#ea580c' ?>;">
                        <?= empty($auditWarnings) ? 'Aktuell' : count($auditWarnings) . ' Fällig' ?>
                    </div>
                    <div class="kpi-sub"><?= $auditIntervalMonths ?> Monate Prüfintervall</div>
                </div>
            </div>

            <?php if (!empty($auditWarnings)): ?>
                <div class="alert-box alert-audit">
                    <strong><?= svg_icon('clock', '', 16) ?> Audit-Prüfung fällig (älter als <?= $auditIntervalMonths ?> Monate):</strong>
                    <ul style="margin:0.5rem 0 0 1.25rem; padding:0;">
                        <?php foreach (array_slice($auditWarnings, 0, 3) as $aw): ?>
                            <li><?= htmlspecialchars($aw) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($unfilledWarnings)): ?>
                <div class="alert-box">
                    <strong><?= svg_icon('warning', '', 16) ?> Fehlende Stammdaten in Texten:</strong> Folgende Platzhalter werden in veröffentlichten Rechtstexten verwendet, sind aber in den Einstellungen noch leer:
                    <ul style="margin:0.5rem 0 0 1.25rem; padding:0;">
                        <?php foreach (array_slice($unfilledWarnings, 0, 3) as $w): ?>
                            <li><?= htmlspecialchars($w) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Matrix -->
            <div class="card">
                <h3 style="margin-top:0; font-size:1.2rem; font-weight:800;">Pflichtseiten & Status-Matrix</h3>
                <p style="color:#64748b; font-size:0.875rem; margin-top:0;">Klicke auf den Status zum Umschalten. Klicke auf Badges zum Bearbeiten oder auf das Pfeil-Symbol zur Live-Ansicht.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Dokumententyp</th>
                            <th>Pflichtstatus (Instant Toggle)</th>
                            <?php foreach ($activeLangs as $lang): ?>
                                <th><?= strtoupper(htmlspecialchars($lang)) ?></th>
                            <?php endforeach; ?>
                            <th style="width: 50px;"></th>
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
                            ?>
                            <tr>
                                <td>
                                    <strong style="font-size:0.95rem;"><?= htmlspecialchars($type['title']) ?></strong>
                                    <div style="font-size:0.75rem;">
                                        <a href="/<?= htmlspecialchars($type['slug']) ?>" target="_blank" style="color:#64748b; text-decoration:none; display:inline-flex; align-items:center; gap:0.25rem;" title="Direktlink in Hauptsprache">
                                            /<?= htmlspecialchars($type['slug']) ?> <?= svg_icon('external', '', 12) ?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="toggle-btn" id="toggle_btn_<?= $type['id'] ?>" onclick="ajaxToggleRequired(<?= $type['id'] ?>)" title="Klicken zum Umschalten">
                                        <?php if ($type['is_required']): ?>
                                            <span class="toggle-required" id="span_req_<?= $type['id'] ?>"><?= svg_icon('check', '', 12) ?> PFLICHTSEITE</span>
                                        <?php else: ?>
                                            <span class="toggle-optional" id="span_req_<?= $type['id'] ?>">&#9675; Optional</span>
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
                                        <div style="display:inline-flex; align-items:center; gap:0.4rem;">
                                            <?php if ($trans && $trans['status'] === 'published'): ?>
                                                <?php if ($isOutdated): ?>
                                                    <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="badge badge-outdated" title="Das deutsche Original wurde geändert!"><span class="pulse-dot dot-outdated"></span> Veraltet</a>
                                                <?php else: ?>
                                                    <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="badge badge-green" title="<?= htmlspecialchars($trans['title']) ?> - Bearbeiten"><span class="pulse-dot dot-green"></span> Live</a>
                                                <?php endif; ?>
                                                <a href="/<?= $lang ?>/<?= htmlspecialchars($trans['slug'] ?: $type['slug']) ?>" target="_blank" title="Öffentliche Seite öffnen" style="color:#2563eb; font-weight:bold; text-decoration:none; display:inline-flex; align-items:center;">
                                                    <?= svg_icon('external', '', 14) ?>
                                                </a>
                                            <?php elseif ($trans && $trans['status'] === 'draft'): ?>
                                                <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="badge badge-yellow"><span class="pulse-dot dot-yellow"></span> Entwurf</a>
                                            <?php else: ?>
                                                <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $lang ?>" class="badge badge-red">+ Erstellen</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if (!in_array($type['slug'], ['impressum', 'privacy'])): ?>
                                        <form method="post" onsubmit="return confirm('Diesen Rechtstext wirklich löschen?');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_doc_type">
                                            <input type="hidden" name="doc_type_id" value="<?= $type['id'] ?>">
                                            <button type="submit" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:1.1rem; padding:0 0.3rem;" title="Löschen">&times;</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Backup & Export Box -->
            <div class="card">
                <h3 style="margin-top:0; font-size:1.2rem; font-weight:800;">Backups & Exporte</h3>
                <p style="color:#64748b; font-size:0.875rem; margin-top:0;">Sichere deine SQLite-Datenbank oder lade alle Rechtstexte als Markdown-Archiv herunter:</p>
                <div class="action-bar">
                    <a href="/admin?action=download_backup" class="btn btn-secondary"><?= svg_icon('disk', '', 14) ?> SQLite Datenbank herunterladen</a>
                    <a href="/admin?action=export_markdown" class="btn btn-secondary"><?= svg_icon('folder', '', 14) ?> Markdown-Export</a>
                    <button type="button" class="btn btn-secondary" onclick="triggerAuditReport()"><?= svg_icon('mail', '', 14) ?> Audit-Report per E-Mail anfordern</button>
                </div>
            </div>

            <!-- Neuer Rechtstext hinzufügen -->
            <div class="card">
                <h3 style="margin-top:0; font-size:1.2rem; font-weight:800;">+ Neuen Rechtstext hinzufügen</h3>
                <p style="color:#64748b; font-size:0.875rem; margin-top:0;">Füge beliebig viele spezifische Rechtstexte hinzu (z. B. AGB B2B, AGB B2C, Sponsoring, Lizenzvereinbarung):</p>
                <form method="post">
                    <input type="hidden" name="action" value="create_doc_type">
                    <div class="grid-add">
                        <input type="text" name="doc_title" placeholder="Titel (z.B. AGB für Geschäftskunden / B2B)" required>
                        <input type="text" name="doc_slug" placeholder="URL-Slug (z.B. agb-b2b)" required>
                        <label style="display:flex; align-items:center; gap:0.4rem; font-size:0.875rem; white-space:nowrap; cursor:pointer;">
                            <input type="checkbox" name="is_required" value="1"> Pflichtseite
                        </label>
                        <button type="submit" class="btn">+ Hinzufügen</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Neues Projekt anlegen -->
        <div id="newProjectModal" class="modal-backdrop" onclick="if(event.target === this) closeNewProjectModal()">
            <div class="modal">
                <h2 style="margin-top:0; font-size:1.4rem; font-weight:800;">+ Neues Projekt anlegen</h2>
                <p style="color:#64748b; font-size:0.875rem;">Füge ein weiteres Projekt (Web-App, Landingpage, SaaS) zu deiner Paragrafy-Instanz hinzu:</p>
                
                <form method="post">
                    <input type="hidden" name="action" value="create_project">
                    
                    <label style="font-size:0.8125rem; font-weight:700; color:#475569; display:block; margin-top:1rem; margin-bottom:0.35rem;">Projektname:</label>
                    <input type="text" name="new_project_name" placeholder="z. B. InvoiceShelf Admin" required style="width:100%; box-sizing:border-box; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:8px;">

                    <label style="font-size:0.8125rem; font-weight:700; color:#475569; display:block; margin-top:1rem; margin-bottom:0.35rem;">Subdomain / Domain:</label>
                    <input type="text" name="new_project_domain" placeholder="z. B. legal.invoiceshelf.de" required style="width:100%; box-sizing:border-box; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:8px;">

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; margin-top:1rem;">
                        <div>
                            <label style="font-size:0.8125rem; font-weight:700; color:#475569; display:block; margin-bottom:0.35rem;">Primärsprache:</label>
                            <select name="new_primary_lang" style="width:100%; box-sizing:border-box; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:8px;">
                                <option value="de" selected>Deutsch (DE)</option>
                                <option value="en">Englisch (EN)</option>
                                <option value="es">Spanisch (ES)</option>
                                <option value="fr">Französisch (FR)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.8125rem; font-weight:700; color:#475569; display:block; margin-bottom:0.35rem;">Akzentfarbe:</label>
                            <input type="text" name="new_brand_color" value="#2563eb" style="width:100%; box-sizing:border-box; padding:0.65rem 0.85rem; border:1px solid #cbd5e1; border-radius:8px; font-family:monospace;">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.75rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeNewProjectModal()">Abbrechen</button>
                        <button type="submit" class="btn">Projekt erstellen &rarr;</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="toastNotification" class="toast">
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
                            span.className = 'toggle-required';
                            span.innerHTML = '✔ PFLICHTSEITE';
                            showToast('Als Pflichtseite markiert');
                        } else {
                            span.className = 'toggle-optional';
                            span.innerHTML = '○ Optional';
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
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8"><title>Einstellungen - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; margin: 0; }
            .nav { background: #090d16; color: #fff; padding: 0.85rem 1.75rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #1e293b; }
            .brand-box { display: flex; align-items: center; gap: 0.65rem; }
            .brand-box img { width: 28px; height: 28px; border-radius: 6px; }
            .nav a { color: #94a3b8; text-decoration: none; margin-left: 1.25rem; font-size: 0.9rem; font-weight: 600; }
            .container { max-width: 860px; margin: 2rem auto; padding: 0 1.5rem; }
            .card { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 2.25rem; margin-bottom: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
            label { font-size: 0.8125rem; font-weight: 700; color: #475569; display: block; margin-top: 1rem; margin-bottom: 0.35rem; }
            input[type=text], input[type=password], input[type=email], input[type=number], textarea, select { width: 100%; box-sizing: border-box; padding: 0.7rem 0.9rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; }
            .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
            .btn { background: #e11d48; color: #fff; border: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 1.5rem; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; box-shadow: 0 2px 6px rgba(225,29,72,0.2); }
            .btn:hover { background: #be123c; transform: translateY(-1px); }
            .btn-test { background: #0f172a; color: #38bdf8; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.8125rem; cursor: pointer; font-weight: 700; margin-top: 0.5rem; display: inline-flex; align-items: center; gap: 0.35rem; }
            .btn-danger { background: #7f1d1d; color: #fecaca; }
            .btn-danger:hover { background: #991b1b; }
            .hint { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; }
        </style>
    </head>
    <body>
        <div class="nav">
            <div class="brand-box">
                <img src="/paragrafy.svg" alt="Logo">
                <strong style="color:#fb7185; font-size:1.1rem;">Paragrafy</strong>
            </div>
            <div>
                <a href="/admin">Zurück zur Matrix</a>
                <a href="/admin?logout=1" style="color:#f87171;">Abmelden</a>
            </div>
        </div>

        <div class="container">
            <div class="card">
                <h2 style="margin-top:0; font-size:1.4rem; font-weight:800;">Projekt & API-Konfiguration</h2>
                <form method="post">
                    <input type="hidden" name="action" value="save_project">

                    <div class="grid">
                        <div>
                            <label>Projekt-Name:</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($project['name']) ?>" required>
                        </div>
                        <div>
                            <label>Domain / Subdomain:</label>
                            <input type="text" name="domain" value="<?= htmlspecialchars($project['domain']) ?>" required>
                        </div>
                    </div>

                    <div class="grid">
                        <div>
                            <label>Primärsprache:</label>
                            <input type="text" name="primary_lang" value="<?= htmlspecialchars($project['primary_lang']) ?>" required>
                        </div>
                        <div>
                            <label>Aktive Sprachen (kommagetrennt, z. B. de,en,es):</label>
                            <input type="text" name="active_languages" value="<?= htmlspecialchars($project['active_languages']) ?>" required>
                        </div>
                    </div>

                    <div class="grid">
                        <div>
                            <label>Akzentfarbe (HEX):</label>
                            <div style="display:flex; align-items:center; gap:0.5rem;">
                                <input type="color" id="adm_cp" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>" style="width:44px; height:40px; padding:0; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer;" oninput="document.getElementById('adm_ct').value = this.value.toUpperCase();">
                                <input type="text" id="adm_ct" name="brand_color" value="<?= htmlspecialchars($project['brand_color'] ?: '#e11d48') ?>" maxlength="7" style="width:130px; font-family:monospace; text-transform:uppercase;" oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('adm_cp').value = this.value;">
                            </div>
                        </div>
                        <div>
                            <label>Logo-URL (optional, z. B. /logo.svg oder https://...):</label>
                            <input type="text" name="logo_url" value="<?= htmlspecialchars($project['logo_url'] ?? '') ?>" placeholder="/paragrafy.svg">
                        </div>
                    </div>

                    <div class="grid">
                        <div>
                            <label>Audit-Intervall (Monate):</label>
                            <input type="number" name="audit_interval_months" value="<?= htmlspecialchars((string)($project['audit_interval_months'] ?? 12)) ?>" min="1" max="36" required>
                            <div class="hint">Warnt im Dashboard nach X Monaten vor ungeprüften Texten.</div>
                        </div>
                        <div>
                            <label style="margin-top:2.2rem; display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                                <input type="checkbox" name="cookie_banner_enabled" value="1" <?= !empty($project['cookie_banner_enabled']) ? 'checked' : '' ?> style="width:16px; height:16px;">
                                DSGVO-Cookie-Banner (/consent.js) aktivieren
                            </label>
                        </div>
                    </div>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">
                    <h3 style="margin-top:0; font-size:1.2rem; font-weight:800;">E-Mail & Audit-Benachrichtigung (SMTP)</h3>

                    <div class="grid">
                        <div>
                            <label>SMTP Host (Server):</label>
                            <input type="text" name="smtp_host" value="<?= htmlspecialchars($project['smtp_host'] ?? '') ?>" placeholder="z. B. smtp.deinedomain.de">
                        </div>
                        <div>
                            <label>SMTP Port & Verschlüsselung:</label>
                            <div style="display:flex; gap:0.5rem;">
                                <input type="number" name="smtp_port" value="<?= htmlspecialchars((string)($project['smtp_port'] ?? 587)) ?>" style="width:90px;">
                                <select name="smtp_secure">
                                    <option value="tls" <?= ($project['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                                    <option value="ssl" <?= ($project['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                                    <option value="none" <?= ($project['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>Keine (25)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="grid">
                        <div>
                            <label>SMTP Benutzername:</label>
                            <input type="text" name="smtp_user" value="<?= htmlspecialchars($project['smtp_user'] ?? '') ?>" placeholder="absender@deinedomain.de">
                        </div>
                        <div>
                            <label>SMTP Passwort:</label>
                            <input type="password" name="smtp_pass" value="<?= htmlspecialchars($project['smtp_pass'] ?? '') ?>" placeholder="Passwort eingeben">
                        </div>
                    </div>

                    <div class="grid">
                        <div>
                            <label>Absender-Adresse (From):</label>
                            <input type="email" name="smtp_from" value="<?= htmlspecialchars($project['smtp_from'] ?? '') ?>" placeholder="legal@yumder.app">
                        </div>
                        <div>
                            <label>Empfänger-Adresse für Audit-Reports:</label>
                            <input type="email" name="audit_email_recipient" value="<?= htmlspecialchars($project['audit_email_recipient'] ?? '') ?>" placeholder="deine-mail@domain.de">
                        </div>
                    </div>

                    <?php if (!empty($project['smtp_host'])): ?>
                        <button type="button" class="btn-test" onclick="triggerTestMail()"><?= svg_icon('mail', '', 14) ?> Test-E-Mail jetzt senden</button>
                    <?php endif; ?>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">
                    <h3 style="margin-top:0; font-size:1.2rem; font-weight:800;">Webhooks & DeepL Integration</h3>

                    <label>Webhook URL (POST bei Textänderungen):</label>
                    <input type="text" name="webhook_url" value="<?= htmlspecialchars($project['webhook_url'] ?? '') ?>" placeholder="https://app.yumder.de/api/legal-webhook">
                    <div class="hint">Benachrichtigt deine Hauptanwendung sofort bei Änderungen an AGB, Datenschutz etc.</div>

                    <label>Webhook Secret (optional für HMAC-Signatur):</label>
                    <input type="text" name="webhook_secret" value="<?= htmlspecialchars($project['webhook_secret'] ?? '') ?>" placeholder="z. B. ein geheimer Schlüssel">

                    <?php if (!empty($project['webhook_url'])): ?>
                        <button type="button" class="btn-test" onclick="triggerTestWebhook()"><?= svg_icon('lightning', '', 14) ?> Test-Webhook jetzt senden</button>
                    <?php endif; ?>

                    <label>DeepL API-Key:</label>
                    <input type="text" name="deepl_api_key" value="<?= htmlspecialchars($project['deepl_api_key'] ?? '') ?>" placeholder="z. B. xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx">
                    <?php if (!empty($envDeepl)): ?>
                        <div class="hint" style="color:#166534;"><?= svg_icon('check', '', 12) ?> In .env.local hinterlegt (wird automatisch als Fallback genutzt).</div>
                    <?php else: ?>
                        <div class="hint">Unterstützt Free- & Pro-Keys (z. B. <code>...:fx</code>). Ermöglicht 1-Klick-Übersetzungen im Editor.</div>
                    <?php endif; ?>

                    <hr style="margin: 2rem 0; border: none; border-top: 1px solid #e2e8f0;">
                    <h3 style="margin-top:0; font-size:1.2rem; font-weight:800;">Unternehmensdaten (Platzhalter)</h3>

                    <div class="grid">
                        <div>
                            <label>Firmenname ({{company_name}}):</label>
                            <input type="text" name="company_name" value="<?= htmlspecialchars($project['company_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Vertretungsberechtigte Person ({{representative}}):</label>
                            <input type="text" name="representative" value="<?= htmlspecialchars($project['representative'] ?? '') ?>">
                        </div>
                    </div>

                    <label>Anschrift ({{address}}):</label>
                    <textarea name="address" rows="2"><?= htmlspecialchars($project['address'] ?? '') ?></textarea>

                    <div class="grid">
                        <div>
                            <label>E-Mail-Adresse ({{email}}):</label>
                            <input type="text" name="email" value="<?= htmlspecialchars($project['email'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Telefonnummer ({{phone}}):</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($project['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <label>Register-Informationen ({{register_info}}):</label>
                    <input type="text" name="register_info" value="<?= htmlspecialchars($project['register_info'] ?? '') ?>">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2rem;">
                        <button type="submit" class="btn"><?= svg_icon('disk', '', 16) ?> Einstellungen speichern</button>
                        
                        <?php if (count($projects) > 1): ?>
                            <form method="post" onsubmit="return confirm('Möchtest du dieses Projekt (<?= htmlspecialchars($project['name']) ?>) und alle zugehörigen Texte wirklich löschen?');" style="margin:0;">
                                <input type="hidden" name="action" value="delete_project">
                                <input type="hidden" name="delete_project_id" value="<?= $project['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="margin:0;">Projekt löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <script>
            async function triggerTestWebhook() {
                const formData = new FormData();
                formData.append('action', 'test_webhook');
                try {
                    const res = await fetch(window.location.href, { method: 'POST', body: formData });
                    if (res.ok) {
                        alert('Test-Webhook erfolgreich an deine URL gesendet!');
                    } else {
                        alert('Fehler beim Senden des Webhooks.');
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
