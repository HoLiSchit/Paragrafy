<?php
/**
 * Paragrafy v1.5.2 - Bidirectional Side-by-Side WYSIWYG & Translation Editor
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
    SELECT d.*, dt.title as type_title, dt.slug as default_slug, p.id as project_id, p.name as project_name, p.domain, p.primary_lang, p.active_languages, p.deepl_api_key, p.webhook_url, p.webhook_secret
    FROM documents d
    JOIN doc_types dt ON d.doc_type_id = dt.id
    JOIN projects p ON d.project_id = p.id
    WHERE d.id = ?
");
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    echo "Dokument nicht gefunden.";
    exit;
}

// Alle verfügbaren Übersetzungen für dieses Dokument laden
$stmtAll = $db->prepare("SELECT lang, title, content, updated_at FROM translations WHERE document_id = ?");
$stmtAll->execute([$docId]);
$allTranslations = [];
foreach ($stmtAll->fetchAll() as $row) {
    $allTranslations[$row['lang']] = $row;
}

// Standard-Referenzsprache bestimmen (falls target == primary, nimm en oder die erste andere Sprache)
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

// Quelltext laden
$sourceTrans = $allTranslations[$sourceLang] ?? ['title' => $doc['type_title'], 'content' => 'Noch kein Text in dieser Sprache vorhanden.'];
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

        $sourceHashToSave = $currentSourceHash;

        $stmtOld = $db->prepare("SELECT content FROM translations WHERE document_id = ? AND lang = ?");
        $stmtOld->execute([$docId, $targetLang]);
        $oldRow = $stmtOld->fetch();
        $prevContent = $oldRow ? $oldRow['content'] : $content;

        $stmt = $db->prepare("
            INSERT INTO translations (document_id, lang, title, slug, content, previous_content, status, change_note, source_hash, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(document_id, lang) DO UPDATE SET
            title=excluded.title, slug=excluded.slug, previous_content=translations.content, content=excluded.content, status=excluded.status, change_note=excluded.change_note, source_hash=excluded.source_hash, updated_at=CURRENT_TIMESTAMP
        ");
        $stmt->execute([$docId, $targetLang, $title, $slug, $content, $prevContent, $status, $changeNote, $sourceHashToSave]);

        if ($status === 'published') {
            dispatch_webhook($doc, [
                'document_id' => $docId,
                'slug' => $slug,
                'lang' => $targetLang,
                'title' => $title,
                'status' => $status,
                'change_note' => $changeNote,
                'updated_at' => date('c')
            ]);
        }

        header("Location: /admin?project_id=" . ((int)$_POST['project_id']) . "&msg=saved");
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
    'status' => 'published'
];

$isOutdated = ($targetLang !== $sourceLang && !empty($targetTrans['source_hash']) && $targetTrans['source_hash'] !== $currentSourceHash);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Editor: <?= htmlspecialchars($doc['type_title']) ?> (<?= strtoupper($targetLang) ?>)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/paragrafy.svg">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; margin: 0; }
        .nav { background: #090d16; color: #fff; padding: 0.85rem 1.75rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #1e293b; }
        .nav a { color: #94a3b8; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
        .editor-container { max-width: 1440px; margin: 1.5rem auto; padding: 0 1.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .pane { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1.75rem; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; flex-direction: column; }
        .pane-source { background: #fcfcfd; }
        .pane-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; gap: 0.5rem; flex-wrap: wrap; }
        h3 { margin: 0; font-size: 1.15rem; color: #0f172a; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem; margin-top: 0.75rem; }
        input[type=text], select { width: 100%; box-sizing: border-box; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; }

        .wysiwyg-toolbar { display: flex; flex-wrap: wrap; gap: 0.35rem; background: #f1f5f9; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 8px 8px 0 0; border-bottom: none; }
        .tool-btn { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.3rem 0.6rem; font-size: 0.8125rem; font-weight: 600; cursor: pointer; color: #334155; display: inline-flex; align-items: center; gap: 0.25rem; }
        .tool-btn:hover { background: #e2e8f0; color: #0f172a; }
        .tool-btn.active { background: #0f172a; color: #ffffff; border-color: #0f172a; }

        .editor-box { min-height: 440px; max-height: 540px; overflow-y: auto; padding: 1.25rem; border: 1px solid #cbd5e1; border-radius: 0 0 8px 8px; background: #ffffff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.7; font-size: 0.95rem; outline: none; }
        .editor-box:focus { border-color: #e11d48; }
        .code-textarea { width: 100%; height: 440px; box-sizing: border-box; padding: 1.25rem; border: 1px solid #cbd5e1; border-radius: 0 0 8px 8px; font-family: ui-monospace, Menlo, Monaco, monospace; font-size: 0.875rem; line-height: 1.5; display: none; }

        .source-box { min-height: 440px; max-height: 540px; overflow-y: auto; padding: 1.25rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 0.95rem; line-height: 1.7; color: #334155; }
        .diff-box { min-height: 440px; max-height: 540px; overflow-y: auto; padding: 1.25rem; border: 1px solid #cbd5e1; border-radius: 8px; background: #fafafa; font-size: 0.95rem; line-height: 1.7; display: none; }

        .stat-footer { display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; color: #64748b; margin-top: 0.4rem; padding: 0 0.25rem; }

        .tokens { background: #f1f5f9; padding: 0.6rem 0.85rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.8125rem; }
        .token-btn { background: #e2e8f0; border: 1px solid #cbd5e1; padding: 0.25rem 0.5rem; border-radius: 6px; cursor: pointer; margin-right: 0.35rem; font-size: 0.75rem; font-family: monospace; }
        .token-btn:hover { background: #cbd5e1; }

        .btn-save { background: #e11d48; color: #fff; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 4px 12px rgba(225,29,72,0.25); transition: all 0.2s; }
        .btn-save:hover { background: #be123c; transform: translateY(-1px); }
        .btn-deepl { background: #0f172a; color: #38bdf8; border: 1px solid #334155; padding: 0.45rem 0.9rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; }
        .btn-deepl:hover { background: #1e293b; color: #7dd3fc; }
        .btn-diff { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem; }

        .warning-strip { background: #fed7aa; color: #9a3412; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.875rem; border: 1px solid #f97316; display: flex; align-items: center; gap: 0.5rem; }
        ins.diff-ins { background: #dcfce7; color: #166534; text-decoration: none; padding: 0.1rem 0.2rem; border-radius: 3px; }
        del.diff-del { background: #fee2e2; color: #991b1b; text-decoration: line-through; padding: 0.1rem 0.2rem; border-radius: 3px; }

        .ref-select { padding: 0.3rem 0.6rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; font-size: 0.8125rem; font-weight: bold; }
    </style>
</head>
<body>
    <div class="nav">
        <div><strong style="color:#fb7185;">Paragrafy Editor:</strong> <?= htmlspecialchars($doc['type_title']) ?> &bull; Projekt: <?= htmlspecialchars($doc['project_name']) ?></div>
        <div><a href="/admin?project_id=<?= $doc['project_id'] ?>">&larr; Zurück zur Matrix</a></div>
    </div>

    <div class="editor-container">
        <?php if ($isOutdated): ?>
            <div class="warning-strip">
                <?= svg_icon('warning', '', 16) ?>
                <span><strong>Hinweis:</strong> Der Quelltext wurde seit der letzten Übersetzung geändert. Bitte gleiche den Zieltext an oder nutze DeepL.</span>
            </div>
        <?php endif; ?>

        <div class="grid">
            <!-- Linke Spalte: Referenz / Quelltext mit Sprachwähler -->
            <div class="pane pane-source">
                <div class="pane-header">
                    <h3>
                        <span>Referenz:</span>
                        <select class="ref-select" onchange="location.href='/admin/edit?doc_id=<?= $docId ?>&lang=<?= $targetLang ?>&ref_lang=' + this.value">
                            <?php foreach ($activeLangs as $al): ?>
                                <?php if ($al !== $targetLang): ?>
                                    <option value="<?= $al ?>" <?= $al === $sourceLang ? 'selected' : '' ?>>
                                        <?= strtoupper($al) ?> <?= isset($allTranslations[$al]) ? '(vorhanden)' : '(leer)' ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </h3>
                    <button type="button" class="btn-diff" id="diffBtn" onclick="toggleDiffViewer()"><?= svg_icon('eye', '', 14) ?> Diff-Vergleich</button>
                </div>

                <label>Referenz-Titel (<?= strtoupper(htmlspecialchars($sourceLang)) ?>):</label>
                <input type="text" id="sourceTitle" value="<?= htmlspecialchars($sourceTrans['title']) ?>" readonly disabled>

                <label>Referenz-Inhalt:</label>
                <div class="source-box" id="sourceContentBox"><?= $sourceTrans['content'] ?></div>
                <div class="diff-box" id="diffViewerBox"></div>

                <div class="stat-footer">
                    <span id="sourceWordCount">0 Wörter</span>
                    <label style="display:inline-flex; align-items:center; gap:0.3rem; margin:0; cursor:pointer;">
                        <input type="checkbox" id="syncScrollCheck" checked style="width:14px; height:14px;"> Synchron-Scroll
                    </label>
                </div>

                <textarea id="sourceRaw" style="display:none;"><?= htmlspecialchars($sourceTrans['content']) ?></textarea>
                <textarea id="previousSourceRaw" style="display:none;"><?= htmlspecialchars($sourceTrans['previous_content'] ?: $sourceTrans['content']) ?></textarea>
            </div>

            <!-- Rechte Spalte: WYSIWYG & Zieltext Editor -->
            <div class="pane">
                <form id="editForm" method="post" action="/admin/edit?doc_id=<?= $docId ?>&lang=<?= $targetLang ?>&ref_lang=<?= $sourceLang ?>" onsubmit="prepareSubmit()">
                    <input type="hidden" name="action" value="save_translation">
                    <input type="hidden" name="project_id" value="<?= $doc['project_id'] ?>">
                    <textarea name="content" id="finalContentInput" style="display:none;"><?= htmlspecialchars($targetTrans['content']) ?></textarea>

                    <div class="pane-header">
                        <h3>Zieltext: <?= strtoupper(htmlspecialchars($targetLang)) ?></h3>
                        <?php if ($targetLang !== $sourceLang): ?>
                            <button type="button" class="btn-deepl" id="deeplBtn" onclick="translateWithDeepL()">
                                <?= svg_icon('lightning', '', 14) ?> Mit DeepL übersetzen (<?= strtoupper($sourceLang) ?> &rarr; <?= strtoupper($targetLang) ?>)
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="tokens">
                        <strong>Platzhalter einfügen:</strong><br>
                        <button type="button" class="token-btn" onclick="insertToken('{{company_name}}')">{{company_name}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{address}}')">{{address}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{email}}')">{{email}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{representative}}')">{{representative}}</button>
                        <button type="button" class="token-btn" onclick="insertToken('{{register_info}}')">{{register_info}}</button>
                    </div>

                    <label>Titel des Dokuments:</label>
                    <input type="text" id="targetTitle" name="title" value="<?= htmlspecialchars($targetTrans['title']) ?>" required>

                    <label>URL-Slug (/<?= htmlspecialchars($targetLang) ?>/slug):</label>
                    <input type="text" name="slug" value="<?= htmlspecialchars($targetTrans['slug']) ?>" required>

                    <label>Inhalt (WYSIWYG-Visual Editor):</label>

                    <div class="wysiwyg-toolbar">
                        <button type="button" class="tool-btn" onclick="execCmd('bold')" title="Fett"><strong>B</strong></button>
                        <button type="button" class="tool-btn" onclick="execCmd('italic')" title="Kursiv"><em>I</em></button>
                        <button type="button" class="tool-btn" onclick="execCmd('underline')" title="Unterstrichen"><u>U</u></button>
                        <button type="button" class="tool-btn" onclick="execFormat('h2')" title="Überschrift 2">H2</button>
                        <button type="button" class="tool-btn" onclick="execFormat('h3')" title="Überschrift 3">H3</button>
                        <button type="button" class="tool-btn" onclick="execFormat('p')" title="Absatz">P</button>
                        <button type="button" class="tool-btn" onclick="execCmd('insertUnorderedList')" title="Aufzählung">&bull; Liste</button>
                        <button type="button" class="tool-btn" onclick="execCmd('insertOrderedList')" title="Nummerierung">1. Liste</button>
                        <button type="button" class="tool-btn" onclick="createLink()" title="Link einfügen"><?= svg_icon('link', '', 14) ?> Link</button>
                        <button type="button" class="tool-btn" onclick="execCmd('removeFormat')" title="Formatierung entfernen">&#9003; Clear</button>
                        <div style="flex:1;"></div>
                        <button type="button" class="tool-btn" id="toggleCodeBtn" onclick="toggleCodeView()" title="HTML Quellcode bearbeiten">&lt;/&gt; HTML Code</button>
                    </div>

                    <div id="visualEditor" class="editor-box" contenteditable="true" oninput="updateWordCounts()"><?= $targetTrans['content'] ?></div>
                    <textarea id="rawCodeEditor" class="code-textarea" oninput="updateWordCounts()"><?= htmlspecialchars($targetTrans['content']) ?></textarea>

                    <div class="stat-footer">
                        <span id="targetWordCount">0 Wörter &bull; 0 Zeichen</span>
                        <span>Shortcut: <strong>Cmd+S</strong> / <strong>Strg+S</strong></span>
                    </div>

                    <label>Änderungsnotiz (optional für Changelog):</label>
                    <input type="text" name="change_note" value="<?= htmlspecialchars($targetTrans['change_note'] ?? '') ?>" placeholder="z. B. Zahlungsanbieter & DPO ergänzt">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:1.5rem;">
                        <select name="status" style="width:auto;">
                            <option value="published" <?= $targetTrans['status'] === 'published' ? 'selected' : '' ?>>Veröffentlicht (öffentlich & API aktiv)</option>
                            <option value="draft" <?= $targetTrans['status'] === 'draft' ? 'selected' : '' ?>>Entwurf (ausgeblendet)</option>
                        </select>
                        <button type="submit" class="btn-save"><?= svg_icon('disk', '', 16) ?> Speichern & Freigeben</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let isCodeMode = false;
        let isDiffActive = false;
        const currentSourceLang = "<?= $sourceLang ?>";
        const currentTargetLang = "<?= $targetLang ?>";

        document.addEventListener('DOMContentLoaded', () => {
            updateWordCounts();
            setupSynchronousScroll();
        });

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
            const url = prompt('URL eingeben:', 'https://');
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
                btn.innerText = 'Visual Editor';
            } else {
                visual.innerHTML = code.value;
                code.style.display = 'none';
                visual.style.display = 'block';
                btn.classList.remove('active');
                btn.innerText = '</> HTML Code';
            }
            updateWordCounts();
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
        }

        function updateWordCounts() {
            const srcText = document.getElementById('sourceContentBox').innerText.trim();
            const srcWords = srcText ? srcText.split(/\s+/).length : 0;
            document.getElementById('sourceWordCount').innerText = `${srcWords} Wörter`;

            const targetText = isCodeMode ? document.getElementById('rawCodeEditor').value : document.getElementById('visualEditor').innerText;
            const targetWords = targetText.trim() ? targetText.trim().split(/\s+/).length : 0;
            const targetChars = targetText.length;
            document.getElementById('targetWordCount').innerText = `${targetWords} Wörter • ${targetChars} Zeichen`;
        }

        function setupSynchronousScroll() {
            const srcBox = document.getElementById('sourceContentBox');
            const visualBox = document.getElementById('visualEditor');
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
                return '<div style="color:#64748b; font-style:italic; padding:1rem 0;">Keine inhaltlichen Änderungen gegenüber der Vorversion.</div>' + newStr;
            }
            const oldWords = oldStr.split(/(\s+|<[^>]+>)/).filter(Boolean);
            const newWords = newStr.split(/(\s+|<[^>]+>)/).filter(Boolean);
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
            btn.innerHTML = '<span>Übersetze...</span>';

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
                    alert(data.error || 'Fehler bei der DeepL Übersetzung.');
                } else {
                    document.getElementById('visualEditor').innerHTML = data.content;
                    document.getElementById('rawCodeEditor').value = data.content;
                    if (data.title) {
                        document.getElementById('targetTitle').value = data.title;
                    }
                    updateWordCounts();
                }
            } catch (err) {
                alert('Netzwerkfehler bei der DeepL-Anfrage: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = `Mit DeepL übersetzen (${currentSourceLang.toUpperCase()} &rarr; ${currentTargetLang.toUpperCase()})`;
            }
        }
    </script>
</body>
</html>
