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

if ($uri === '/embed.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    render_embed_js();
    exit;
}

if ($uri === '/consent.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    render_consent_js();
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
        <style>
            :root { --brand: <?= $brand ?>; --bg: #f8fafc; --text: #0f172a; --card: #ffffff; --border: #e2e8f0; --toc-active: #e11d48; }
            @media (prefers-color-scheme: dark) {
                :root { --bg: #090d16; --text: #f1f5f9; --card: #131b2e; --border: #1e293b; --toc-active: #fb7185; }
            }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; line-height: 1.7; font-size: 1rem; }
            
            .wrapper { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; display: grid; grid-template-columns: 1fr 280px; gap: 2.5rem; align-items: start; }
            @media (max-width: 980px) { .wrapper { grid-template-columns: 1fr; } .toc-sidebar { display: none; } }
            
            .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 2.5rem 3rem; box-shadow: 0 4px 20px -2px rgba(0,0,0,0.04); }
            @media (max-width: 600px) { .card { padding: 1.5rem; } }
            
            header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 1.5rem; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
            .brand-wrap { display: flex; align-items: center; gap: 0.75rem; text-decoration: none; }
            .brand-wrap img { width: 32px; height: 32px; border-radius: 8px; }
            .brand-name { font-weight: 800; font-size: 1.3rem; color: var(--brand); letter-spacing: -0.02em; }
            
            .header-actions { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
            .lang-switch a { text-decoration: none; padding: 0.35rem 0.65rem; border-radius: 8px; font-size: 0.8125rem; border: 1px solid var(--border); color: var(--text); font-weight: 700; transition: all 0.2s; }
            .lang-switch a.active { background: var(--brand); color: #fff; border-color: var(--brand); }
            
            .btn-action { background: transparent; border: 1px solid var(--border); color: var(--text); padding: 0.35rem 0.65rem; border-radius: 8px; font-size: 0.8125rem; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 0.35rem; transition: all 0.2s; }
            .btn-action:hover { border-color: var(--brand); color: var(--brand); }
            
            h1 { font-size: 2.3rem; margin-top: 0; font-weight: 800; letter-spacing: -0.025em; line-height: 1.2; }
            .meta-bar { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; margin-bottom: 2rem; font-size: 0.85rem; color: #64748b; }
            .meta-pill { background: rgba(0,0,0,0.04); padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 500; display: inline-flex; align-items: center; gap: 0.35rem; }
            @media (prefers-color-scheme: dark) { .meta-pill { background: rgba(255,255,255,0.06); } }
            
            .search-box { position: relative; margin-bottom: 2rem; }
            .search-box input { width: 100%; box-sizing: border-box; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); color: var(--text); font-size: 0.9rem; outline: none; transition: border-color 0.2s; }
            .search-box input:focus { border-color: var(--brand); }
            .search-box svg { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; }

            .content h2, .content h3 { scroll-margin-top: 2rem; position: relative; }
            .content h2 { font-size: 1.45rem; font-weight: 700; margin-top: 2.5rem; margin-bottom: 0.75rem; border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
            .content h3 { font-size: 1.15rem; font-weight: 700; margin-top: 1.75rem; margin-bottom: 0.5rem; }
            .content a { color: var(--brand); text-decoration: underline; }
            .content p { margin: 1rem 0; color: var(--text); }
            
            .anchor-link { opacity: 0; margin-left: 0.5rem; text-decoration: none !important; color: #94a3b8 !important; font-weight: normal; transition: opacity 0.2s; font-size: 0.9em; }
            .content h2:hover .anchor-link, .content h3:hover .anchor-link { opacity: 1; }

            .toc-sidebar { position: sticky; top: 2rem; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 15px -2px rgba(0,0,0,0.03); }
            .toc-title { font-size: 0.875rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.05em; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.4rem; }
            .toc-list { list-style: none; padding: 0; margin: 0; max-height: calc(100vh - 12rem); overflow-y: auto; }
            .toc-item { margin-bottom: 0.5rem; }
            .toc-link { display: block; font-size: 0.875rem; color: #64748b; text-decoration: none; padding: 0.25rem 0.5rem; border-radius: 6px; border-left: 2px solid transparent; transition: all 0.2s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .toc-link:hover { color: var(--text); background: rgba(0,0,0,0.03); }
            .toc-link.active { color: var(--toc-active); font-weight: 700; border-left-color: var(--toc-active); background: rgba(225,29,72,0.05); }

            footer { text-align: center; margin-top: 4rem; padding-bottom: 2rem; font-size: 0.8125rem; color: #64748b; }
            @media print { .header-actions, .toc-sidebar, .search-box, .anchor-link, header a, footer { display: none; } .card { border: none; box-shadow: none; padding: 0; } .wrapper { display: block; padding: 0; } body { background: #fff; color: #000; } }
        </style>
    </head>
    <body>
        <div class="wrapper">
            <div class="card">
                <header>
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
                </header>

                <main>
                    <h1><?= htmlspecialchars($trans['title']) ?></h1>
                    
                    <div class="meta-bar">
                        <span class="meta-pill"><?= svg_icon('clock', '', 14) ?> <?= htmlspecialchars($lastUpdatedStr) ?></span>
                        <span class="meta-pill"><?= svg_icon('eye', '', 14) ?> <?= htmlspecialchars($readTimeStr) ?></span>
                        <?php if (!empty($trans['change_note'])): ?>
                            <span class="meta-pill"><?= svg_icon('edit', '', 14) ?> <?= htmlspecialchars($trans['change_note']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="search-box">
                        <?= svg_icon('search', '', 16) ?>
                        <input type="text" id="docSearchInput" placeholder="<?= htmlspecialchars($i18n['search_ph']) ?>" oninput="filterDocumentText(this.value)">
                    </div>

                    <div class="content" id="documentContent">
                        <?= $content ?>
                    </div>
                </main>
            </div>

            <aside class="toc-sidebar">
                <div class="toc-title"><?= svg_icon('shield', '', 14) ?> <?= htmlspecialchars($i18n['toc']) ?></div>
                <ul class="toc-list" id="tocList"></ul>
            </aside>
        </div>

        <footer>
            &copy; <?= date('Y') ?> <?= htmlspecialchars($project['company_name'] ?: $project['name']) ?> &bull; Bereitgestellt über Paragrafy
        </footer>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const content = document.getElementById('documentContent');
                const headings = content.querySelectorAll('h2, h3');
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
                const paragraphs = document.querySelectorAll('#documentContent p, #documentContent li, #documentContent h2, #documentContent h3');
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
        <style>
            :root { --brand: <?= $brand ?>; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem 1rem; }
            .box { max-width: 580px; margin: 3rem auto; background: #fff; padding: 2.5rem; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px rgba(0,0,0,0.03); }
            .head-logo { display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem; }
            .head-logo img { width: 36px; height: 36px; border-radius: 10px; }
            h1 { font-size: 1.6rem; margin: 0; color: var(--brand); font-weight: 800; letter-spacing: -0.02em; }
            ul { list-style: none; padding: 0; margin-top: 1.5rem; }
            li { margin-bottom: 0.75rem; }
            li a { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border: 1px solid #e2e8f0; border-radius: 10px; color: #1e293b; text-decoration: none; font-weight: 600; transition: all 0.2s; }
            li a:hover { border-color: var(--brand); color: var(--brand); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="head-logo">
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                <h1><?= htmlspecialchars($project['name']) ?></h1>
            </div>
            <p style="color: #64748b; margin-top:0;"><?= htmlspecialchars($i18n['overview_sub']) ?></p>
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
        </div>
    </body>
    </html>
    <?php
}

function render_public_404(array $project, string $lang): void {
    $i18n = get_i18n_strings($lang);
    ?>
    <!DOCTYPE html>
    <html><head><meta charset="utf-8"><title>404 - <?= htmlspecialchars($i18n['not_found_title']) ?></title></head>
    <body style="font-family:sans-serif; text-align:center; padding: 5rem 1rem; background:#f8fafc; color:#1e293b;">
        <h2><?= htmlspecialchars($i18n['not_found_title']) ?></h2>
        <p><?= htmlspecialchars($i18n['not_found_desc']) ?></p>
        <p><a href="/<?= htmlspecialchars($lang) ?>" style="color:#e11d48; font-weight:bold;">&larr; <?= htmlspecialchars($i18n['back_to_overview']) ?></a></p>
    </body></html>
    <?php
}
