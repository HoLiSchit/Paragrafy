<?php
/**
 * Paragrafy - Side-by-Side WYSIWYG & Translation Editor with Scheduled Publishing
 */
declare(strict_types=1);

if (empty($_SESSION['paragrafy_admin'])) {
    header('Location: /admin');
    exit;
}

$db = get_db();
$docId = (int)($_GET['doc_id'] ?? 0);
$targetLang = strtolower($_GET['lang'] ?? 'de');

$stmt = $db->prepare("
    SELECT d.*, dt.title as type_title, dt.slug as default_slug, p.id as project_id, p.name as project_name, p.domain, p.primary_lang, p.active_languages, p.deepl_api_key, p.webhook_url, p.webhook_secret, p.brand_color
    FROM documents d
    JOIN doc_types dt ON d.doc_type_id = dt.id
    JOIN projects p ON d.project_id = p.id
    WHERE d.id = ?
");
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    echo htmlspecialchars(t('editor.error.doc_not_found'));
    exit;
}

$stmtAll = $db->prepare("SELECT lang, title, content, updated_at, scheduled_at FROM translations WHERE document_id = ?");
$stmtAll->execute([$docId]);
$allTranslations = [];
foreach ($stmtAll->fetchAll() as $row) {
    $allTranslations[$row['lang']] = $row;
}

$activeLangs = array_filter(array_map('trim', explode(',', $doc['active_languages'] ?? 'de,en')));
$defaultRefLang = $doc['primary_lang'] ?: 'de';
if ($targetLang === $defaultRefLang) {
    foreach ($activeLangs as $l) {
        if ($l !== $targetLang && !empty($allTranslations[$l]['content'])) {
            $defaultRefLang = $l;
            break;
        }
    }
}
$sourceLang = strtolower($_GET['ref_lang'] ?? $defaultRefLang);

$sourceTrans = $allTranslations[$sourceLang] ?? ['title' => $doc['type_title'], 'content' => t('editor.no_text_placeholder')];
$currentSourceHash = md5($sourceTrans['content'] ?? '');

// DeepL AJAX Bidirektionaler Übersetzungs-Endpunkt
if (isset($_POST['action']) && $_POST['action'] === 'deepl_translate') {
    header('Content-Type: application/json');
    $env = load_env_file();
    $apiKey = !empty($doc['deepl_api_key']) ? $doc['deepl_api_key'] : ($env['DEEPL_API_KEY'] ?? '');

    $reqSourceLang = strtolower($_POST['source_lang'] ?? $sourceLang);
    $reqTargetLang = strtolower($_POST['target_lang'] ?? $targetLang);
    $contentToTranslate = $_POST['content'] ?? $sourceTrans['content'];
    $titleToTranslate = $_POST['title'] ?? $sourceTrans['title'];

    $resContent = translate_with_deepl($contentToTranslate, $reqSourceLang, $reqTargetLang, $apiKey);
    if (!$resContent['success']) {
        echo json_encode(['success' => false, 'error' => $resContent['error']]);
        exit;
    }

    $resTitle = translate_with_deepl($titleToTranslate, $reqSourceLang, $reqTargetLang, $apiKey);
    $translatedTitle = $resTitle['success'] ? $resTitle['text'] : $titleToTranslate;

    echo json_encode([
        'success' => true,
        'title' => $translatedTitle,
        'content' => $resContent['text']
    ]);
    exit;
}

// Speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_translation') {
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $content = $_POST['content'] ?? '';
        $status = $_POST['status'] ?? 'published';
        $changeNote = trim($_POST['change_note'] ?? '');
        $scheduledAt = !empty($_POST['scheduled_at']) ? str_replace('T', ' ', trim($_POST['scheduled_at'])) . ':00' : null;
        
        $sourceHashToSave = $currentSourceHash;

        $stmtOld = $db->prepare("SELECT content, title, slug FROM translations WHERE document_id = ? AND lang = ?");
        $stmtOld->execute([$docId, $targetLang]);
        $oldRow = $stmtOld->fetch();

        if ($status === 'scheduled' && !empty($scheduledAt)) {
            if ($oldRow) {
                $stmt = $db->prepare("
                    UPDATE translations SET
                        scheduled_at = ?,
                        scheduled_title = ?,
                        scheduled_slug = ?,
                        scheduled_content = ?,
                        scheduled_note = ?
                    WHERE document_id = ? AND lang = ?
                ");
                $stmt->execute([$scheduledAt, $title, $slug, $content, $changeNote, $docId, $targetLang]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, change_note, source_hash, scheduled_at, scheduled_title, scheduled_slug, scheduled_content, scheduled_note, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$docId, $targetLang, $title, $slug, $content, $content, $changeNote, $sourceHashToSave, $scheduledAt, $title, $slug, $content, $changeNote]);
            }

            $currentSlugForPreview = $oldRow['slug'] ?? $slug;
            enqueue_webhook($doc, [
                'event_type' => 'legal_text.scheduled',
                'document_id' => $docId,
                'slug' => $slug,
                'lang' => $targetLang,
                'title' => $title,
                'status' => 'scheduled',
                'change_note' => $changeNote,
                'scheduled_at' => date('c', strtotime($scheduledAt)),
                'effective_date' => date('c', strtotime($scheduledAt)),
                'preview_url' => 'https://' . $doc['domain'] . '/' . $targetLang . '/' . $currentSlugForPreview . '/preview',
                'preview_api_url' => 'https://' . $doc['domain'] . '/api/' . $targetLang . '/' . $currentSlugForPreview . '/preview'
            ]);
            log_audit((int)$doc['project_id'], $doc['project_name'], t('editor.audit.scheduled_for', ['title' => $title, 'lang' => strtoupper($targetLang), 'date' => date('d.m.Y H:i', strtotime($scheduledAt))]));
        } else {
            $prevContent = $oldRow ? $oldRow['content'] : $content;
            $stmt = $db->prepare("
                INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, change_note, source_hash, scheduled_at, scheduled_title, scheduled_slug, scheduled_content, scheduled_note, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, '', '', '', '', CURRENT_TIMESTAMP)
                ON CONFLICT(document_id, lang) DO UPDATE SET
                title=excluded.title, slug=excluded.slug, previous_content=translations.content, content=excluded.content, status=excluded.status, change_note=excluded.change_note, source_hash=excluded.source_hash, scheduled_at=NULL, scheduled_title='', scheduled_slug='', scheduled_content='', scheduled_note='', updated_at=CURRENT_TIMESTAMP
            ");
            $stmt->execute([$docId, $targetLang, $title, $slug, $content, $prevContent, $status, $changeNote, $sourceHashToSave]);
            record_translation_version($db, $docId, $targetLang, $title, $slug, $content, $changeNote, $status);

            if ($status === 'published') {
                enqueue_webhook($doc, [
                    'event_type' => 'legal_text.updated',
                    'document_id' => $docId,
                    'slug' => $slug,
                    'lang' => $targetLang,
                    'title' => $title,
                    'status' => 'published',
                    'change_note' => $changeNote,
                    'was_scheduled' => false,
                    'effective_date' => date('c'),
                    'updated_at' => date('c')
                ]);
            }
            $statusLabel = $status === 'published' ? t('editor.audit.status_published') : t('editor.audit.status_draft');
            log_audit((int)$doc['project_id'], $doc['project_name'], t('editor.audit.saved', ['title' => $title, 'lang' => strtoupper($targetLang), 'status' => $statusLabel]));
        }

        header("Location: /admin?project_id=" . ((int)$_POST['project_id']) . "&msg=saved");
        exit;
    }

    if ($action === 'restore_version') {
        $versionId = (int)($_POST['version_id'] ?? 0);
        $stmtV = $db->prepare("SELECT * FROM translation_versions WHERE id = ? AND document_id = ? AND lang = ?");
        $stmtV->execute([$versionId, $docId, $targetLang]);
        $version = $stmtV->fetch();

        if ($version) {
            $stmtOld = $db->prepare("SELECT content FROM translations WHERE document_id = ? AND lang = ?");
            $stmtOld->execute([$docId, $targetLang]);
            $oldRow = $stmtOld->fetch();
            $prevContent = $oldRow ? $oldRow['content'] : $version['content'];
            $noteRestored = t('editor.restored_note', ['date' => date('d.m.Y H:i', strtotime($version['created_at']))]);

            $stmt = $db->prepare("
                INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, change_note, source_hash, scheduled_at, scheduled_title, scheduled_slug, scheduled_content, scheduled_note, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'published', ?, ?, NULL, '', '', '', '', CURRENT_TIMESTAMP)
                ON CONFLICT(document_id, lang) DO UPDATE SET
                title=excluded.title, slug=excluded.slug, previous_content=translations.content, content=excluded.content, status='published', change_note=excluded.change_note, source_hash=excluded.source_hash, scheduled_at=NULL, scheduled_title='', scheduled_slug='', scheduled_content='', scheduled_note='', updated_at=CURRENT_TIMESTAMP
            ");
            $stmt->execute([$docId, $targetLang, $version['title'], $version['slug'], $version['content'], $prevContent, $noteRestored, $currentSourceHash]);
            record_translation_version($db, $docId, $targetLang, $version['title'], $version['slug'], $version['content'], $noteRestored, 'published');

            enqueue_webhook($doc, [
                'event_type' => 'legal_text.updated',
                'document_id' => $docId,
                'slug' => $version['slug'],
                'lang' => $targetLang,
                'title' => $version['title'],
                'status' => 'published',
                'change_note' => $noteRestored,
                'was_scheduled' => false,
                'effective_date' => date('c'),
                'updated_at' => date('c')
            ]);
            log_audit((int)$doc['project_id'], $doc['project_name'], t('editor.audit.restored', ['title' => $version['title'], 'lang' => strtoupper($targetLang), 'note' => $noteRestored]));
        }

        header("Location: /admin/edit?doc_id=$docId&lang=$targetLang&msg=restored");
        exit;
    }
}

// Zieltext laden
$stmt = $db->prepare("SELECT * FROM translations WHERE document_id = ? AND lang = ?");
$stmt->execute([$docId, $targetLang]);
$targetTrans = $stmt->fetch() ?: [
    'title' => $doc['type_title'],
    'slug' => $doc['default_slug'],
    'content' => '',
    'previous_content' => '',
    'change_note' => '',
    'status' => 'published',
    'scheduled_at' => null,
    'scheduled_title' => '',
    'scheduled_slug' => '',
    'scheduled_content' => '',
    'scheduled_note' => ''
];

$hasScheduled = !empty($targetTrans['scheduled_at']);
$displayContent = $hasScheduled && !empty($targetTrans['scheduled_content']) ? $targetTrans['scheduled_content'] : $targetTrans['content'];
$displayTitle = $hasScheduled && !empty($targetTrans['scheduled_title']) ? $targetTrans['scheduled_title'] : $targetTrans['title'];
$displaySlug = $hasScheduled && !empty($targetTrans['scheduled_slug']) ? $targetTrans['scheduled_slug'] : $targetTrans['slug'];
$displayNote = $hasScheduled && !empty($targetTrans['scheduled_note']) ? $targetTrans['scheduled_note'] : $targetTrans['change_note'];

$isOutdated = ($targetLang !== $sourceLang && !empty($targetTrans['source_hash']) && $targetTrans['source_hash'] !== $currentSourceHash);
$showRef = count($activeLangs) > 1 && ($_GET['showRef'] ?? '0') === '1';

$stmtVersions = $db->prepare("SELECT * FROM translation_versions WHERE document_id = ? AND lang = ? ORDER BY created_at DESC LIMIT 50");
$stmtVersions->execute([$docId, $targetLang]);
$versions = $stmtVersions->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars(t('editor.page_title', ['doc' => $doc['type_title'], 'lang' => strtoupper($targetLang)])) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
    <?= theme_head_tags_admin() ?>
    <?= theme_base_css_admin($doc['brand_color'] ?? '#6366F1') ?>
    <style>
        .editor-container { max-width: 1440px; margin: 24px auto; padding: 0 28px 60px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--border); border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        .pane { background: var(--bg); padding: 22px 26px; display: flex; flex-direction: column; }
        .pane-source { background: var(--bg); }
        .pane:last-child { background: var(--card); }
        .pane-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; flex-wrap: wrap; }
        h3 { margin: 0; font-family: 'JetBrains Mono', monospace; font-size: 11.5px; font-weight: 600; color: var(--text-faint); text-transform: uppercase; letter-spacing: .05em; display: flex; align-items: center; gap: 8px; }
        label { display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; margin-top: 12px; }
        input[readonly] { background: var(--border-soft); color: var(--text-muted); }

        .wysiwyg-toolbar { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
        .tool-btn { background: var(--card); border: 1px solid var(--border-strong); border-radius: var(--radius-sm); width: 28px; height: 28px; font-size: 13px; font-weight: 700; cursor: pointer; color: var(--text); display: inline-flex; align-items: center; justify-content: center; }
        .tool-btn.wide { width: auto; padding: 0 8px; gap: 4px; }
        .tool-btn:hover { background: var(--bg); }
        .tool-btn.active { background: var(--text); color: var(--bg); border-color: var(--text); }

        .editor-box { min-height: 260px; max-height: 480px; overflow-y: auto; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); line-height: 1.7; font-size: 13px; outline: none; margin-bottom: 12px; }
        .editor-box:focus { border-color: var(--accent); }
        .code-textarea { width: 100%; height: 260px; box-sizing: border-box; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius); font-family: 'JetBrains Mono', Menlo, Monaco, monospace; font-size: 12.5px; line-height: 1.5; display: none; margin-bottom: 12px; }

        .source-box { min-height: 380px; max-height: 480px; overflow-y: auto; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); font-size: 13px; line-height: 1.7; color: var(--text); }
        .diff-box { min-height: 380px; max-height: 480px; overflow-y: auto; padding: 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--card); font-size: 13px; line-height: 1.7; display: none; }

        .stat-footer { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--text-faint); margin-top: 6px; padding: 0 2px; }

        .tokens { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .token-btn { background: var(--border-soft); border: none; color: var(--text-muted); padding: 4px 8px; border-radius: var(--radius-sm); cursor: pointer; font-size: 11px; font-family: 'JetBrains Mono', monospace; }
        .token-btn:hover { background: var(--border); color: var(--text); }

        .btn-save { border: none; border-radius: var(--radius); padding: 10px 18px; background: var(--accent); color: var(--btn-ink); font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-save:hover { background: color-mix(in srgb, var(--accent) 85%, white); }
        .btn-deepl { background: transparent; color: var(--text); border: 1px solid var(--border-strong); border-radius: var(--radius-sm); padding: 6px 10px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-deepl:hover { border-color: var(--accent); color: var(--accent); }
        .btn-diff { background: var(--border-soft); color: var(--text-muted); border: none; padding: 6px 12px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }

        .warning-strip { background: var(--amber-bg); color: var(--amber); padding: 10px 28px; font-size: 12.5px; border-bottom: 1px solid var(--amber); display: flex; align-items: center; gap: 8px; }
        .scheduled-strip { background: var(--accent-bg); color: var(--text); border-bottom: 1px solid var(--border-strong); padding: 10px 28px; font-size: 12.5px; display: flex; align-items: center; gap: 8px; }

        ins.diff-ins { background: var(--green-bg); color: var(--green); text-decoration: none; padding: 0.1rem 0.2rem; border-radius: var(--radius-sm); }
        del.diff-del { background: var(--red-bg); color: var(--red); text-decoration: line-through; padding: 0.1rem 0.2rem; border-radius: var(--radius-sm); }

        .ref-select { padding: 4px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-strong); background: var(--card); font-size: 12px; font-weight: 700; }
        .schedule-box { background: var(--accent-bg); border: 1px solid var(--border-strong); border-radius: var(--radius); padding: 12px; margin-top: 14px; display: none; }
        .schedule-box label { color: var(--text) !important; }

        .lang-tabs-row { display: flex; align-items: center; gap: 10px; padding: 16px 28px 0; flex-wrap: wrap; }
        .lang-tab { border: none; border-radius: var(--radius) var(--radius) 0 0; padding: 9px 16px; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; border-bottom: 2px solid transparent; background: transparent; color: var(--text-faint); }
        .lang-tab.active { background: var(--card); color: var(--text); border-bottom-color: var(--accent); }
        .lang-tab-add { border: 1px dashed var(--border-strong); background: transparent; border-radius: var(--radius); padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; color: var(--text-faint); text-decoration: none; }
        .compare-toggle { margin-left: auto; display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--text-muted); font-weight: 500; cursor: pointer; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="pg-topbar">
        <div class="pg-crumb"><?= htmlspecialchars($doc['project_name']) ?> <span style="margin:0 4px">/</span> <strong><?= htmlspecialchars(t('editor.crumb_prefix', ['doc' => $doc['type_title']])) ?></strong></div>
        <a href="/admin?project_id=<?= $doc['project_id'] ?>" style="margin-left:auto;font-size:13px;font-weight:600"><?= t('editor.back_to_dashboard') ?></a>
    </div>

    <?php if (($_GET['msg'] ?? '') === 'restored'): ?>
    <div style="max-width:1440px;margin:24px auto 0;padding:0 28px">
        <div class="scheduled-strip" style="border-radius:var(--radius)">
            <?= svg_icon('check', '', 16) ?>
            <span><?= htmlspecialchars(t('editor.msg.restored')) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasScheduled || $isOutdated): ?>
    <div style="max-width:1440px;margin:24px auto 0;padding:0 28px">
        <?php if ($hasScheduled): ?>
            <div class="scheduled-strip" style="border-radius:var(--radius)">
                <?= svg_icon('calendar', '', 16) ?>
                <span><?= t('editor.scheduled_notice', ['date' => date('d.m.Y \u\m H:i', strtotime($targetTrans['scheduled_at']))]) ?></span>
                <a href="https://<?= htmlspecialchars($doc['domain']) ?>/<?= htmlspecialchars($targetLang) ?>/<?= htmlspecialchars($targetTrans['slug']) ?>/preview" target="_blank" style="margin-left:auto;font-weight:700;white-space:nowrap"><?= t('editor.preview_link') ?></a>
            </div>
        <?php endif; ?>

        <?php if ($isOutdated): ?>
            <div class="warning-strip" style="border-radius:var(--radius)">
                <?= svg_icon('warning', '', 16) ?>
                <span><?= t('editor.outdated_notice') ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="lang-tabs-row" style="max-width:1440px;margin:<?= ($hasScheduled || $isOutdated) ? '14px' : '24px' ?> auto 0;box-sizing:border-box">
        <?php foreach ($activeLangs as $al): ?>
            <?php $alMeta = lang_meta($al); ?>
            <a href="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $al ?>&showRef=<?= $showRef ? '1' : '0' ?>" class="lang-tab <?= $al === $targetLang ? 'active' : '' ?>"><?= $alMeta['flag'] ? $alMeta['flag'] . ' ' : '' ?><?= htmlspecialchars($alMeta['label']) ?></a>
        <?php endforeach; ?>
        <a href="/admin/settings?project_id=<?= $doc['project_id'] ?>" class="lang-tab-add"><?= htmlspecialchars(t('editor.add_language')) ?></a>
        <?php if (count($activeLangs) > 1): ?>
            <label class="compare-toggle">
                <input type="checkbox" <?= $showRef ? 'checked' : '' ?> onchange="location.href='/admin/edit?doc_id=<?= $docId ?>&lang=<?= $targetLang ?>&ref_lang=<?= $sourceLang ?>&showRef=' + (this.checked ? '1' : '0')">
                <?= htmlspecialchars(t('editor.compare_toggle')) ?>
            </label>
        <?php endif; ?>
    </div>

    <div class="editor-container" style="margin-top:0;padding-top:22px">
        <div class="grid" style="grid-template-columns: <?= $showRef ? '1fr 1fr' : '1fr' ?>; max-width: <?= $showRef ? 'none' : '900px' ?>; margin-left: <?= $showRef ? '0' : 'auto' ?>; margin-right: <?= $showRef ? '0' : 'auto' ?>;">
            <?php if ($showRef): ?>
            <div class="pane pane-source">
                <div class="pane-header">
                    <h3>
                        <span><?= htmlspecialchars(t('editor.reference_label')) ?></span>
                        <select class="ref-select" onchange="location.href='/admin/edit?doc_id=<?= $docId ?>&lang=<?= $targetLang ?>&showRef=1&ref_lang=' + this.value">
                            <?php foreach ($activeLangs as $al): ?>
                                <?php if ($al !== $targetLang): ?>
                                    <option value="<?= $al ?>" <?= $al === $sourceLang ? 'selected' : '' ?>>
                                        <?= strtoupper($al) ?> <?= isset($allTranslations[$al]) ? htmlspecialchars(t('editor.translation_present')) : htmlspecialchars(t('editor.translation_empty')) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </h3>
                    <button type="button" class="btn-diff" id="diffBtn" onclick="toggleDiffViewer()"><?= svg_icon('eye', '', 14) ?> <?= htmlspecialchars(t('editor.show_differences')) ?></button>
                </div>

                <label><?= htmlspecialchars(t('editor.reference_title_label', ['lang' => strtoupper($sourceLang)])) ?></label>
                <input type="text" value="<?= htmlspecialchars($sourceTrans['title']) ?>" readonly disabled style="width:100%">

                <label><?= htmlspecialchars(t('editor.reference_content_label')) ?></label>
                <div class="source-box" id="sourceContentBox"><?= $sourceTrans['content'] ?></div>
                <div class="diff-box" id="diffViewerBox"></div>

                <div class="stat-footer">
                    <span id="sourceWordCount">0 <?= htmlspecialchars(t('editor.js.words_label')) ?></span>
                    <label style="display:inline-flex; align-items:center; gap:0.3rem; margin:0; cursor:pointer;">
                        <input type="checkbox" id="syncScrollCheck" checked style="width:14px; height:14px;"> <?= htmlspecialchars(t('editor.sync_scroll')) ?>
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <input type="hidden" id="sourceTitle" value="<?= htmlspecialchars($sourceTrans['title']) ?>">
            <textarea id="sourceRaw" style="display:none;"><?= htmlspecialchars($sourceTrans['content']) ?></textarea>
            <textarea id="previousSourceRaw" style="display:none;"><?= htmlspecialchars($sourceTrans['previous_content'] ?: $sourceTrans['content']) ?></textarea>

            <div class="pane">
                <form id="editForm" method="post" action="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $targetLang ?>&ref_lang=<?= $sourceLang ?>" onsubmit="prepareSubmit()">
                    <input type="hidden" name="action" value="save_translation">
                    <input type="hidden" name="project_id" value="<?= $doc['project_id'] ?>">
                    <textarea name="content" id="finalContentInput" style="display:none;"><?= htmlspecialchars($displayContent) ?></textarea>

                    <div class="pane-header">
                        <h3><?= t('editor.editing_label', ['lang' => strtoupper(htmlspecialchars($targetLang))]) ?></h3>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <button type="button" class="btn-diff" onclick="openVersionsModal()"><?= svg_icon('clock', '', 13) ?> <?= htmlspecialchars(t('editor.history_label', ['count' => count($versions)], count($versions))) ?></button>
                            <?php if ($targetLang !== $sourceLang): ?>
                                <button type="button" class="btn-deepl" id="deeplBtn" onclick="translateWithDeepL()">
                                    <?= htmlspecialchars(t('editor.deepl_button', ['src' => strtoupper($sourceLang), 'tgt' => strtoupper($targetLang)])) ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tokens">
                        <strong><?= htmlspecialchars(t('editor.insert_placeholder_label')) ?></strong><br>
                        <button type="button" class="token-btn" onclick="insertToken('{{company_name}}')">{{company_name}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{address}}')">{{address}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{email}}')">{{email}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{representative}}')">{{representative}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{register_info}}')">{{register_info}}</button>
                    </div>

                    <label><?= htmlspecialchars(t('editor.doc_title_label')) ?></label>
                    <input type="text" id="targetTitle" name="title" value="<?= htmlspecialchars($displayTitle) ?>" required style="width:100%">

                    <label><?= htmlspecialchars(t('editor.slug_label', ['lang' => htmlspecialchars($targetLang)])) ?><?= help_icon(t('editor.slug_help', ['lang' => htmlspecialchars($targetLang)])) ?></label>
                    <input type="text" name="slug" value="<?= htmlspecialchars($displaySlug) ?>" required style="width:100%">

                    <label><?= htmlspecialchars(t('editor.content_label')) ?></label>

                    <div class="wysiwyg-toolbar">
                        <button type="button" class="tool-btn" onclick="execCmd('bold')" title="<?= htmlspecialchars(t('editor.toolbar.bold_title')) ?>"><strong>B</strong></button>
                        <button type="button" class="tool-btn" onclick="execCmd('italic')" title="<?= htmlspecialchars(t('editor.toolbar.italic_title')) ?>"><em>I</em></button>
                        <button type="button" class="tool-btn" onclick="execCmd('underline')" title="<?= htmlspecialchars(t('editor.toolbar.underline_title')) ?>"><u>U</u></button>
                        <button type="button" class="tool-btn" onclick="execFormat('h2')" title="<?= htmlspecialchars(t('editor.toolbar.h2_title')) ?>">H2</button>
                        <button type="button" class="tool-btn" onclick="execFormat('h3')" title="<?= htmlspecialchars(t('editor.toolbar.h3_title')) ?>">H3</button>
                        <button type="button" class="tool-btn" onclick="execFormat('p')" title="<?= htmlspecialchars(t('editor.toolbar.paragraph_title')) ?>">P</button>
                        <button type="button" class="tool-btn wide" onclick="execCmd('insertUnorderedList')" title="<?= htmlspecialchars(t('editor.toolbar.bullet_title')) ?>"><?= t('editor.toolbar.bullet_list_label') ?></button>
                        <button type="button" class="tool-btn wide" onclick="execCmd('insertOrderedList')" title="<?= htmlspecialchars(t('editor.toolbar.numbered_title')) ?>"><?= t('editor.toolbar.numbered_list_label') ?></button>
                        <button type="button" class="tool-btn wide" onclick="createLink()" title="<?= htmlspecialchars(t('editor.toolbar.link_title')) ?>"><?= svg_icon('link', '', 14) ?> <?= htmlspecialchars(t('editor.toolbar.link_label')) ?></button>
                        <button type="button" class="tool-btn wide" onclick="execCmd('removeFormat')" title="<?= htmlspecialchars(t('editor.toolbar.clear_title')) ?>"><?= t('editor.toolbar.clear_label') ?></button>
                        <div style="flex:1;"></div>
                        <button type="button" class="tool-btn wide" id="toggleCodeBtn" onclick="toggleCodeView()" title="<?= htmlspecialchars(t('editor.toolbar.html_title')) ?>"><?= t('editor.toolbar.html_code_label') ?></button>
                    </div>

                    <div id="visualEditor" class="editor-box" contenteditable="true" oninput="updateWordCounts()"><?= $displayContent ?></div>
                    <textarea id="rawCodeEditor" class="code-textarea" oninput="updateWordCounts()"><?= htmlspecialchars($displayContent) ?></textarea>

                    <div class="stat-footer">
                        <span id="targetWordCount">0 <?= htmlspecialchars(t('editor.js.words_label')) ?> &bull; 0 <?= htmlspecialchars(t('editor.js.chars_label')) ?></span>
                        <span><?= t('editor.shortcut_hint') ?></span>
                    </div>

                    <label><?= t('editor.change_note_label') ?><?= help_icon(t('editor.change_note_help')) ?></label>
                    <input type="text" name="change_note" value="<?= htmlspecialchars($displayNote) ?>" placeholder="<?= htmlspecialchars(t('editor.change_note_placeholder')) ?>" style="width:100%">

                    <!-- Zeitgesteuerte Veröffentlichung Einstellungen -->
                    <div id="scheduleBox" class="schedule-box" style="<?= $hasScheduled ? 'display:block;' : '' ?>">
                        <label style="margin-top:0;"><?= htmlspecialchars(t('editor.schedule_label')) ?></label>
                        <input type="datetime-local" id="scheduledInput" name="scheduled_at" value="<?= $hasScheduled ? date('Y-m-d\TH:i', strtotime($targetTrans['scheduled_at'])) : '' ?>" style="width:100%">
                        <div class="pg-hint"><?= htmlspecialchars(t('editor.schedule_hint')) ?></div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem; flex-wrap:wrap; gap:1rem;">
                        <select id="statusSelect" name="status" style="width:auto;" onchange="handleStatusChange(this.value)">
                            <option value="published" <?= (!$hasScheduled && $targetTrans['status'] === 'published') ? 'selected' : '' ?>><?= htmlspecialchars(t('editor.status_publish_now')) ?></option>
                            <option value="scheduled" <?= $hasScheduled ? 'selected' : '' ?>><?= htmlspecialchars(t('editor.status_schedule')) ?></option>
                            <option value="draft" <?= (!$hasScheduled && $targetTrans['status'] === 'draft') ? 'selected' : '' ?>><?= htmlspecialchars(t('editor.status_draft')) ?></option>
                        </select>
                        <div style="display:flex;align-items:center;gap:12px">
                            <span id="dirtyIndicator" style="display:none;font-size:12px;font-weight:600;color:var(--text-faint)"><?= htmlspecialchars(t('editor.unsaved_changes')) ?></span>
                            <button type="submit" class="btn-save"><?= svg_icon('disk', '', 16) ?> <?= htmlspecialchars(t('editor.save_button')) ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="pg-footer-note"><?= htmlspecialchars(t('admin.common.footer_disclaimer')) ?></div>

    <textarea id="currentTargetRaw" style="display:none;"><?= htmlspecialchars($targetTrans['content'] ?? '') ?></textarea>

    <!-- Modal: Versionsverlauf -->
    <div id="versionsModal" class="pg-modal-backdrop" onclick="if(event.target === this) closeVersionsModal()">
        <div class="pg-modal" style="width:640px;max-height:80vh;display:flex;flex-direction:column">
            <h2 style="font-size:19px;font-weight:800;margin:0 0 6px"><?= t('editor.versions_modal_title', ['lang' => strtoupper(htmlspecialchars($targetLang))]) ?></h2>
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px"><?= htmlspecialchars(t('editor.versions_modal_desc')) ?></p>

            <div style="overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:10px">
                <?php if (empty($versions)): ?>
                    <div style="color:var(--text-faint);font-size:13px;font-style:italic"><?= htmlspecialchars(t('editor.no_versions')) ?></div>
                <?php endif; ?>
                <?php foreach ($versions as $v): ?>
                    <div style="border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                            <div>
                                <div style="font-size:13px;font-weight:600"><?= t('editor.version_date_user', ['date' => date('d.m.Y H:i', strtotime($v['created_at']))]) ?> <span style="color:var(--text-muted);font-weight:500"><?= htmlspecialchars($v['user_name']) ?></span></div>
                                <?php if (!empty($v['change_note'])): ?><div style="font-size:12px;color:var(--text-faint);margin-top:2px"><?= htmlspecialchars($v['change_note']) ?></div><?php endif; ?>
                            </div>
                            <div style="display:flex;gap:6px;flex-shrink:0">
                                <button type="button" class="pg-btn-secondary" style="padding:5px 10px;font-size:11.5px" onclick="toggleVersionDiff(<?= $v['id'] ?>)"><?= htmlspecialchars(t('editor.diff_button')) ?></button>
                                <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('editor.confirm_restore'), ENT_QUOTES) ?>');" style="margin:0">
                                    <input type="hidden" name="action" value="restore_version">
                                    <input type="hidden" name="version_id" value="<?= $v['id'] ?>">
                                    <button type="submit" class="pg-btn-secondary" style="padding:5px 10px;font-size:11.5px"><?= htmlspecialchars(t('editor.restore_button')) ?></button>
                                </form>
                            </div>
                        </div>
                        <div id="version_diff_<?= $v['id'] ?>" style="display:none;margin-top:10px;padding:12px;border-top:1px solid var(--border-soft);font-size:13px;line-height:1.6;max-height:260px;overflow-y:auto"></div>
                        <textarea id="version_raw_<?= $v['id'] ?>" style="display:none;"><?= htmlspecialchars($v['content']) ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;justify-content:flex-end;margin-top:16px">
                <button type="button" class="pg-btn-secondary" onclick="closeVersionsModal()"><?= htmlspecialchars(t('editor.close_button')) ?></button>
            </div>
        </div>
    </div>

    <script>
        let isCodeMode = false;
        let isDiffActive = false;
        let formDirty = false;
        const currentSourceLang = "<?= $sourceLang ?>";
        const currentTargetLang = "<?= $targetLang ?>";
        const i18n = {
            urlPrompt: <?= json_encode(t('editor.js.url_prompt')) ?>,
            visualEditorLabel: <?= json_encode(t('editor.toolbar.visual_editor_label')) ?>,
            htmlCodeLabel: <?= json_encode(t('editor.toolbar.html_code_label')) ?>,
            wordsLabel: <?= json_encode(t('editor.js.words_label')) ?>,
            charsLabel: <?= json_encode(t('editor.js.chars_label')) ?>,
            noChanges: <?= json_encode(t('editor.js.no_changes')) ?>,
            translating: <?= json_encode(t('editor.js.translating')) ?>,
            deeplError: <?= json_encode(t('editor.js.deepl_error')) ?>,
            networkErrorPrefix: <?= json_encode(t('editor.js.network_error_prefix')) ?>,
            deeplButtonTemplate: <?= json_encode(t('editor.deepl_button', ['src' => '%SRC%', 'tgt' => '%TGT%'])) ?>
        };

        document.addEventListener('DOMContentLoaded', () => {
            updateWordCounts();
            setupSynchronousScroll();
            const form = document.getElementById('editForm');
            const markDirty = () => {
                formDirty = true;
                document.getElementById('dirtyIndicator').style.display = 'inline';
            };
            form.addEventListener('input', markDirty);
            form.addEventListener('change', markDirty);
        });

        window.addEventListener('beforeunload', (e) => {
            if (!formDirty) return;
            e.preventDefault();
            e.returnValue = '';
        });

        function handleStatusChange(val) {
            const box = document.getElementById('scheduleBox');
            const schedInput = document.getElementById('scheduledInput');
            if (val === 'scheduled') {
                box.style.display = 'block';
                if (!schedInput.value) {
                    const d = new Date();
                    d.setDate(d.getDate() + 1);
                    d.setHours(0, 0, 0, 0);
                    schedInput.value = d.toISOString().slice(0, 16);
                }
            } else {
                box.style.display = 'none';
            }
        }

        document.addEventListener('keydown', function(e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                prepareSubmit();
                document.getElementById('editForm').submit();
            }
        });

        function execCmd(command, value = null) {
            document.getElementById('visualEditor').focus();
            document.execCommand(command, false, value);
            updateWordCounts();
        }

        function execFormat(tag) {
            document.getElementById('visualEditor').focus();
            document.execCommand('formatBlock', false, tag);
            updateWordCounts();
        }

        function createLink() {
            const url = prompt(i18n.urlPrompt, 'https://');
            if (url) {
                execCmd('createLink', url);
            }
        }

        function insertToken(token) {
            if (isCodeMode) {
                const textarea = document.getElementById('rawCodeEditor');
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                textarea.value = textarea.value.substring(0, start) + token + textarea.value.substring(end);
                textarea.focus();
            } else {
                document.getElementById('visualEditor').focus();
                document.execCommand('insertText', false, token);
            }
            updateWordCounts();
        }

        function toggleCodeView() {
            const visual = document.getElementById('visualEditor');
            const code = document.getElementById('rawCodeEditor');
            const btn = document.getElementById('toggleCodeBtn');

            isCodeMode = !isCodeMode;

            if (isCodeMode) {
                code.value = visual.innerHTML;
                visual.style.display = 'none';
                code.style.display = 'block';
                btn.classList.add('active');
                btn.innerText = i18n.visualEditorLabel;
            } else {
                visual.innerHTML = code.value;
                code.style.display = 'none';
                visual.style.display = 'block';
                btn.classList.remove('active');
                btn.innerText = i18n.htmlCodeLabel.replace(/&lt;/g, '<').replace(/&gt;/g, '>');
            }
            updateWordCounts();
        }

        function openVersionsModal() {
            document.getElementById('versionsModal').style.display = 'flex';
        }
        function closeVersionsModal() {
            document.getElementById('versionsModal').style.display = 'none';
        }

        function toggleVersionDiff(id) {
            const box = document.getElementById('version_diff_' + id);
            if (box.style.display === 'block') {
                box.style.display = 'none';
                return;
            }
            const versionText = document.getElementById('version_raw_' + id).value;
            const currentText = document.getElementById('currentTargetRaw').value;
            box.innerHTML = computeSimpleDiff(versionText, currentText);
            box.style.display = 'block';
        }

        function prepareSubmit() {
            const visual = document.getElementById('visualEditor');
            const code = document.getElementById('rawCodeEditor');
            const hidden = document.getElementById('finalContentInput');

            if (isCodeMode) {
                hidden.value = code.value;
            } else {
                hidden.value = visual.innerHTML;
            }
            formDirty = false;
            document.getElementById('dirtyIndicator').style.display = 'none';
        }

        function updateWordCounts() {
            const srcBox = document.getElementById('sourceContentBox');
            if (srcBox) {
                const srcText = srcBox.innerText.trim();
                const srcWords = srcText ? srcText.split(/\\s+/).length : 0;
                document.getElementById('sourceWordCount').innerText = `${srcWords} ${i18n.wordsLabel}`;
            }

            const targetText = isCodeMode ? document.getElementById('rawCodeEditor').value : document.getElementById('visualEditor').innerText;
            const targetWords = targetText.trim() ? targetText.trim().split(/\\s+/).length : 0;
            const targetChars = targetText.length;
            document.getElementById('targetWordCount').innerText = `${targetWords} ${i18n.wordsLabel} • ${targetChars} ${i18n.charsLabel}`;
        }

        function setupSynchronousScroll() {
            const srcBox = document.getElementById('sourceContentBox');
            const visualBox = document.getElementById('visualEditor');
            if (!srcBox || !visualBox) return;
            let isSyncing = false;

            const sync = (source, target) => {
                if (!document.getElementById('syncScrollCheck').checked || isSyncing) return;
                isSyncing = true;
                const percent = source.scrollTop / (source.scrollHeight - source.clientHeight);
                target.scrollTop = percent * (target.scrollHeight - target.clientHeight);
                setTimeout(() => { isSyncing = false; }, 50);
            };

            srcBox.addEventListener('scroll', () => sync(srcBox, visualBox));
            visualBox.addEventListener('scroll', () => sync(visualBox, srcBox));
        }

        function toggleDiffViewer() {
            const normalBox = document.getElementById('sourceContentBox');
            const diffBox = document.getElementById('diffViewerBox');
            const btn = document.getElementById('diffBtn');

            isDiffActive = !isDiffActive;

            if (isDiffActive) {
                const oldText = document.getElementById('previousSourceRaw').value;
                const currentText = document.getElementById('sourceRaw').value;
                diffBox.innerHTML = computeSimpleDiff(oldText, currentText);
                normalBox.style.display = 'none';
                diffBox.style.display = 'block';
                btn.style.background = '#0f172a';
                btn.style.color = '#fff';
            } else {
                diffBox.style.display = 'none';
                normalBox.style.display = 'block';
                btn.style.background = '#f1f5f9';
                btn.style.color = '#475569';
            }
        }

        function computeSimpleDiff(oldStr, newStr) {
            if (oldStr === newStr) {
                return `<div style="color:#64748b; font-style:italic; padding:1rem 0;">${i18n.noChanges}</div>` + newStr;
            }
            const oldWords = oldStr.split(/(\\s+|<[^>]+>)/).filter(Boolean);
            const newWords = newStr.split(/(\\s+|<[^>]+>)/).filter(Boolean);
            let out = '';
            let i = 0, j = 0;
            while (i < oldWords.length || j < newWords.length) {
                if (i < oldWords.length && j < newWords.length && oldWords[i] === newWords[j]) {
                    out += newWords[j];
                    i++; j++;
                } else if (j < newWords.length && !oldWords.includes(newWords[j])) {
                    out += `<ins class="diff-ins">${newWords[j]}</ins>`;
                    j++;
                } else if (i < oldWords.length) {
                    out += `<del class="diff-del">${oldWords[i]}</del>`;
                    i++;
                } else {
                    out += newWords[j];
                    j++;
                }
            }
            return out;
        }

        async function translateWithDeepL() {
            const btn = document.getElementById('deeplBtn');
            const sourceRaw = document.getElementById('sourceRaw').value;
            const sourceTitle = document.getElementById('sourceTitle').value;

            btn.disabled = true;
            btn.innerHTML = `<span>${i18n.translating}</span>`;

            const formData = new FormData();
            formData.append('action', 'deepl_translate');
            formData.append('source_lang', currentSourceLang);
            formData.append('target_lang', currentTargetLang);
            formData.append('content', sourceRaw);
            formData.append('title', sourceTitle);

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await res.json();
                if (!data.success) {
                    alert(data.error || i18n.deeplError);
                } else {
                    document.getElementById('visualEditor').innerHTML = data.content;
                    document.getElementById('rawCodeEditor').value = data.content;
                    if (data.title) {
                        document.getElementById('targetTitle').value = data.title;
                    }
                    updateWordCounts();
                }
            } catch (err) {
                alert(i18n.networkErrorPrefix + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = i18n.deeplButtonTemplate.replace('%SRC%', currentSourceLang.toUpperCase()).replace('%TGT%', currentTargetLang.toUpperCase());
            }
        }
    </script>
</body>
</html>
