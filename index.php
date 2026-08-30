<?php
/**
 * Paragrafy v1.5.1 - Public Router, Document Viewer, Embed Drawer, JSON API & Cron Audit
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!is_installed()) {
    require_once __DIR__ . '/install.php';
    exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri, '/');
if ($uri === '') $uri = '/';

if ($uri === '/paragrafy.svg') {
    header('Content-Type: image/svg+xml');
    if (file_exists(__DIR__ . '/paragrafy.svg')) {
        readfile(__DIR__ . '/paragrafy.svg');
    }
    exit;
}

if (str_starts_with($uri, '/admin')) {
    require_once __DIR__ . '/admin.php';
    exit;
}

$host = get_current_host();
$db = get_db();

$stmt = $db->prepare("SELECT * FROM projects WHERE domain = ? OR domain = 'localhost' ORDER BY (domain = ?) DESC LIMIT 1");
$stmt->execute([$host, $host]);
$project = $stmt->fetch();

if ($uri === '/embed.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    render_embed_js();
    exit;
}

if ($uri === '/consent.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    render_consent_js($project ?: null);
    exit;
}

if (!$project) {
    http_response_code(404);
    echo "<h1>404 - Kein Projekt hinterlegt</h1><p>Für die Domain <code>" . htmlspecialchars($host) . "</code> ist kein Legal-Projekt eingerichtet. Bitte im <a href='/admin'>Admin-Panel</a> anlegen.</p>";
    exit;
}

$parts = array_values(array_filter(explode('/', $uri)));
$primaryLang = $project['primary_lang'] ?: 'de';
$activeLangs = array_filter(array_map('trim', explode(',', $project['active_languages'] ?? 'de,en')));

// Cron Audit Endpoint (/api/cron/audit)
if (!empty($parts) && $parts[0] === 'api' && ($parts[1] ?? '') === 'cron' && ($parts[2] ?? '') === 'audit') {
    handle_cron_audit($project, $db);
    exit;
}

// JSON API Endpoint (/api/de/impressum oder /api/impressum)
if (!empty($parts) && $parts[0] === 'api') {
    handle_json_api($parts, $project, $db, $primaryLang);
    exit;
}

if (empty($parts)) {
    render_public_overview($project, $db, $primaryLang);
    exit;
}

if (count($parts) === 1) {
    $first = strtolower($parts[0]);
    if (in_array($first, $activeLangs) || (strlen($first) === 2 && !is_numeric($first))) {
        render_public_overview($project, $db, $first);
        exit;
    }
    $lang = $primaryLang;
    $slug = $first;
} else {
    $lang = strtolower($parts[0]);
    $slug = strtolower($parts[1]);
}

$stmt = $db->prepare("
    SELECT t.*, d.doc_type_id, dt.title AS default_title, dt.slug AS default_slug
    FROM translations t
    JOIN documents d ON t.document_id = d.id
    JOIN doc_types dt ON d.doc_type_id = dt.id
    WHERE d.project_id = ? AND t.lang = ? AND (t.slug = ? OR dt.slug = ?) AND t.status = 'published'
    LIMIT 1
");
$stmt->execute([$project['id'], $lang, $slug, $slug]);
$trans = $stmt->fetch();

if (!$trans) {
    http_response_code(404);
    render_public_404($project, $lang);
    exit;
}

$stmt = $db->prepare("
    SELECT t.lang, t.slug, dt.slug as default_slug
    FROM translations t
    JOIN documents d ON t.document_id = d.id
    JOIN doc_types dt ON d.doc_type_id = dt.id
    WHERE t.document_id = ? AND t.status = 'published'
");
$stmt->execute([$trans['document_id']]);
$languages = $stmt->fetchAll();

$content = replace_placeholders($trans['content'], $project);
render_public_document($project, $trans, $content, $languages, $lang);

function get_i18n_strings(string $lang): array {
    $dict = [
        'de' => [
            'toc' => 'Inhaltsverzeichnis',
            'search_ph' => 'Dokument durchsuchen (z. B. Cookies, Haftung, Widerruf)...',
            'read_time' => '~%d Min. Lesezeit',
            'last_updated' => 'Stand: %s',
            'print' => 'Drucken',
            'overview_title' => 'Rechtliche Dokumente',
            'overview_sub' => 'Rechtliche Hinweise und Bestimmungen.',
            'not_found_title' => 'Dokument nicht gefunden',
            'not_found_desc' => 'Der angeforderte Rechtstext existiert nicht oder wurde noch nicht freigegeben.',
            'back_to_overview' => 'Zurück zur Übersicht',
            'copy_anchor' => 'Direktlink kopieren'
        ],
        'en' => [
            'toc' => 'Table of Contents',
            'search_ph' => 'Search document (e.g. cookies, liability, revocation)...',
            'read_time' => '~%d min read',
            'last_updated' => 'Last updated: %s',
            'print' => 'Print',
            'overview_title' => 'Legal Documents',
            'overview_sub' => 'Legal notices, policies and terms.',
            'not_found_title' => 'Document not found',
            'not_found_desc' => 'The requested legal document does not exist or has not been published yet.',
            'back_to_overview' => 'Back to Overview',
            'copy_anchor' => 'Copy section link'
        ],
        'es' => [
            'toc' => 'Índice de contenidos',
            'search_ph' => 'Buscar en el documento (ej. cookies, responsabilidad)...',
            'read_time' => '~%d min de lectura',
            'last_updated' => 'Última actualización: %s',
            'print' => 'Imprimir',
            'overview_title' => 'Documentos legales',
            'overview_sub' => 'Avisos legales, términos y políticas.',
            'not_found_title' => 'Documento no encontrado',
            'not_found_desc' => 'El documento legal solicitado no existe o aún no ha sido publicado.',
            'back_to_overview' => 'Volver a la vista general',
            'copy_anchor' => 'Copiar enlace'
        ],
        'fr' => [
            'toc' => 'Table des matières',
            'search_ph' => 'Rechercher dans le document (ex. cookies, responsabilité)...',
            'read_time' => '~%d min de lecture',
            'last_updated' => 'Dernière mise à jour: %s',
            'print' => 'Imprimer',
            'overview_title' => 'Documents juridiques',
            'overview_sub' => 'Mentions légales, conditions et politiques.',
            'not_found_title' => 'Document non trouvé',
            'not_found_desc' => 'Le document juridique demandé n\'existe pas ou n\'a pas encore été publié.',
            'back_to_overview' => 'Retour à la vue d\'ensemble',
            'copy_anchor' => 'Copier le lien'
        ],
        'it' => [
            'toc' => 'Indice dei contenuti',
            'search_ph' => 'Cerca nel documento (es. cookie, responsabilità)...',
            'read_time' => '~%d min di lettura',
            'last_updated' => 'Ultimo aggiornamento: %s',
            'print' => 'Stampa',
            'overview_title' => 'Documenti legali',
            'overview_sub' => 'Note legali, termini e informative.',
            'not_found_title' => 'Documento non trovato',
            'not_found_desc' => 'Il documento legale richiesto non esiste o non è ancora stato pubblicato.',
            'back_to_overview' => 'Torna alla panoramica',
            'copy_anchor' => 'Copia link'
        ],
        'nl' => [
            'toc' => 'Inhoudsopgave',
            'search_ph' => 'Document doorzoeken (bijv. cookies, aansprakelijkheid)...',
            'read_time' => '~%d min leestijd',
            'last_updated' => 'Laatst bijgewerkt: %s',
            'print' => 'Afdrukken',
            'overview_title' => 'Juridische documenten',
            'overview_sub' => 'Juridische kennisgevingen en voorwaarden.',
            'not_found_title' => 'Document niet gevonden',
            'not_found_desc' => 'Het opgevraagde juridische document bestaat niet of is nog niet gepubliceerd.',
            'back_to_overview' => 'Terug naar het overzicht',
            'copy_anchor' => 'Kopieer link'
        ]
    ];
    return $dict[$lang] ?? $dict['en'];
}

function handle_cron_audit(array $project, PDO $db): void {
    header('Content-Type: application/json');
    $recipient = trim($project['audit_email_recipient'] ?? '') ?: ($project['email'] ?? '');
    if (empty($recipient)) {
        echo json_encode(['success' => false, 'error' => 'Keine Audit-Empfänger E-Mail hinterlegt.']);
        return;
    }

    $auditMonths = (int)($project['audit_interval_months'] ?? 12);
    $stmt = $db->prepare("
        SELECT t.title, t.lang, t.updated_at, dt.title as type_title
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE d.project_id = ? AND t.status = 'published'
    ");
    $stmt->execute([$project['id']]);
    $docs = $stmt->fetchAll();

    $now = new DateTime();
    $overdue = [];
    foreach ($docs as $d) {
        if (!empty($d['updated_at'])) {
            $updated = new DateTime($d['updated_at']);
            $diffMonths = (($now->format('Y') - $updated->format('Y')) * 12) + ($now->format('m') - $updated->format('m'));
            if ($diffMonths >= $auditMonths) {
                $days = $now->diff($updated)->days;
                $overdue[] = "<li><strong>" . htmlspecialchars($d['title']) . "</strong> (" . strtoupper($d['lang']) . ") - Zuletzt geprüft vor $days Tagen (Stand: " . date('d.m.Y', strtotime($d['updated_at'])) . ")</li>";
            }
        }
    }

    if (empty($overdue)) {
        echo json_encode(['success' => true, 'message' => 'Alle Rechtstexte sind aktuell. Keine E-Mail erforderlich.']);
        return;
    }

    $html = "<h2>Paragrafy Compliance-Audit: Prüfung fällig</h2>";
    $html .= "<p>Für dein Projekt <strong>" . htmlspecialchars($project['name']) . "</strong> (" . htmlspecialchars($project['domain']) . ") sind folgende Rechtstexte seit mehr als $auditMonths Monaten ungeprüft:</p>";
    $html .= "<ul>" . implode('', $overdue) . "</ul>";
    $html .= "<p><a href='https://" . htmlspecialchars($project['domain']) . "/admin' style='background:#e11d48;color:#fff;padding:0.6rem 1.2rem;border-radius:6px;text-decoration:none;display:inline-block;font-weight:bold;'>Zum Admin-Dashboard</a></p>";

    $res = send_smtp_mail($project, $recipient, "[Paragrafy] Audit-Erinnerung: " . count($overdue) . " Rechtstexte prüfen", $html);
    echo json_encode($res);
}

function handle_json_api(array $parts, array $project, PDO $db, string $primaryLang): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $lang = $primaryLang;
    $slug = '';

    if (count($parts) === 2) {
        $slug = strtolower($parts[1]);
    } elseif (count($parts) >= 3) {
        $lang = strtolower($parts[1]);
        $slug = strtolower($parts[2]);
    }

    if (empty($slug)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing document slug']);
        return;
    }

    $stmt = $db->prepare("
        SELECT t.title, t.slug, t.lang, t.content, t.updated_at, t.change_note
        FROM translations t
        JOIN documents d ON t.document_id = d.id
        JOIN doc_types dt ON d.doc_type_id = dt.id
        WHERE d.project_id = ? AND t.lang = ? AND (t.slug = ? OR dt.slug = ?) AND t.status = 'published'
        LIMIT 1
    ");
    $stmt->execute([$project['id'], $lang, $slug, $slug]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['error' => 'Document not found or not published']);
        return;
    }

    $doc['rendered_html'] = replace_placeholders($doc['content'], $project);
    echo json_encode([
        'project' => $project['name'],
        'title' => $doc['title'],
        'slug' => $doc['slug'],
        'lang' => $doc['lang'],
        'updated_at' => $doc['updated_at'],
        'change_note' => $doc['change_note'],
        'html' => $doc['rendered_html']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function render_public_document(array $project, array $trans, string $content, array $languages, string $currentLang): void {
    $brand = htmlspecialchars($project['brand_color'] ?: '#e11d48');
    $logoUrl = $project['logo_url'] ?: '/paragrafy.svg';
    $i18n = get_i18n_strings($currentLang);

    $wordCount = str_word_count(strip_tags($content));
    $readMinutes = max(1, (int)ceil($wordCount / 200));
    $readTimeStr = sprintf($i18n['read_time'], $readMinutes);
    $lastUpdatedStr = sprintf($i18n['last_updated'], date('d.m.Y', strtotime($trans['updated_at'])));
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($currentLang) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($trans['title']) ?> - <?= htmlspecialchars($project['name']) ?></title>
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($logoUrl) ?>">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
        <style>
            body { line-height: 1.7; }
            .hero { background: #17141b; padding: 24px 40px 40px; }
            .hero-inner { max-width: 1160px; margin: 0 auto; }
            .hero-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 1rem; }
            .brand-wrap { display: flex; align-items: center; gap: 10px; text-decoration: none; }
            .brand-wrap img { width: 30px; height: 30px; border-radius: 7px; }
            .brand-name { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 17px; color: #fff; }

            .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
            .lang-switch a { text-decoration: none; padding: 6px 14px; border-radius: 7px; font-size: 12px; border: 1px solid rgba(255,255,255,0.16); color: rgba(255,255,255,0.6); font-weight: 700; }
            .lang-switch a.active { background: var(--accent); color: #fff; border-color: var(--accent); }
            .btn-action { background: transparent; border: 1px solid rgba(255,255,255,0.16); color: rgba(255,255,255,0.75); padding: 6px 14px; border-radius: 7px; font-size: 12px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; font-family: 'IBM Plex Sans', sans-serif; }
            .btn-action:hover { border-color: rgba(255,255,255,0.4); color: #fff; }

            h1 { font-size: 32px; margin: 0 0 14px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.2; color: #fff; }
            .meta-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; font-size: 12px; }
            .meta-pill { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.75); padding: 6px 12px; border-radius: 20px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }

            .search-box { position: relative; }
            .search-box input { width: 100%; box-sizing: border-box; padding: 12px 16px 12px 40px; border: 1px solid rgba(255,255,255,0.14); border-radius: 8px; background: rgba(255,255,255,0.06); color: #fff; font-size: 13px; outline: none; font-family: 'IBM Plex Sans', sans-serif; }
            .search-box input::placeholder { color: rgba(255,255,255,0.4); }
            .search-box input:focus { border-color: var(--accent); }
            .search-box svg { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.4); pointer-events: none; }

            .wrapper { max-width: 1160px; margin: 0 auto; padding: 36px 40px 90px; display: grid; grid-template-columns: 1fr 280px; gap: 48px; align-items: start; }
            @media (max-width: 980px) { .wrapper { grid-template-columns: 1fr; } .toc-sidebar { display: none; } .hero { padding: 20px 20px 28px; } .wrapper { padding: 28px 20px 60px; } }

            .content { font-size: 14.5px; color: var(--text); }
            .content h1, .content h2, .content h3 { scroll-margin-top: 2rem; position: relative; }
            .content h1 { font-size: 26px; font-weight: 800; margin-top: 2.25rem; margin-bottom: 0.75rem; }
            .content h2 { font-size: 22px; font-weight: 700; margin-top: 2.25rem; margin-bottom: 0.75rem; }
            .content h3 { font-size: 16px; font-weight: 700; margin-top: 1.75rem; margin-bottom: 0.5rem; }
            .content a { text-decoration: underline; }
            .content p { margin: 1rem 0; }

            .anchor-link { opacity: 0; margin-left: 0.5rem; text-decoration: none !important; color: var(--text-faint) !important; font-weight: normal; transition: opacity 0.2s; font-size: 0.9em; }
            .content h1:hover .anchor-link, .content h2:hover .anchor-link, .content h3:hover .anchor-link { opacity: 1; }

            .toc-sidebar { position: sticky; top: 24px; border-left: 1px solid var(--border); padding-left: 24px; }
            .toc-title { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-faint); letter-spacing: 0.06em; margin-bottom: 14px; }
            .toc-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 11px; max-height: calc(100vh - 12rem); overflow-y: auto; }
            .toc-link { display: block; font-size: 13px; color: var(--text-muted); text-decoration: none; border-left: 2px solid transparent; padding-left: 10px; margin-left: -11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .toc-link:hover { color: var(--text); }
            .toc-link.active { color: var(--accent); font-weight: 600; border-left-color: var(--accent); }
            .toc-brand { display: flex; align-items: center; gap: 6px; margin-top: 28px; font-size: 12px; color: var(--text-faint); font-weight: 500; }
            .toc-brand span { width: 15px; height: 15px; border-radius: 4px; background: var(--accent); display: inline-flex; align-items: center; justify-content: center; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 9px; color: #fff; }

            footer { text-align: center; padding-bottom: 2rem; font-size: 12px; color: var(--text-faint); }
            @media print { .header-actions, .toc-sidebar, .search-box, .anchor-link, .hero a, footer { display: none; } .hero { background: #fff; padding: 0; } h1, .brand-name { color: #000; } .wrapper { display: block; padding: 0; } body { background: #fff; color: #000; } }
        </style>
    </head>
    <body>
        <div class="hero">
            <div class="hero-inner">
                <div class="hero-top">
                    <a href="/<?= htmlspecialchars($currentLang) ?>" class="brand-wrap">
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                        <span class="brand-name"><?= htmlspecialchars($project['name']) ?></span>
                    </a>
                    <div class="header-actions">
                        <div class="lang-switch">
                            <?php foreach ($languages as $l): ?>
                                <a href="/<?= htmlspecialchars($l['lang']) ?>/<?= htmlspecialchars($l['slug'] ?: $l['default_slug']) ?>" class="<?= $l['lang'] === $currentLang ? 'active' : '' ?>">
                                    <?= strtoupper(htmlspecialchars($l['lang'])) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <button onclick="window.print()" class="btn-action" title="Drucken oder als PDF speichern">
                            <?= svg_icon('print', '', 14) ?> <?= htmlspecialchars($i18n['print']) ?>
                        </button>
                    </div>
                </div>

                <h1><?= htmlspecialchars($trans['title']) ?></h1>
                <div class="meta-bar">
                    <span class="meta-pill"><?= svg_icon('clock', '', 13) ?> <?= htmlspecialchars($lastUpdatedStr) ?></span>
                    <span class="meta-pill"><?= svg_icon('eye', '', 13) ?> <?= htmlspecialchars($readTimeStr) ?></span>
                    <?php if (!empty($trans['change_note'])): ?>
                        <span class="meta-pill"><?= svg_icon('edit', '', 13) ?> <?= htmlspecialchars($trans['change_note']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="search-box">
                    <?= svg_icon('search', '', 16) ?>
                    <input type="text" id="docSearchInput" placeholder="<?= htmlspecialchars($i18n['search_ph']) ?>" oninput="filterDocumentText(this.value)">
                </div>
            </div>
        </div>

        <div class="wrapper">
            <main>
                <div class="content" id="documentContent">
                    <?= $content ?>
                </div>
            </main>

            <aside class="toc-sidebar">
                <div class="toc-title"><?= htmlspecialchars($i18n['toc']) ?></div>
                <ul class="toc-list" id="tocList" style="list-style:none;padding:0;margin:0"></ul>
                <div class="toc-brand"><span>§</span> Bereitgestellt mit Paragrafy</div>
            </aside>
        </div>

        <footer>
            &copy; <?= date('Y') ?> <?= htmlspecialchars($project['company_name'] ?: $project['name']) ?> &bull; Bereitgestellt über Paragrafy
        </footer>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const content = document.getElementById('documentContent');
                const headings = content.querySelectorAll('h1, h2, h3');
                const tocList = document.getElementById('tocList');

                if (headings.length === 0) {
                    document.querySelector('.toc-sidebar').style.display = 'none';
                    document.querySelector('.wrapper').style.gridTemplateColumns = '1fr';
                    return;
                }

                headings.forEach((h, idx) => {
                    const id = 'section-' + idx;
                    h.id = id;

                    const anchor = document.createElement('a');
                    anchor.className = 'anchor-link';
                    anchor.href = '#' + id;
                    anchor.innerHTML = '#';
                    anchor.title = '<?= htmlspecialchars($i18n['copy_anchor']) ?>';
                    anchor.onclick = (e) => {
                        e.preventDefault();
                        navigator.clipboard.writeText(window.location.origin + window.location.pathname + '#' + id);
                        window.location.hash = id;
                    };
                    h.appendChild(anchor);

                    const li = document.createElement('li');
                    li.className = 'toc-item';
                    const link = document.createElement('a');
                    link.className = 'toc-link' + (h.tagName === 'H3' ? ' toc-sub' : '');
                    link.href = '#' + id;
                    link.innerText = h.childNodes[0].nodeValue.trim();
                    link.dataset.target = id;
                    li.appendChild(link);
                    tocList.appendChild(li);
                });

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            document.querySelectorAll('.toc-link').forEach(l => l.classList.remove('active'));
                            const activeLink = document.querySelector(`.toc-link[data-target="${entry.target.id}"]`);
                            if (activeLink) activeLink.classList.add('active');
                        }
                    });
                }, { rootMargin: '0px 0px -70% 0px' });

                headings.forEach(h => observer.observe(h));
            });

            function filterDocumentText(term) {
                const paragraphs = document.querySelectorAll('#documentContent p, #documentContent li, #documentContent h1, #documentContent h2, #documentContent h3');
                const query = term.toLowerCase().trim();
                paragraphs.forEach(p => {
                    if (query === '' || p.innerText.toLowerCase().includes(query)) {
                        p.style.opacity = '1';
                        p.style.display = '';
                    } else {
                        p.style.opacity = '0.15';
                    }
                });
            }
        </script>
    </body>
    </html>
    <?php
}

function render_public_overview(array $project, PDO $db, string $lang): void {
    $brand = htmlspecialchars($project['brand_color'] ?: '#e11d48');
    $logoUrl = $project['logo_url'] ?: '/paragrafy.svg';
    $i18n = get_i18n_strings($lang);

    $stmt = $db->prepare("
        SELECT t.title, t.slug, dt.slug as default_slug, dt.title as fallback_title
        FROM documents d
        JOIN doc_types dt ON d.doc_type_id = dt.id
        LEFT JOIN translations t ON d.id = t.document_id AND t.lang = ? AND t.status = 'published'
        WHERE d.project_id = ?
    ");
    $stmt->execute([$lang, $project['id']]);
    $docs = $stmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($lang) ?>">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($i18n['overview_title']) ?> - <?= htmlspecialchars($project['name']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($logoUrl) ?>">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
        <style>
            body { display: flex; align-items: center; justify-content: center; padding: 70px 20px; }
            .box { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 36px; max-width: 460px; width: 100%; box-shadow: 0 2px 12px rgba(0,0,0,.04); }
            .head-logo { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
            .head-logo img { width: 32px; height: 32px; border-radius: 8px; }
            .head-logo span { font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 19px; letter-spacing: -0.01em; }
            ul { list-style: none; padding: 0; margin: 20px 0 0; display: flex; flex-direction: column; gap: 10px; }
            li a { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border: 1px solid var(--border); border-radius: 10px; color: var(--text); text-decoration: none; font-weight: 600; font-size: 14px; }
            li a:hover { border-color: var(--accent); }
            li a span:last-child { color: var(--text-faint); }
            .toc-brand { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 14px; font-size: 12px; color: var(--text-faint); font-weight: 500; }
            .toc-brand span.badge { width: 15px; height: 15px; border-radius: 4px; background: var(--accent); display: inline-flex; align-items: center; justify-content: center; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 800; font-size: 9px; color: #fff; }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="head-logo">
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                <span><?= htmlspecialchars($project['name']) ?></span>
            </div>
            <p style="font-size:14px;color:var(--text-muted);margin:0 0 20px;"><?= htmlspecialchars($i18n['overview_sub']) ?></p>
            <ul>
                <?php foreach ($docs as $doc): ?>
                    <?php if ($doc['title']): ?>
                        <li>
                            <a href="/<?= htmlspecialchars($lang) ?>/<?= htmlspecialchars($doc['slug'] ?: $doc['default_slug']) ?>">
                                <span><?= htmlspecialchars($doc['title']) ?></span>
                                <span>&rarr;</span>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <p style="font-size:11px;color:var(--text-faintest);line-height:1.5;text-align:center;margin:22px 0 0"><?= htmlspecialchars($i18n['overview_title']) ?></p>
            <div class="toc-brand"><span class="badge">§</span> Bereitgestellt mit Paragrafy</div>
        </div>
    </body>
    </html>
    <?php
}

function render_public_404(array $project, string $lang): void {
    $i18n = get_i18n_strings($lang);
    ?>
    <!DOCTYPE html>
    <html lang="<?= htmlspecialchars($lang) ?>">
    <head>
        <meta charset="utf-8"><title>404 - <?= htmlspecialchars($i18n['not_found_title']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?= theme_head_tags() ?>
        <?= theme_base_css($project['brand_color'] ?: '#e11d48') ?>
    </head>
    <body style="text-align:center; padding: 5rem 1rem;">
        <h2 style="font-size:20px;font-weight:800;"><?= htmlspecialchars($i18n['not_found_title']) ?></h2>
        <p style="color:var(--text-muted)"><?= htmlspecialchars($i18n['not_found_desc']) ?></p>
        <p><a href="/<?= htmlspecialchars($lang) ?>" style="font-weight:700;">&larr; <?= htmlspecialchars($i18n['back_to_overview']) ?></a></p>
    </body></html>
    <?php
}

/**
 * In-App Embed-Drawer: bind buttons with data-paragrafy-slug / data-paragrafy-lang
 * and open a modal sheet loading the document via the public JSON API.
 */
function render_embed_js(): void {
    ?>
(function () {
    var scriptEl = document.currentScript;
    if (!scriptEl) {
        var scripts = document.getElementsByTagName('script');
        scriptEl = scripts[scripts.length - 1];
    }
    var origin;
    try { origin = new URL(scriptEl.src, window.location.href).origin; } catch (e) { origin = window.location.origin; }

    function injectStyles() {
        if (document.getElementById('paragrafy-embed-style')) return;
        var style = document.createElement('style');
        style.id = 'paragrafy-embed-style';
        style.textContent =
            '.paragrafy-embed-backdrop{position:fixed;inset:0;background:rgba(20,16,22,.5);z-index:999999;display:flex;align-items:flex-end;justify-content:center;padding:0}' +
            '@media (min-width:720px){.paragrafy-embed-backdrop{align-items:center;padding:20px}}' +
            '.paragrafy-embed-sheet{background:#fff;width:100%;max-width:640px;max-height:85vh;border-radius:16px 16px 0 0;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 -10px 40px rgba(0,0,0,.2);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' +
            '@media (min-width:720px){.paragrafy-embed-sheet{border-radius:16px}}' +
            '.paragrafy-embed-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #E8E4DC;flex-shrink:0}' +
            '.paragrafy-embed-header h2{margin:0;font-size:16px;font-weight:700;color:#201C24}' +
            '.paragrafy-embed-close{border:none;background:transparent;font-size:22px;line-height:1;cursor:pointer;color:#746E78;padding:4px;flex-shrink:0}' +
            '.paragrafy-embed-body{padding:20px;overflow-y:auto;font-size:14px;line-height:1.7;color:#201C24}' +
            '.paragrafy-embed-body h2{font-size:18px;margin-top:20px}' +
            '.paragrafy-embed-body h3{font-size:15px;margin-top:16px}' +
            '.paragrafy-embed-body p{margin:0.75em 0}' +
            '.paragrafy-embed-loading,.paragrafy-embed-error{padding:40px 20px;text-align:center;color:#746E78;font-size:14px}';
        document.head.appendChild(style);
    }

    function openDrawer(lang, slug) {
        injectStyles();
        var backdrop = document.createElement('div');
        backdrop.className = 'paragrafy-embed-backdrop';
        backdrop.innerHTML =
            '<div class="paragrafy-embed-sheet" role="dialog" aria-modal="true">' +
                '<div class="paragrafy-embed-header"><h2>Wird geladen&hellip;</h2>' +
                '<button type="button" class="paragrafy-embed-close" aria-label="Schlie&szlig;en">&times;</button></div>' +
                '<div class="paragrafy-embed-body"><div class="paragrafy-embed-loading">L&auml;dt&hellip;</div></div>' +
            '</div>';
        document.body.appendChild(backdrop);

        function close() {
            backdrop.remove();
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) { if (e.key === 'Escape') close(); }
        document.addEventListener('keydown', onKey);
        backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
        backdrop.querySelector('.paragrafy-embed-close').addEventListener('click', close);

        fetch(origin + '/api/' + encodeURIComponent(lang) + '/' + encodeURIComponent(slug))
            .then(function (res) {
                if (!res.ok) throw new Error('not_found');
                return res.json();
            })
            .then(function (data) {
                backdrop.querySelector('.paragrafy-embed-header h2').textContent = data.title || '';
                backdrop.querySelector('.paragrafy-embed-body').innerHTML = data.html || '';
            })
            .catch(function () {
                backdrop.querySelector('.paragrafy-embed-header h2').textContent = 'Fehler';
                backdrop.querySelector('.paragrafy-embed-body').innerHTML =
                    '<div class="paragrafy-embed-error">Dokument konnte nicht geladen werden.</div>';
            });
    }

    function bind(el) {
        if (el.__paragrafyBound) return;
        el.__paragrafyBound = true;
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var slug = el.getAttribute('data-paragrafy-slug');
            var lang = el.getAttribute('data-paragrafy-lang') || 'de';
            if (slug) openDrawer(lang, slug);
        });
    }

    function scan() {
        var els = document.querySelectorAll('[data-paragrafy-slug]');
        for (var i = 0; i < els.length; i++) bind(els[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    if (window.MutationObserver) {
        new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
    <?php
}

/**
 * DSGVO Cookie-Consent-Banner. $project may be null (unknown domain) — falls
 * back to generic text/colors in that case.
 */
function render_consent_js(?array $project): void {
    $brand = $project['brand_color'] ?? '#e11d48';
    $primaryLang = $project['primary_lang'] ?? 'de';
    $bannerText = trim($project['cookie_banner_text'] ?? '') ?: 'Diese Website verwendet Cookies, um grundlegende Funktionen bereitzustellen und die Nutzung zu verbessern.';
    $privacyUrl = '/' . $primaryLang . '/datenschutz';

    $brandJs = json_encode($brand, JSON_UNESCAPED_UNICODE);
    $textJs = json_encode($bannerText, JSON_UNESCAPED_UNICODE);
    $privacyUrlJs = json_encode($privacyUrl, JSON_UNESCAPED_UNICODE);
    ?>
(function () {
    var CONSENT_KEY = 'paragrafy_consent';
    try { if (localStorage.getItem(CONSENT_KEY)) return; } catch (e) {}

    var brand = <?= $brandJs ?>;
    var text = <?= $textJs ?>;
    var privacyUrl = <?= $privacyUrlJs ?>;

    var style = document.createElement('style');
    style.textContent =
        '.paragrafy-consent-bar{position:fixed;left:16px;right:16px;bottom:16px;max-width:560px;margin:0 auto;background:#201C24;color:#fff;border-radius:12px;padding:16px 18px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;line-height:1.5;z-index:999998;box-shadow:0 10px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;gap:12px}' +
        '.paragrafy-consent-bar a{color:#fff;text-decoration:underline}' +
        '.paragrafy-consent-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}' +
        '.paragrafy-consent-actions button{border:none;border-radius:8px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit}' +
        '.paragrafy-consent-decline{background:transparent !important;color:#fff;border:1px solid rgba(255,255,255,.3) !important}' +
        '.paragrafy-consent-accept{background:' + brand + ';color:#fff}';
    document.head.appendChild(style);

    var bar = document.createElement('div');
    bar.className = 'paragrafy-consent-bar';
    bar.setAttribute('role', 'dialog');
    bar.setAttribute('aria-label', 'Cookie-Hinweis');

    var textEl = document.createElement('div');
    textEl.textContent = text + ' ';
    var link = document.createElement('a');
    link.href = privacyUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Mehr erfahren';
    textEl.appendChild(link);

    var actions = document.createElement('div');
    actions.className = 'paragrafy-consent-actions';
    var declineBtn = document.createElement('button');
    declineBtn.type = 'button';
    declineBtn.className = 'paragrafy-consent-decline';
    declineBtn.textContent = 'Nur notwendige';
    var acceptBtn = document.createElement('button');
    acceptBtn.type = 'button';
    acceptBtn.className = 'paragrafy-consent-accept';
    acceptBtn.textContent = 'Akzeptieren';

    actions.appendChild(declineBtn);
    actions.appendChild(acceptBtn);
    bar.appendChild(textEl);
    bar.appendChild(actions);
    document.body.appendChild(bar);

    function setConsent(value) {
        try { localStorage.setItem(CONSENT_KEY, value); } catch (e) {}
        bar.remove();
        document.dispatchEvent(new CustomEvent('paragrafy:consent', { detail: { consent: value } }));
    }

    acceptBtn.addEventListener('click', function () { setConsent('accepted'); });
    declineBtn.addEventListener('click', function () { setConsent('declined'); });
})();
    <?php
}
