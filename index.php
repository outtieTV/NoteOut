<?php
// index.php
// Single-file Notes Webapp with folders/subfolders, media capture/upload, markdown preview/view, theme & timezone settings.
// - Uses PDO sqlite when available, falls back to SQLite3 extension if PDO sqlite driver missing.
// - Media saved to media/ directory.
// - Folders: user-selected color on creation; support nested subfolders via parent_id.
// - Settings: theme (light/dark) and timezone list via Intl.supportedValuesOf('timeZone') in the browser.
// - Marked (markdown) used for preview and full-view modals (client-side).

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_file = __DIR__ . '/notes.sqlite';
$media_dir = __DIR__ . '/media/';

// ensure media dir exists
if (!is_dir($media_dir)) mkdir($media_dir, 0777, true);

// ----------------------
// DB Abstraction (PDO sqlite preferred, fallback to SQLite3)
// ----------------------
$use_pdo = false;
$pdo = null;
$sqlite3 = null;
if (class_exists('PDO')) {
    try {
        $pdo = new PDO('sqlite:' . $db_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // quick test if sqlite driver available
        if (in_array('sqlite', $pdo->getAvailableDrivers())) {
            $use_pdo = true;
        } else {
            $pdo = null;
        }
    } catch (Exception $e) {
        $pdo = null;
    }
}
if (!$use_pdo) {
    if (class_exists('SQLite3')) {
        // open/create DB
        $sqlite3 = new SQLite3($db_file);
        $sqlite3->busyTimeout(5000);
    } else {
        http_response_code(500);
        echo "No suitable SQLite driver found. Enable pdo_sqlite or sqlite3 extension in PHP.";
        exit;
    }
}

// helper functions: db_exec (INSERT/UPDATE/DELETE), db_query (SELECT -> array)
function db_exec($sql, $params = []) {
    global $use_pdo, $pdo, $sqlite3;
    if ($use_pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $pdo->lastInsertId();
    } else {
        $stmt = $sqlite3->prepare($sql);
        // bind params numerically 1..n if params is indexed array, or by name if associative
        if ($stmt === false) {
            throw new Exception("Prepare failed: " . $sqlite3->lastErrorMsg() . " SQL: $sql");
        }
        $isAssoc = array_keys($params) !== range(0, count($params) - 1);
        $i = 1;
        foreach ($params as $k => $v) {
            if ($isAssoc && is_string($k)) {
                // named param e.g. :name
                $stmt->bindValue($k, $v, SQLITE3_TEXT);
            } else {
                $stmt->bindValue($i, $v, SQLITE3_TEXT);
                $i++;
            }
        }
        $res = $stmt->execute();
        if ($res === false) throw new Exception("Execute failed: " . $sqlite3->lastErrorMsg());
        // last insert id
        $last = $sqlite3->lastInsertRowID();
        return $last;
    }
}
function db_query($sql, $params = []) {
    global $use_pdo, $pdo, $sqlite3;
    if ($use_pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $sqlite3->prepare($sql);
        if ($stmt === false) throw new Exception("Prepare failed: " . $sqlite3->lastErrorMsg() . " SQL: $sql");
        $isAssoc = array_keys($params) !== range(0, count($params) - 1);
        $i = 1;
        foreach ($params as $k => $v) {
            if ($isAssoc && is_string($k)) {
                $stmt->bindValue($k, $v, SQLITE3_TEXT);
            } else {
                $stmt->bindValue($i, $v, SQLITE3_TEXT);
                $i++;
            }
        }
        $res = $stmt->execute();
        if ($res === false) throw new Exception("Execute failed: " . $sqlite3->lastErrorMsg());
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
        return $rows;
    }
}

// create tables if not exist
try {
    // folders: id, name, color, parent_id (nullable), created_at
    db_exec("CREATE TABLE IF NOT EXISTS folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        color TEXT NOT NULL,
        parent_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )", []);

    // notes: id, folder_id, title, content (markdown), media, created_at, updated_at
    db_exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_id INTEGER,
        title TEXT,
        content TEXT,
        media TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT
    )", []);
} catch (Exception $e) {
    http_response_code(500);
    echo "DB init error: " . htmlspecialchars($e->getMessage());
    exit;
}

// ----------------------
// Simple router: action (GET/POST)
// ----------------------
$action = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
    // media upload endpoint (used both for file browse and capture uploads)
    $tmp = $_FILES['media']['tmp_name'];
    $name = basename($_FILES['media']['name']);
    $safe = preg_replace("/[^a-zA-Z0-9._-]/", "_", $name);
    $target = $media_dir . time() . "_" . $safe;
    if (move_uploaded_file($tmp, $target)) {
        echo json_encode(['success' => true, 'path' => str_replace('\\', '/', basename($target)), 'full_path' => str_replace('\\','/',$target)]);
    } else {
        echo json_encode(['success' => false, 'error' => 'move_upload failed']);
    }
    exit;
}

if ($action === 'create_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? 'New Folder');
    $color = preg_replace('/[^#A-Za-z0-9]/', '', ($_POST['color'] ?? '#cccccc'));
    $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $id = db_exec("INSERT INTO folders (name, color, parent_id) VALUES (?, ?, ?)", [$name, $color, $parent_id]);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'delete_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

    // 1. Check for notes in the folder
    $note_count = db_query("SELECT COUNT(id) AS count FROM notes WHERE folder_id = ?", [$id])[0]['count'];

    // 2. Check for subfolders
    $subfolder_count = db_query("SELECT COUNT(id) AS count FROM folders WHERE parent_id = ?", [$id])[0]['count'];

    if ($note_count > 0 || $subfolder_count > 0) {
        http_response_code(409); // Conflict
        $message = "Cannot delete folder. It contains ";
        if ($note_count > 0) $message .= "$note_count notes";
        if ($note_count > 0 && $subfolder_count > 0) $message .= " and ";
        if ($subfolder_count > 0) $message .= "$subfolder_count subfolders";
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    // 3. Delete the folder
    db_exec("DELETE FROM folders WHERE id = ?", [$id]);

    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'list_folders') {
    // return folder tree flat list, client will build tree
    $folders = db_query("SELECT * FROM folders ORDER BY created_at DESC", []);
    echo json_encode($folders);
    exit;
}

if ($action === 'save_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder_id = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
    $title = trim($_POST['title'] ?? 'Untitled');
    $content = trim($_POST['content'] ?? '');
    $media = trim($_POST['media'] ?? '');
    $id = db_exec("INSERT INTO notes (folder_id, title, content, media) VALUES (?, ?, ?, ?)", [$folder_id, $title, $content, $media]);
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

if ($action === 'list_notes') {
    // optional folder filter
    $folder = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? (int)$_GET['folder_id'] : null;
    if ($folder) {
        $notes = db_query("SELECT * FROM notes WHERE folder_id = ? ORDER BY id DESC", [$folder]);
    } else {
        $notes = db_query("SELECT * FROM notes ORDER BY id DESC", []);
    }
    echo json_encode($notes);
    exit;
}

if ($action === 'get_note' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $r = db_query("SELECT * FROM notes WHERE id = ?", [$id]);
    echo json_encode($r[0] ?? null);
    exit;
}

if ($action === 'update_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $folder_id = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
    $title = trim($_POST['title'] ?? 'Untitled');
    $content = trim($_POST['content'] ?? '');
    $media = trim($_POST['media'] ?? '');
    db_exec("UPDATE notes SET folder_id = ?, title = ?, content = ?, media = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$folder_id, $title, $content, $media, $id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

    // 1. Get media path before deleting the note
    $note_r = db_query("SELECT media FROM notes WHERE id = ?", [$id]);
    $media_path = $note_r[0]['media'] ?? null;
    
    // 2. Delete the note record
    db_exec("DELETE FROM notes WHERE id = ?", [$id]);

    // 3 & 4. Delete the associated media file if it exists
    if ($media_path) {
        // Sanitize and ensure the path is relative to the media directory
        $media_file_name = basename($media_path);
        
        // IMPORTANT SECURITY CHECK: Ensure the file is inside the media directory
        $target_file = $media_dir . $media_file_name;

        // Check if the file exists and is actually inside the expected media directory
        if (file_exists($target_file) && strpos(realpath($target_file), realpath($media_dir)) === 0) {
            // Check if it's a file before attempting deletion
            if (is_file($target_file)) {
                @unlink($target_file); // Use @ to suppress errors if file is locked or permission denied
            }
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// default: serve UI
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Notes — Folders, Markdown, Media</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css" />
<style>
:root{
  --bg:#f4f6f8; --card:#fff; --muted:#666; --accent:#1976d2;
}
[data-theme="dark"]{ --bg:#0f1113; --card:#111418; --muted:#9aa0a6; --accent:#2a9df4; color: #e6eef8; }
body{ font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; background:var(--bg); margin:0; padding:1rem; }
.app { max-width:1200px; margin:0 auto; display:grid; grid-template-columns:260px 1fr; gap:1rem; }
.sidebar { 
    background:var(--card); 
    padding:1rem; 
    border-radius:8px; 
    box-shadow:0 6px 18px rgba(0,0,0,0.06); 
    height:calc(100vh - 2rem); 
    overflow:auto; 
    top:1rem; /* Keep top:1rem for sticky on large screens */
}
.main { background:var(--card); padding:1rem; border-radius:8px; box-shadow:0 6px 18px rgba(0,0,0,0.06); min-height: calc(100vh - 2rem); overflow:auto; }
.h1 { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
.btn { border: none; background:var(--accent); color:white; padding:0.45rem 0.7rem; border-radius:6px; cursor:pointer; font-weight:600; }
.btn.ghost { background:transparent; color:var(--accent); border:1px solid rgba(0,0,0,0.06); }
.row { display:flex; gap:0.5rem; align-items:center; }
/* Update the folder-item CSS */
.folder-item { 
    display:flex; 
    align-items:center; 
    gap:0.6rem; 
    padding:0.35rem 0.25rem; 
    border-radius:6px; 
    cursor:pointer;
    /* Ensure space between name and buttons */
    justify-content: space-between; 
}
/* Ensure folder name part can take available space for the click handler */
.folder-item > div:first-child { 
    flex-grow: 1; 
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding-right: 0.5rem; /* Add some space */
}
/* Ensure name part has cursor for clicking */
.folder-item > div:first-child:hover { 
    background: rgba(var(--accent), 0.05); /* subtle hover */
}
.folder-color { width:14px; height:14px; border-radius:3px; flex:0 0 14px; }
.folder-name { font-weight:600; flex-shrink: 1; overflow: hidden; text-overflow: ellipsis; } 
.small { font-size:0.85rem; color:var(--muted); }
.note-card { padding:0.6rem; border-radius:6px; border:1px solid rgba(0,0,0,0.04); margin-bottom:0.6rem; }
.note-title { font-weight:700; }
.note-meta { font-size:0.8rem; color:var(--muted); margin-bottom:0.4rem; }
.controls { display:flex; gap:0.5rem; flex-wrap:wrap; }
.form-row { margin-top:0.6rem; display:flex; gap:0.6rem; align-items:center; }
input[type="text"], select, textarea { padding:0.45rem; border-radius:6px; border:1px solid #ddd; width:100%; font-size:0.95rem; }
textarea { min-height:140px; resize:vertical; }
.media-preview { max-width:100%; margin-top:0.4rem; border-radius:6px; display:block; }
.sidebar .add-folder { margin-top: 0.6rem; display:flex; gap:0.5rem; }
.breadcrumbs { font-size:0.9rem; color:var(--muted); margin-bottom:0.6rem; }
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.45); z-index:9999; padding:1rem; }
.modal .dialog { width:100%; max-width:900px; background:var(--card); padding:1rem; border-radius:8px; max-height:90vh; overflow:auto; }
.modal.show { display:flex; }
.kv { display:flex; gap:0.6rem; align-items:center; margin-top:0.6rem; }
.small-btn { padding:0.3rem 0.45rem; font-size:0.9rem; border-radius:6px; border:1px solid rgba(0,0,0,0.06); background:transparent; cursor:pointer; }
footer.small { margin-top:1rem; font-size:0.85rem; color:var(--muted); text-align:center; }
@media (max-width:900px) {
  /* 1. On small screens, switch to a single-column layout */
  .app { grid-template-columns: 1fr; } 
  
  /* 2. Sidebar should be relative and full width on small screens */
  .sidebar { 
    position:relative; 
    height:auto; 
    width: 100%; /* Ensure it fills the grid column */
  }
  
  /* 3. By default, hide the sidebar on mobile */
  .sidebar-hidden #sidebar {
      display: none;
  }
}
/* Ensure the sidebar's default state is sticky on large screens (901px and up) */
@media (min-width:901px) {
  /* Default Desktop Layout: 260px (Sidebar) and 1fr (Notes/Main) */
  .app {
    grid-template-columns: 260px 1fr;
  }
  
  /* Enforce sticky positioning here */
  .sidebar { 
    position:sticky; 
    /* Ensure height calculation is correct for sticky */
    height:calc(100vh - 2rem); 
  }
  
  /* Hiding behavior on Desktop (Fixes the "shrinking" notes column) */
  .sidebar-hidden #sidebar {
      display: none;
  }
  /* When sidebar is hidden on desktop, main content takes the full width (1fr) */
  .sidebar-hidden .app {
      grid-template-columns: 1fr; /* Main content now occupies the full grid */
  }
  .desktop-only { display: none !important; }

  .sidebar-hidden .desktop-only { 
      /* When sidebar is hidden, show the toggle button */
      display: inline-block !important; 
  }
  
  .sidebar-hidden #sidebar {
      /* Ensure sidebar is hidden by default on mobile */
      display: none;
  }
  .desktop-only { display: inline-block !important; }
}

/* Mobile Layout (Max 900px) */
@media (max-width:900px) {
  /* 1. On small screens, switch to a single-column layout by default */
  .app { grid-template-columns: 1fr; } 
  
  /* 2. Sidebar should be relative and full width on small screens */
  .sidebar { 
    position:relative; 
    height:auto; 
    width: 100%; 
  }
  
  /* 3. By default, hide the sidebar on mobile */
  .sidebar-hidden #sidebar {
      display: none;
  }
}
</style>
</head>
<body>
<div style="max-width:1200px;margin:0 auto;">
  <div class="h1" style="margin-bottom:0.8rem;">
    <div style="display:flex;flex-direction:column;">
      <strong style="font-size:1.2rem;">OuttieTV's NoteOut</strong>
      <span class="small">Folders, nested subfolders, media capture, markdown preview/view</span>
    </div>
    <div class="row">
      <button id="settingsBtn" class="btn ghost">⚙ Settings</button>
      <button id="addFolderBtn" class="btn">+ Folder</button>
      <button id="addNoteBtn" class="btn">+ Note</button>
	  <button id="collapseFolders" class="small-btn">☰ Menu</button>
    </div>
  </div>

  <div class="app">
    <aside class="sidebar" id="sidebar">
      <div style="display:flex; align-items:center; justify-content:space-between;">
        <strong>Folders</strong>
      </div>
      <div id="foldersList" style="margin-top:0.6rem;"></div>

      <div style="margin-top:1rem; border-top:1px dashed rgba(0,0,0,0.06); padding-top:0.8rem;">
        <div class="small">Selected folder:</div>
        <div id="selectedFolderName" class="small" style="font-weight:700;">All notes</div>
      </div>

      <div style="margin-top:1rem;">
        <div class="small">Quick tips</div>
        <ul class="small" style="padding-left:1rem;">
          <li>Create colored folders. Use the folder menu to add a subfolder.</li>
          <li>Use the Record / Capture buttons in the note modal to attach media.</li>
          <li>Preview markdown in the editor with Preview button.</li>
        </ul>
      </div>
    </aside>

    <main class="main">
      <div class="breadcrumbs" id="breadcrumbs">All notes</div>
      <div class="controls" style="margin-bottom:0.7rem;">
        <select id="filterFolder" style="max-width:240px;">
          <option value="">All folders</option>
        </select>
        <input id="searchInput" type="text" placeholder="Search title or content" />
        <button id="refreshBtn" class="btn ghost">Refresh</button>
      </div>

      <div id="notesContainer"></div>

      <footer class="small">Notes webapp — media files saved in /media/ — DB: <?=htmlspecialchars(basename($db_file))?> </footer>
    </main>
  </div>
</div>

<!-- Modals -->
<div id="noteModal" class="modal">
  <div class="dialog">
    <h3 id="noteModalTitle">New Note</h3>
    <form id="noteForm">
      <div class="kv">
        <label style="min-width:80px">Folder</label>
        <select id="noteFolder" name="folder_id"></select>
      </div>
      <div class="kv">
        <label style="min-width:80px">Title</label>
        <input type="text" id="noteTitle" name="title" required />
      </div>
      <div class="kv" style="flex-direction:column;">
        <label>Content (Markdown)</label>
        <textarea id="noteContent" name="content"></textarea>
        <div style="margin-top:0.5rem;">
          <button type="button" id="previewBtn" class="small-btn">Preview</button>
          <button type="button" id="fullViewBtn" class="small-btn">Open Full View</button>
        </div>
      </div>

      <div class="kv" style="flex-direction:column;">
        <label>Attach Media</label>
        <input type="file" id="mediaFileInput" accept="image/*,audio/*,video/*">
		<div style="margin-top:0.45rem; display:flex; gap:0.5rem;">
          <button type="button" id="recordAudio" class="small-btn">🎙 Record Audio</button>
          <button type="button" id="recordVideo" class="small-btn">🎥 Record Video</button>
          <button type="button" id="captureImage" class="small-btn">📸 Capture Image</button>
          <button type="button" id="stopRecording" class="btn" style="background:red; display:none;">🛑 Stop Recording</button>
        </div>
        <div id="mediaLivePreviewWrap" style="margin-top:0.6rem; text-align:center;">
            <video id="liveVideoPreview" autoplay muted style="max-width:100%; max-height:200px; border-radius:6px; display:none;"></video>
            <div id="recordingStatus" style="color:red; font-weight:bold; display:none;">🔴 Recording...</div>
        </div>
        <input type="hidden" id="mediaPath" name="media" />
        <div id="mediaPreviewWrap"></div>
      </div>

      <div style="display:flex; gap:0.6rem; margin-top:0.8rem;">
        <button type="submit" class="btn">Save</button>
        <button type="button" id="noteCancel" class="btn ghost">Cancel</button>
        <button type="button" id="deleteNoteBtn" class="small-btn" style="margin-left:auto;display:none;">Delete</button>
      </div>
    </form>
  </div>
</div>

<div id="previewModal" class="modal"><div class="dialog"><div id="previewContent"></div><div style="text-align:right;margin-top:0.6rem;"><button id="closePreview" class="btn ghost">Close</button></div></div></div>

<div id="folderModal" class="modal">
  <div class="dialog">
    <h3>Create Folder</h3>
    <form id="folderForm">
      <div class="kv">
        <label style="min-width:80px">Name</label>
        <input type="text" id="folderName" name="name" required />
      </div>
      <div class="kv">
        <label style="min-width:80px">Color</label>
        <input type="color" id="folderColor" name="color" value="#aabbcc" />
      </div>
      <div class="kv">
        <label style="min-width:80px">Parent (optional)</label>
        <select id="folderParent" name="parent_id"><option value="">(none)</option></select>
      </div>
      <div style="margin-top:0.6rem;">
        <button class="btn">Create</button>
        <button type="button" id="folderCancel" class="btn ghost">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="settingsModal" class="modal">
  <div class="dialog">
    <h3>Settings</h3>
    <div class="kv">
      <label style="min-width:120px">Theme</label>
      <select id="themeSelect"><option value="light">Light</option><option value="dark">Dark</option></select>
    </div>
    <div class="kv">
      <label style="min-width:120px">Timezone</label>
      <select id="timezoneSelect"></select>
    </div>
    <div style="margin-top:0.6rem;text-align:right;">
      <button id="saveSettings" class="btn">Save</button>
      <button id="closeSettings" class="btn ghost">Close</button>
    </div>
  </div>
</div>

<!-- Include marked for markdown rendering -->
<script src="https://cdn.jsdelivr.net/npm/marked/lib/marked.umd.js"></script>

<script>
/* Client-side app logic */
const apiBase = location.pathname; // same file
let folders = [];
let selectedFolderId = '';
let editingNoteId = null;

// utils
function byId(id){ return document.getElementById(id); }
function q(sel, root=document) { return root.querySelector(sel); }
function showModal(id){ byId(id).classList.add('show'); }
function hideModal(id){ byId(id).classList.remove('show'); }
function basename(p){ return p.split(/[\\/]/).pop(); }

// fetch wrappers
async function api(action, method='GET', body=null) {
  let url = apiBase + (action ? '?action=' + encodeURIComponent(action) : '');
  const opts = { method };
  if (method === 'POST' && body instanceof FormData) {
    opts.body = body;
  } else if (method === 'POST' && body && typeof body === 'object') {
    opts.headers = {'Content-Type':'application/x-www-form-urlencoded'};
    opts.body = new URLSearchParams(body);
  }
  const res = await fetch(url, opts);
  const text = await res.text();
  try { return JSON.parse(text); } catch(e) { return text; }
}

// load folders and populate lists
async function loadFolders() {
  folders = await api('list_folders');
  // build flat select and tree
  const sel = byId('filterFolder');
  const noteFolder = byId('noteFolder');
  const folderParent = byId('folderParent');
  sel.innerHTML = '<option value="">All folders</option>';
  noteFolder.innerHTML = '<option value="">(none)</option>';
  folderParent.innerHTML = '<option value="">(none)</option>';
  // build tree HTML
  const listWrap = byId('foldersList');
  listWrap.innerHTML = '';

  // build map
  const map = {};
  folders.forEach(f => { map[f.id] = {...f, children: []}; });
  const roots = [];
  folders.forEach(f => {
    if (f.parent_id) {
      if (map[f.parent_id]) map[f.parent_id].children.push(map[f.id]);
      else roots.push(map[f.id]);
    } else roots.push(map[f.id]);
  });

function renderNode(node, depth=0) {
    const div = document.createElement('div');
    div.style.paddingLeft = (depth * 12) + 'px';
    // Use a flex container for name and buttons
    div.className = 'folder-item'; 
    div.dataset.id = node.id;
    
    // Structure: Color | Name | Buttons (Delete, Add Subfolder)
    div.innerHTML = `
        <div style="display:flex; align-items:center; flex:1;">
            <div class="folder-color" style="background:${node.color}"></div>
            <div class="folder-name">${escapeHtml(node.name)}</div>
        </div>
        <div style="margin-left:auto; display:flex; gap:0.3rem;">
            <button class="small-btn" data-id="${node.id}" data-act="addsub" title="Add Subfolder">+</button>
            <button class="small-btn" data-id="${node.id}" data-act="del" title="Delete Folder" style="color:red; opacity:0.7;">&times;</button>
        </div>
    `;

    // Click handler for selecting the folder
    q('.folder-name', div).closest('div').onclick = () => { selectedFolderId = node.id; refreshNotes(); updateSelectedFolderLabel(); };

    // Handler for the delete button (new)
    q('button[data-act="del"]', div).onclick = async (e) => {
        e.stopPropagation(); // Prevent folder selection
        if (!confirm(`Are you sure you want to delete the folder "${node.name}"?`)) return;

        const res = await api('delete_folder', 'POST', { id: node.id });
        
        if (res.success) {
            alert('Folder deleted successfully.');
            await loadFolders();
            refreshNotes();
        } else {
            alert(`Deletion failed: ${res.error || 'Unknown error'}`);
        }
    };
    
    // Handler for the Add Subfolder button (new)
    q('button[data-act="addsub"]', div).onclick = (e) => {
        e.stopPropagation(); // Prevent folder selection
        // Pre-fill parent_id and show the folder modal
        byId('folderForm').reset();
        byId('folderParent').value = node.id;
        showModal('folderModal');
    };

    listWrap.appendChild(div);

    // ... (rest of the renderNode function)
    // Add options to selects...
    const opt = document.createElement('option'); opt.value = node.id; opt.text = `${'— '.repeat(depth)}${node.name}`; sel.appendChild(opt);
    const opt2 = opt.cloneNode(true); noteFolder.appendChild(opt2);
    const opt3 = opt.cloneNode(true); folderParent.appendChild(opt3);

    node.children.forEach(ch => renderNode(ch, depth+1));
}
  roots.forEach(r => renderNode(r));
  // also include a top-level "All notes" option in breadcrumb
  updateSelectedFolderLabel();
}

function updateSelectedFolderLabel() {
  const label = byId('selectedFolderName');
  const breadcrumbs = byId('breadcrumbs');
  if (!selectedFolderId) {
    label.textContent = 'All notes';
    breadcrumbs.textContent = 'All notes';
    byId('filterFolder').value = '';
  } else {
    const f = folders.find(x => x.id == selectedFolderId);
    label.textContent = f ? f.name : 'All notes';
    // build breadcrumb by climbing parents
    let parts = [];
    let cur = f;
    while (cur) {
      parts.unshift(cur.name);
      cur = folders.find(x => x.id == cur.parent_id);
    }
    breadcrumbs.textContent = parts.join(' / ');
    byId('filterFolder').value = selectedFolderId;
  }
}

// load notes (optionally filtered)
async function refreshNotes() {
  const folderFilter = byId('filterFolder').value || selectedFolderId || '';
  const query = folderFilter ? `?action=list_notes&folder_id=${encodeURIComponent(folderFilter)}` : '?action=list_notes';
  const res = await fetch(location.pathname + (folderFilter ? '?action=list_notes&folder_id='+encodeURIComponent(folderFilter) : '?action=list_notes'));
  const notes = await res.json();
  const container = byId('notesContainer');
  const search = byId('searchInput').value.toLowerCase().trim();
  container.innerHTML = '';
  notes.forEach(n => {
    if (search && !((n.title||'').toLowerCase().includes(search) || (n.content||'').toLowerCase().includes(search))) return;
    const div = document.createElement('div');
    div.className = 'note-card';
    const d = new Date(n.created_at || Date.now()).toLocaleString();
    div.innerHTML = `
      <div class="note-title">${escapeHtml(n.title || '(no title)')}</div>
      <div class="note-meta">${escapeHtml(d)} ${n.folder_id ? '| folder: ' + escapeHtml(getFolderName(n.folder_id)) : ''}</div>
      <div class="note-snippet">${escapeHtml((n.content||'').substring(0,200))}${(n.content||'').length>200?'...':''}</div>
      <div style="margin-top:0.6rem;display:flex;gap:0.4rem;">
        <button class="small-btn" data-id="${n.id}" data-act="view">View</button>
        <button class="small-btn" data-id="${n.id}" data-act="edit">Edit</button>
        <button class="small-btn" data-id="${n.id}" data-act="del">Delete</button>
      </div>
    `;
    container.appendChild(div);
  });
  // attach handlers
  container.querySelectorAll('button[data-act]').forEach(b => {
    b.onclick = async (e) => {
      const id = b.dataset.id;
      const act = b.dataset.act;
      if (act === 'view') openFullView(id);
      if (act === 'edit') openEditNote(id);
      if (act === 'del') {
        if (!confirm('Delete this note?')) return;
        await api('delete_note', 'POST', {id});
        refreshNotes();
      }
    };
  });
}

function getFolderName(id) {
  const f = folders.find(x => x.id == id); return f ? f.name : '(unknown)';
}

function escapeHtml(s){ return String(s||'').replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

// Note modal handlers
byId('addNoteBtn').onclick = () => {
  editingNoteId = null;
  byId('noteModalTitle').textContent = 'New Note';
  byId('noteForm').reset();
  byId('mediaPreviewWrap').innerHTML = '';
  byId('mediaPath').value = '';
  // set default folder to selected
  if (selectedFolderId) byId('noteFolder').value = selectedFolderId;
  showModal('noteModal');
  byId('deleteNoteBtn').style.display = 'none';
};
byId('noteCancel').onclick = () => hideModal('noteModal');

byId('noteForm').onsubmit = async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  // append action
  const payload = new URLSearchParams();
  for (const [k,v] of fd.entries()) payload.append(k, v);
  payload.append('action','save_note');
  // POST
  const res = await fetch(location.pathname + '?action=save_note', { method:'POST', body: payload });
  const j = await res.json();
  if (j.success) {
    hideModal('noteModal');
    refreshNotes();
  } else {
    alert('Save failed');
  }
};

// Folder modal handlers
byId('addFolderBtn').onclick = () => {
  byId('folderForm').reset();
  showModal('folderModal');
};
byId('folderCancel').onclick = () => hideModal('folderModal');
byId('folderForm').onsubmit = async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch(location.pathname + '?action=create_folder', { method:'POST', body: new URLSearchParams([...fd.entries()]) });
  const j = await res.json();
  if (j.success) {
    hideModal('folderModal');
    await loadFolders();
  } else alert('Create folder failed');
};

// settings modal
byId('settingsBtn').onclick = () => { showModal('settingsModal'); populateTimezones(); loadSettings(); };
byId('closeSettings').onclick = () => hideModal('settingsModal');
byId('saveSettings').onclick = () => {
  const theme = byId('themeSelect').value;
  const tz = byId('timezoneSelect').value;
  localStorage.setItem('notes_theme', theme);
  localStorage.setItem('notes_tz', tz);
  applyTheme(theme);
  hideModal('settingsModal');
};

function loadSettings(){
  const theme = localStorage.getItem('notes_theme') || 'light';
  const tz = localStorage.getItem('notes_tz') || Intl.DateTimeFormat().resolvedOptions().timeZone || '';
  byId('themeSelect').value = theme;
  byId('timezoneSelect').value = tz;
  applyTheme(theme);
}

function applyTheme(theme){
  if (theme === 'dark') document.documentElement.setAttribute('data-theme','dark');
  else document.documentElement.removeAttribute('data-theme');
}

async function populateTimezones(){
  // use browser API
  const sel = byId('timezoneSelect');
  if (sel.options.length > 1) return; // already populated
  let tzs = [];
  if (typeof Intl !== 'undefined' && Intl.supportedValuesOf) {
    try { tzs = Intl.supportedValuesOf('timeZone'); } catch(e) { tzs = []; }
  }
  if (!tzs.length) tzs = [Intl.DateTimeFormat().resolvedOptions().timeZone];
  sel.innerHTML = '';
  tzs.forEach(t=> sel.appendChild(new Option(t,t)));
}

// preview modal
byId('previewBtn').onclick = () => {
  const md = byId('noteContent').value || '';
  byId('previewContent').innerHTML = marked.parse(md);
  showModal('previewModal');
};
byId('closePreview').onclick = () => hideModal('previewModal');

// full view: opens a modal showing rendered markdown and note metadata
async function openFullView(id) {
  const res = await api('get_note&id='+encodeURIComponent(id));
  const note = await fetch(location.pathname + '?action=get_note&id='+encodeURIComponent(id)).then(r=>r.json());
  byId('previewContent').innerHTML = `<h2>${escapeHtml(note.title)}</h2>
    <div class="note-meta">${new Date(note.created_at || Date.now()).toLocaleString()}</div>
    <hr/>
    ${marked.parse(note.content||'')}
    ${note.media ? renderMediaTag(note.media) : ''}
  `;
  showModal('previewModal');
}

// edit a note
async function openEditNote(id) {
  editingNoteId = id;
  const note = await fetch(location.pathname + '?action=get_note&id='+encodeURIComponent(id)).then(r=>r.json());
  byId('noteModalTitle').textContent = 'Edit Note';
  byId('noteFolder').value = note.folder_id || '';
  byId('noteTitle').value = note.title || '';
  byId('noteContent').value = note.content || '';
  byId('mediaPath').value = note.media || '';
  const wrap = byId('mediaPreviewWrap');
  wrap.innerHTML = note.media ? renderMediaTag(note.media) : '';
  showModal('noteModal');
  byId('deleteNoteBtn').style.display = 'inline-block';
  byId('deleteNoteBtn').onclick = async () => {
    if (!confirm('Delete this note?')) return;
    await api('delete_note', 'POST', {id});
    hideModal('noteModal');
    refreshNotes();
  };

  // override submit to do update
  byId('noteForm').onsubmit = async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = new URLSearchParams();
    for (const [k,v] of fd.entries()) payload.append(k, v);
    payload.append('action','update_note');
    payload.append('id', editingNoteId);
    const res = await fetch(location.pathname + '?action=update_note', { method:'POST', body: payload });
    const j = await res.json();
    if (j.success) {
      hideModal('noteModal');
      refreshNotes();
      // restore form handler
      byId('noteForm').onsubmit = defaultNoteFormSubmit;
    } else alert('Update failed');
  };
}

// store original submit to restore after edit
const defaultNoteFormSubmit = byId('noteForm').onsubmit;

// media upload and capture
async function uploadBlob(blob, filename) {
  const fd = new FormData();
  fd.append('media', blob, filename);
  const res = await fetch(location.pathname, { method:'POST', body: fd });
  const j = await res.json();
  if (j.success) {
    // save path: use relative basename so <img src="media/..." works
    const rel = 'media/' + basename(j.path || j.full_path || j.path);
    byId('mediaPath').value = rel;
    byId('mediaPreviewWrap').innerHTML = renderMediaTag(rel);
    return rel;
  } else {
    alert('Upload failed');
    return '';
  }
}

byId('mediaFileInput').addEventListener('change', async (e) => {
  if (!e.target.files.length) return;
  const file = e.target.files[0];
  const fd = new FormData();
  fd.append('media', file, file.name);
  const res = await fetch(location.pathname, { method:'POST', body: fd });
  const j = await res.json();
  if (j.success) {
    const rel = 'media/' + basename(j.path || j.full_path || j.path);
    byId('mediaPath').value = rel;
    byId('mediaPreviewWrap').innerHTML = renderMediaTag(rel);
  } else alert('Upload failed');
});

/* Client-side app logic: Media Recording Rewrite */

// Global variables for recording state
let mediaStream = null;
let mediaRecorder = null;
const stopRecordingBtn = byId('stopRecording');
const liveVideoPreview = byId('liveVideoPreview');
const recordingStatus = byId('recordingStatus');

// Helper to disable/enable media buttons
function setMediaButtonsDisabled(disabled) {
  byId('recordAudio').disabled = disabled;
  byId('recordVideo').disabled = disabled;
  byId('captureImage').disabled = disabled;
  byId('mediaFileInput').disabled = disabled;
}

// Common function to start recording
async function startRecording(mimeType, isVideo) {
  try {
    // 1. Get media stream
    const constraints = { audio: true };
    if (isVideo) constraints.video = true;
    mediaStream = await navigator.mediaDevices.getUserMedia(constraints);

    // 2. Setup UI
    setMediaButtonsDisabled(true);
    stopRecordingBtn.style.display = 'inline-block';
    recordingStatus.style.display = 'block';

    if (isVideo) {
      liveVideoPreview.srcObject = mediaStream;
      liveVideoPreview.style.display = 'block';
    }

    // 3. Start MediaRecorder
    mediaRecorder = new MediaRecorder(mediaStream);
    const chunks = [];
    mediaRecorder.ondataavailable = e => chunks.push(e.data);

    mediaRecorder.onstop = async () => {
      // 4. Handle stop and upload
      const blob = new Blob(chunks, { type: mimeType });
      const filename = (isVideo ? 'video_' : 'audio_') + Date.now() + (mimeType.includes('mp4') ? '.mp4' : '.webm'); // Using webm for simplicity with common browser support
      
      // Cleanup UI and stream
      setMediaButtonsDisabled(false);
      stopRecordingBtn.style.display = 'none';
      recordingStatus.style.display = 'none';
      liveVideoPreview.style.display = 'none';
      liveVideoPreview.srcObject = null;
      mediaStream.getTracks().forEach(t => t.stop());
      mediaStream = null;

      // 5. Upload the blob
      await uploadBlob(blob, filename);
    };

    mediaRecorder.onerror = (e) => {
        alert('Recording error: ' + e.error.message);
        stopRecordingBtn.click(); // Force stop on error
    };

    mediaRecorder.start();

  } catch (e) {
    alert('Media record failed: ' + e.message);
    setMediaButtonsDisabled(false);
    stopRecordingBtn.style.display = 'none';
    recordingStatus.style.display = 'none';
    if (mediaStream) mediaStream.getTracks().forEach(t => t.stop());
  }
}

// Handler for Audio Recording
byId('recordAudio').onclick = () => {
  // Use 'audio/webm' or check for 'audio/mp4' support if needed
  startRecording('audio/webm', false);
};

// Handler for Video Recording
byId('recordVideo').onclick = () => {
  // Use 'video/webm' or check for 'video/mp4' support if needed
  startRecording('video/webm', true);
};

// Handler for Stop Button
stopRecordingBtn.onclick = () => {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
};

/* End of Media Recording Rewrite */

byId('captureImage').onclick = async () => {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true });
    const track = stream.getVideoTracks()[0];
    // capture frame via canvas if ImageCapture not supported
    let blob = null;
    if (window.ImageCapture) {
      const ic = new ImageCapture(track);
      blob = await ic.takePhoto();
    } else {
      // fallback: draw to canvas
      const video = document.createElement('video');
      video.srcObject = stream;
      await video.play();
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth || 640;
      canvas.height = video.videoHeight || 480;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const dataUrl = canvas.toDataURL('image/jpeg');
      const res = await fetch(dataUrl);
      blob = await res.blob();
      video.pause();
      video.srcObject = null;
    }
    await uploadBlob(blob, 'image_' + Date.now() + '.jpg');
    stream.getTracks().forEach(t=>t.stop());
  } catch (e) { alert('Image capture failed: ' + e.message); }
};

function renderMediaTag(path) {
  const p = String(path||'');
  if (!p) return '';
  const lower = p.toLowerCase();
  if (lower.match(/\.(jpg|jpeg|png|gif)$/)) return `<img src="${escapeHtml(p)}" class="media-preview">`;
  if (lower.match(/\.(mp4|webm)$/)) return `<video src="${escapeHtml(p)}" controls class="media-preview"></video>`;
  if (lower.match(/\.(mp3|wav|ogg|webm)$/)) return `<audio src="${escapeHtml(p)}" controls class="media-preview"></audio>`;
  // else: plain link
  return `<a href="${escapeHtml(p)}" target="_blank">${escapeHtml(basename(p))}</a>`;
}

// helpers
function basename(p){ return p.split(/[\\/]/).pop(); }

// initial load
(async function init(){
  await loadFolders();
  await refreshNotes();
  loadSettings();
})();

// refresh button
byId('refreshBtn').onclick = () => { loadFolders(); refreshNotes(); };

// folder filter change
byId('filterFolder').onchange = () => {
  selectedFolderId = byId('filterFolder').value || '';
  updateSelectedFolderLabel();
  refreshNotes();
};

// search input enter
byId('searchInput').addEventListener('keydown', (e)=>{ if (e.key === 'Enter') refreshNotes(); });

// Also, initialize the state on page load
(function initSidebarState() {
    const mainWrapper = document.querySelector('.app').closest('div');
    const button = byId('collapseFolders');
    
    if (window.innerWidth <= 900) {
        // Mobile State: Sidebar is hidden by default
        mainWrapper.classList.add('sidebar-hidden');
        button.innerHTML = '☰ Menu';
        // The mobile-only CSS will make the button visible now
    } else {
        // Desktop State: Sidebar is visible by default
        mainWrapper.classList.remove('sidebar-hidden');
        button.innerHTML = '✕ Collapse';
        // The desktop-only CSS will make the button visible now
    }
})();

// Re-add the click handler, ensuring it correctly updates the icon
byId('collapseFolders').onclick = () => {
    const mainWrapper = document.querySelector('.app').closest('div');
    const button = byId('collapseFolders');
    
    // Toggle the class on the main wrapper
    mainWrapper.classList.toggle('sidebar-hidden');

    if (mainWrapper.classList.contains('sidebar-hidden')) {
        // Sidebar is hidden -> Show Hamburger icon (☰)
        button.innerHTML = '☰ Menu';
    } else {
        // Sidebar is visible -> Show Close icon (✕)
        button.innerHTML = '✕ Collapse';
    }
};

// helper: open edit route when modal closed - ensure default submit restored
document.addEventListener('click', (e) => {
  // close modals when clicking backdrop
  ['noteModal','previewModal','folderModal','settingsModal'].forEach(id=>{
    const el = byId(id);
    if (!el) return;
    if (el.classList.contains('show') && e.target === el) hideModal(id);
  });
});
</script>
</body>
</html>
