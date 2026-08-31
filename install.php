<?php
/**
 * Paragrafy v1.5.0 - Interactive Setup & Installation Wizard
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (is_installed()) {
    header('Location: /admin');
    exit;
}

$error = null;
$host = get_current_host();

$standardTemplates = [
    'impressum' => [
        'title' => 'Impressum',
        'slug' => 'impressum',
        'is_required' => 1,
        'checked' => true,
        'content' => "<h2>Angaben gemäß § 5 DDG</h2>\n<p><strong>{{company_name}}</strong><br>{{address}}</p>\n<h3>Vertreten durch:</h3>\n<p>{{representative}}</p>\n<h3>Kontakt:</h3>\n<p>E-Mail: {{email}}<br>Telefon: {{phone}}</p>\n<h3>Registereintrag:</h3>\n<p>{{register_info}}</p>"
    ],
    'privacy' => [
        'title' => 'Datenschutzerklärung',
        'slug' => 'datenschutz',
        'is_required' => 1,
        'checked' => true,
        'content' => "<h2>1. Datenschutz auf einen Blick</h2>\n<h3>Allgemeine Hinweise</h3>\n<p>Die folgenden Hinweise geben einen einfachen Überblick darüber, was mit Ihren personenbezogenen Daten passiert, wenn Sie diese Website besuchen.</p>\n<h3>Verantwortliche Stelle:</h3>\n<p><strong>{{company_name}}</strong><br>{{address}}<br>E-Mail: {{email}}</p>\n<h2>2. Erfassung von Daten auf dieser Website</h2>\n<p>Die Datenverarbeitung auf dieser Website erfolgt durch den Websitebetreiber.</p>"
    ],
    'terms_b2c' => [
        'title' => 'AGB (Endkunden / B2C)',
        'slug' => 'agb-b2c',
        'is_required' => 0,
        'checked' => false,
        'content' => "<h2>1. Geltungsbereich für Verbraucher (B2C)</h2>\n<p>Für alle Verträge mit Verbrauchern über die Angebote von {{company_name}} gelten nachfolgende Bedingungen.</p>"
    ],
    'terms_b2b' => [
        'title' => 'AGB (Geschäftskunden / B2B)',
        'slug' => 'agb-b2b',
        'is_required' => 0,
        'checked' => false,
        'content' => "<h2>1. Geltungsbereich für Unternehmer (B2B)</h2>\n<p>Diese Geschäftsbedingungen gelten ausschließlich für Geschäftsbeziehungen mit Unternehmern, juristischen Personen des öffentlichen Rechts oder öffentlich-rechtlichen Sondervermögen.</p>"
    ],
    'cookies' => [
        'title' => 'Cookie-Richtlinie',
        'slug' => 'cookie-richtlinie',
        'is_required' => 0,
        'checked' => false,
        'content' => "<h2>Verwendung von Cookies</h2>\n<p>Unsere Website verwendet technisch notwendige Cookies, um grundlegende Funktionen zu gewährleisten.</p>"
    ],
    'revocation' => [
        'title' => 'Widerrufsbelehrung',
        'slug' => 'widerruf',
        'is_required' => 0,
        'checked' => false,
        'content' => "<h2>Widerrufsrecht</h2>\n<p>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen.</p>"
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminPass = $_POST['admin_password'] ?? '';
    $projectName = trim($_POST['project_name'] ?? '');
    $projectDomain = trim($_POST['project_domain'] ?? '');
    $primaryLang = $_POST['primary_lang'] ?? 'de';
    $selectedLangs = $_POST['active_languages'] ?? ['de'];
    $brandColor = trim($_POST['brand_color'] ?? '#e11d48');
    $deeplApiKey = trim($_POST['deepl_api_key'] ?? '');
    if (!str_starts_with($brandColor, '#')) {
        $brandColor = '#' . $brandColor;
    }

    $companyName = trim($_POST['company_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $representative = trim($_POST['representative'] ?? '');
    $registerInfo = trim($_POST['register_info'] ?? '');

    $selectedPages = $_POST['pages'] ?? [];
    $customTitles = $_POST['custom_title'] ?? [];
    $customSlugs = $_POST['custom_slug'] ?? [];
    $customRequired = $_POST['custom_required'] ?? [];

    if (strlen($adminPass) < 6) {
        $error = "Das Admin-Passwort muss mindestens 6 Zeichen lang sein.";
    } elseif (empty($projectName) || empty($projectDomain)) {
        $error = "Bitte Projektnamen und Domain angeben.";
    } else {
        try {
            $pdo = get_db();
            init_database_schema($pdo);

            write_config([
                'admin_password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
                'installed_at' => date('c'),
                'cron_secret' => bin2hex(random_bytes(32)),
            ]);

            $docTypeIds = [];
            foreach ($standardTemplates as $slugKey => $tpl) {
                $isRequired = in_array($slugKey, ['impressum', 'privacy']) ? 1 : (isset($selectedPages[$slugKey]) ? 0 : 0);
                $stmt = $pdo->prepare("INSERT INTO doc_types (slug, title, is_required) VALUES (?, ?, ?)");
                $stmt->execute([$tpl['slug'], $tpl['title'], $isRequired]);
                $docTypeIds[$slugKey] = (int)$pdo->lastInsertId();
            }

            $customCreatedIds = [];
            if (!empty($customTitles) && is_array($customTitles)) {
                foreach ($customTitles as $idx => $cTitle) {
                    $cTitle = trim((string)$cTitle);
                    if ($cTitle === '') continue;
                    $cSlug = trim((string)($customSlugs[$idx] ?? ''));
                    if ($cSlug === '') {
                        $cSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $cTitle));
                    }
                    $cIsReq = !empty($customRequired[$idx]) ? 1 : 0;

                    $stmt = $pdo->prepare("INSERT INTO doc_types (slug, title, is_required) VALUES (?, ?, ?)");
                    $stmt->execute([$cSlug, $cTitle, $cIsReq]);
                    $customCreatedIds[] = [
                        'id' => (int)$pdo->lastInsertId(),
                        'title' => $cTitle,
                        'slug' => $cSlug,
                        'is_required' => $cIsReq
                    ];
                }
            }

            $activeLanguagesStr = implode(',', array_unique(array_merge([$primaryLang], $selectedLangs)));
            $stmt = $pdo->prepare("
                INSERT INTO projects (domain, name, primary_lang, active_languages, brand_color, deepl_api_key, company_name, address, email, phone, representative, register_info)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $projectDomain, $projectName, $primaryLang, $activeLanguagesStr, $brandColor, $deeplApiKey,
                $companyName, $address, $email, $phone, $representative, $registerInfo
            ]);
            $projectId = (int)$pdo->lastInsertId();

            foreach ($selectedPages as $pageKey) {
                if (isset($standardTemplates[$pageKey]) && isset($docTypeIds[$pageKey])) {
                    $docTypeId = $docTypeIds[$pageKey];
                    $tpl = $standardTemplates[$pageKey];

                    $docStmt = $pdo->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                    $docStmt->execute([$projectId, $docTypeId]);
                    $documentId = (int)$pdo->lastInsertId();

                    $transStmt = $pdo->prepare("
                        INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, source_hash)
                        VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
                    ");
                    $transStmt->execute([
                        $documentId,
                        $primaryLang,
                        $tpl['title'],
                        $tpl['slug'],
                        $tpl['content'],
                        $tpl['content'],
                        md5($tpl['content'])
                    ]);
                }
            }

            foreach ($customCreatedIds as $cDoc) {
                $docStmt = $pdo->prepare("INSERT INTO documents (project_id, doc_type_id) VALUES (?, ?)");
                $docStmt->execute([$projectId, $cDoc['id']]);
                $documentId = (int)$pdo->lastInsertId();

                $defaultContent = "<h2>" . htmlspecialchars($cDoc['title']) . "</h2>\n<p>Hier den Inhalt für {{company_name}} einfügen.</p>";
                $transStmt = $pdo->prepare("
                    INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, source_hash)
                    VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
                ");
                $transStmt->execute([
                    $documentId,
                    $primaryLang,
                    $cDoc['title'],
                    $cDoc['slug'],
                    $defaultContent,
                    $defaultContent,
                    md5($defaultContent)
                ]);
            }

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['paragrafy_admin'] = true;
            header('Location: /admin');
            exit;
        } catch (Throwable $e) {
            $error = "Fehler bei der Installation: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Paragrafy Setup & Installation</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
    <style>
        :root { --primary: #e11d48; --bg: #090d16; --card: #131b2e; --text: #f8fafc; --muted: #94a3b8; --border: #1e293b; --input-bg: #0b1120; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 2.5rem 1rem; line-height: 1.5; }
        .wizard-container { max-width: 820px; margin: 0 auto; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 2.5rem; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
        .logo-header { display: flex; align-items: center; gap: 0.85rem; margin-bottom: 0.5rem; }
        .logo-header img { width: 38px; height: 38px; border-radius: 10px; box-shadow: 0 4px 12px rgba(225,29,72,0.3); }
        h1 { font-size: 1.85rem; margin: 0; color: #fff; font-weight: 800; letter-spacing: -0.02em; }
        .subtitle { color: var(--muted); margin-bottom: 2rem; font-size: 0.95rem; }
        .section { background: rgba(0,0,0,0.25); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .section-title { font-size: 1.05rem; font-weight: 700; margin-top: 0; margin-bottom: 1rem; color: #fb7185; display: flex; align-items: center; gap: 0.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 600; color: #cbd5e1; margin-bottom: 0.35rem; }
        input[type=text], input[type=password], input[type=email], textarea, select { width: 100%; box-sizing: border-box; background: var(--input-bg); border: 1px solid var(--border); border-radius: 8px; padding: 0.7rem 0.9rem; color: #fff; font-size: 0.9rem; transition: all 0.2s; }
        input:focus, textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(225,29,72,0.2); }
        .checkbox-group { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 0.5rem; }
        .checkbox-card { display: flex; align-items: center; gap: 0.6rem; background: var(--input-bg); padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .checkbox-card:hover { border-color: #475569; }
        .checkbox-card input { cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary); }
        .color-picker-wrap { display: flex; align-items: center; gap: 0.5rem; }
        .color-picker-wrap input[type=color] { width: 44px; height: 40px; padding: 0; border: 1px solid var(--border); border-radius: 8px; background: transparent; cursor: pointer; }
        .color-picker-wrap input[type=text] { width: 130px; text-transform: uppercase; font-family: monospace; }
        .btn { width: 100%; background: var(--primary); color: #fff; border: none; padding: 0.9rem 1.5rem; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s; margin-top: 1rem; box-shadow: 0 4px 15px rgba(225,29,72,0.3); }
        .btn:hover { background: #be123c; transform: translateY(-1px); }
        .btn-add { background: #1e293b; color: #f8fafc; border: 1px solid var(--border); padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 600; cursor: pointer; margin-top: 0.75rem; transition: all 0.2s; }
        .btn-add:hover { background: #334155; }
        .custom-row { display: grid; grid-template-columns: 1.5fr 1fr auto auto; gap: 0.5rem; align-items: center; background: var(--input-bg); padding: 0.6rem; border: 1px solid var(--border); border-radius: 8px; margin-top: 0.5rem; }
        .custom-row input { margin: 0; }
        .btn-del { background: #7f1d1d; color: #fecaca; border: none; padding: 0.4rem 0.65rem; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .error-box { background: #7f1d1d; border: 1px solid #dc2626; color: #fecaca; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="wizard-container">
        <div class="logo-header">
            <img src="/paragrafy.svg" alt="Paragrafy Logo">
            <h1>Paragrafy Setup</h1>
        </div>
        <div class="subtitle">Zentrale Verwaltung für rechtliche Pflichttexte, Mehrsprachigkeit & Headless API.</div>

        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="section">
                <div class="section-title">1. Administrator-Zugang</div>
                <label>Admin-Passwort:</label>
                <input type="password" name="admin_password" placeholder="Sicheres Passwort für das /admin Dashboard" required autofocus>
            </div>

            <div class="section">
                <div class="section-title">2. Projekt, Branding & DeepL</div>
                <div class="grid">
                    <div>
                        <label>Projektname:</label>
                        <input type="text" name="project_name" placeholder="z. B. Meine App" required>
                    </div>
                    <div>
                        <label>Subdomain / Domain:</label>
                        <input type="text" name="project_domain" value="<?= htmlspecialchars($host) ?>" required>
                    </div>
                </div>
                <div class="grid" style="margin-top: 1rem;">
                    <div>
                        <label>Primärsprache:</label>
                        <select name="primary_lang">
                            <option value="de" selected>Deutsch (DE)</option>
                            <option value="en">Englisch (EN)</option>
                        </select>
                    </div>
                    <div>
                        <label>Akzentfarbe (HEX):</label>
                        <div class="color-picker-wrap">
                            <input type="color" id="color_picker" value="#e11d48" oninput="syncColor(this.value, 'text')">
                            <input type="text" id="color_text" name="brand_color" value="#e11d48" placeholder="#E11D48" maxlength="7" oninput="syncColor(this.value, 'picker')">
                        </div>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <label>DeepL API-Key (optional, für 1-Klick-Übersetzungen):</label>
                    <input type="text" name="deepl_api_key" placeholder="z. B. xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx">
                </div>

                <div style="margin-top: 1rem;">
                    <label>Aktivierte Sprachen für Übersetzungen:</label>
                    <div class="checkbox-group">
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="de" checked disabled> Deutsch (DE - Basis)</label>
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="en" checked> Englisch (EN)</label>
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="es"> Spanisch (ES)</label>
                        <label class="checkbox-card"><input type="checkbox" name="active_languages[]" value="fr"> Französisch (FR)</label>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">3. Erforderliche Rechtstexte & Vorlagen</div>
                <p style="color: var(--muted); font-size: 0.8125rem; margin-top: 0;">Wähle die Basis-Dokumente aus oder erstelle beliebig viele eigene:</p>

                <div class="checkbox-group">
                    <?php foreach ($standardTemplates as $k => $t): ?>
                        <label class="checkbox-card">
                            <input type="checkbox" name="pages[]" value="<?= $k ?>" <?= $t['checked'] ? 'checked' : '' ?>>
                            <?= htmlspecialchars($t['title']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 1.5rem;">
                    <label>Eigene zusätzliche Rechtstexte definieren:</label>
                    <div id="custom_docs_container"></div>
                    <button type="button" class="btn-add" onclick="addCustomDoc()">+ Weiteren Rechtstext hinzufügen</button>
                </div>
            </div>

            <div class="section">
                <div class="section-title">4. Unternehmensangaben (Platzhalter)</div>
                <div class="grid">
                    <div>
                        <label>Firmenname / Inhaber:</label>
                        <input type="text" name="company_name" placeholder="z. B. Max Mustermann Webentwicklung">
                    </div>
                    <div>
                        <label>Vertretungsberechtigte Person:</label>
                        <input type="text" name="representative" placeholder="z. B. Max Mustermann">
                    </div>
                </div>
                <div style="margin-top: 1rem;">
                    <label>Vollständige Anschrift:</label>
                    <textarea name="address" rows="2" placeholder="Musterweg 1&#10;12345 Musterstadt, Deutschland"></textarea>
                </div>
                <div class="grid" style="margin-top: 1rem;">
                    <div>
                        <label>Kontakt-E-Mail:</label>
                        <input type="email" name="email" placeholder="kontakt@deinedomain.de">
                    </div>
                    <div>
                        <label>Telefonnummer (optional):</label>
                        <input type="text" name="phone" placeholder="+49 123 456789">
                    </div>
                </div>
                <div style="margin-top: 1rem;">
                    <label>Registergericht & Nummer (falls vorhanden):</label>
                    <input type="text" name="register_info" placeholder="Amtsgericht Musterstadt, HRB 12345">
                </div>
            </div>

            <button type="submit" class="btn">Paragrafy initialisieren & Dashboard öffnen &rarr;</button>
        </form>
    </div>

    <script>
        function syncColor(val, target) {
            if (target === 'text') {
                document.getElementById('color_text').value = val.toUpperCase();
            } else if (target === 'picker') {
                let hex = val.trim();
                if (!hex.startsWith('#')) hex = '#' + hex;
                if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
                    document.getElementById('color_picker').value = hex;
                }
            }
        }

        let customIdx = 0;
        function addCustomDoc(title = '', slug = '', isReq = false) {
            const container = document.getElementById('custom_docs_container');
            const div = document.createElement('div');
            div.className = 'custom-row';
            div.id = 'crow_' + customIdx;
            div.innerHTML = `
                <input type="text" name="custom_title[]" value="${title}" placeholder="Titel (z.B. AGB B2B)" required oninput="autoSlug(this, ${customIdx})">
                <input type="text" name="custom_slug[]" id="cslug_${customIdx}" value="${slug}" placeholder="URL-Slug (z.B. agb-b2b)" required>
                <label style="display:flex; align-items:center; gap:0.3rem; margin:0; font-size:0.75rem; white-space:nowrap; cursor:pointer;">
                    <input type="checkbox" name="custom_required[${customIdx}]" value="1" ${isReq ? 'checked' : ''} style="width:14px; height:14px;"> Pflichtseite
                </label>
                <button type="button" class="btn-del" onclick="document.getElementById('crow_${customIdx}').remove()">&times;</button>
            `;
            container.appendChild(div);
            customIdx++;
        }

        function autoSlug(input, idx) {
            const slugField = document.getElementById('cslug_' + idx);
            if (!slugField.dataset.customized) {
                slugField.value = input.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            }
        }
    </script>
</body>
</html>
