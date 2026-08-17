<?php

// ============== CONFIGURATION ==============
$CONFIG = [
    'max_upload_size' => 50 * 1024 * 1024,
    'title' => 'File Manager',
];

// ============== INITIALIZATION ==============

session_start();

$message = '';
$messageType = '';

// ============== HELPERS ==============

function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// Resolve path - allows full server navigation
function resolvePath($path) {
    $path = str_replace("\0", '', $path);
    $path = str_replace('\\', '/', $path);
    if ($path === '' || $path === '/') return '/';
    $real = realpath($path);
    if ($real === false) return false;
    return str_replace('\\', '/', $real);
}

// Get current directory from query param
function getCurrentDir() {
    $dir = $_GET['dir'] ?? '';
    if ($dir === '') return str_replace('\\', '/', __DIR__);
    $full = resolvePath($dir);
    if ($full === false || !is_dir($full)) return str_replace('\\', '/', __DIR__);
    return $full;
}

// Build breadcrumbs from absolute path
function buildBreadcrumbs($absPath) {
    $absPath = rtrim($absPath, '/');
    if ($absPath === '') $absPath = '/';

    $crumbs = [];

    // Add root /
    $crumbs[] = ['name' => '/', 'path' => '/'];

    if ($absPath === '/') return $crumbs;

    $parts = explode('/', ltrim($absPath, '/'));
    $accumulated = '';
    foreach ($parts as $part) {
        $accumulated .= '/' . $part;
        $crumbs[] = ['name' => $part, 'path' => $accumulated];
    }
    return $crumbs;
}

// ============== FILE OPERATIONS (POST) ==============

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $returnDir = $_POST['return_dir'] ?? '';

    // Upload
    if ($action === 'upload' && isset($_FILES['files'])) {
        $targetDir = resolvePath($_POST['upload_dir'] ?? '');
        if ($targetDir === false || !is_dir($targetDir)) {
            $message = 'Invalid upload directory.';
            $messageType = 'error';
        } else {
            $uploaded = 0;
            $failed = 0;
            $errors = [];

            foreach ($_FILES['files']['tmp_name'] as $i => $tmpName) {
                if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
                    $failed++;
                    $errors[] = $_FILES['files']['name'][$i] . ': Upload error';
                    continue;
                }

                $fileName = basename($_FILES['files']['name'][$i]);

                if ($_FILES['files']['size'][$i] > $CONFIG['max_upload_size']) {
                    $failed++;
                    $errors[] = $fileName . ': File too large';
                    continue;
                }

                $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
                $targetPath = $targetDir . '/' . $safeName;

                if (file_exists($targetPath)) {
                    $base = pathinfo($safeName, PATHINFO_FILENAME);
                    $counter = 1;
                    while (file_exists($targetDir . '/' . $base . '_' . $counter . ($ext ? '.' . $ext : ''))) {
                        $counter++;
                    }
                    $safeName = $base . '_' . $counter . ($ext ? '.' . $ext : '');
                    $targetPath = $targetDir . '/' . $safeName;
                }

                if (move_uploaded_file($tmpName, $targetPath)) {
                    $uploaded++;
                } else {
                    $failed++;
                    $errors[] = $fileName . ': Move failed';
                }
            }

            if ($uploaded > 0) {
                $message = $uploaded . ' file(s) uploaded successfully.';
                $messageType = 'success';
            }
            if ($failed > 0) {
                $message .= ($message ? ' ' : '') . $failed . ' failed: ' . implode(', ', $errors);
                $messageType = $uploaded > 0 ? 'warning' : 'error';
            }
        }
    }

    // Delete file
    if ($action === 'delete_file') {
        $filePath = resolvePath($_POST['path'] ?? '');
        if ($filePath && is_file($filePath)) {
            if (unlink($filePath)) {
                $message = 'Deleted: ' . basename($filePath);
                $messageType = 'success';
            } else {
                $message = 'Failed to delete file.';
                $messageType = 'error';
            }
        }
    }

    // Delete folder
    if ($action === 'delete_folder') {
        $folderPath = resolvePath($_POST['path'] ?? '');
        if ($folderPath && is_dir($folderPath) && $folderPath !== '/') {
            $items = array_diff(scandir($folderPath), ['.', '..']);
            if (count($items) > 0) {
                $message = 'Folder is not empty. Delete contents first.';
                $messageType = 'error';
            } else {
                if (rmdir($folderPath)) {
                    $message = 'Deleted folder: ' . basename($folderPath);
                    $messageType = 'success';
                } else {
                    $message = 'Failed to delete folder.';
                    $messageType = 'error';
                }
            }
        }
    }

    // Rename
    if ($action === 'rename') {
        $oldPath = resolvePath($_POST['path'] ?? '');
        $newName = basename($_POST['new_name'] ?? '');
        $newName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $newName);
        if ($oldPath && $newName) {
            $parentDir = dirname($oldPath);
            $newPath = $parentDir . '/' . $newName;
            if (file_exists($newPath)) {
                $message = 'A file/folder with that name already exists.';
                $messageType = 'error';
            } elseif (rename($oldPath, $newPath)) {
                $message = 'Renamed to: ' . $newName;
                $messageType = 'success';
            } else {
                $message = 'Rename failed.';
                $messageType = 'error';
            }
        }
    }

    // Save edited file
    if ($action === 'save_file') {
        $filePath = resolvePath($_POST['path'] ?? '');
        if ($filePath && is_file($filePath)) {
            $content = $_POST['content'] ?? '';
            if (file_put_contents($filePath, $content) !== false) {
                $message = 'File saved: ' . basename($filePath);
                $messageType = 'success';
            } else {
                $message = 'Failed to save file.';
                $messageType = 'error';
            }
        }
    }

    // Create folder
    if ($action === 'create_folder') {
        $parentPath = resolvePath($_POST['parent_dir'] ?? '');
        $folderName = basename($_POST['folder_name'] ?? '');
        $folderName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $folderName);
        if ($parentPath && is_dir($parentPath) && $folderName) {
            $newFolder = $parentPath . '/' . $folderName;
            if (file_exists($newFolder)) {
                $message = 'Folder already exists.';
                $messageType = 'error';
            } elseif (mkdir($newFolder, 0755)) {
                $message = 'Folder created: ' . $folderName;
                $messageType = 'success';
            } else {
                $message = 'Failed to create folder.';
                $messageType = 'error';
            }
        }
    }

    // PRG redirect
    $redir = $_SERVER['PHP_SELF'];
    if ($returnDir !== '') {
        $redir .= '?dir=' . urlencode($returnDir);
    }
    $_SESSION['fmf_message'] = $message;
    $_SESSION['fmf_message_type'] = $messageType;
    header('Location: ' . $redir);
    exit;
}

// Flash message
if (isset($_SESSION['fmf_message']) && $_SESSION['fmf_message']) {
    $message = $_SESSION['fmf_message'];
    $messageType = $_SESSION['fmf_message_type'];
    unset($_SESSION['fmf_message'], $_SESSION['fmf_message_type']);
}

// ============== FILE DOWNLOAD ==============

if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['path'])) {
    $filePath = resolvePath($_GET['path']);
    if ($filePath && is_file($filePath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ============== EDIT MODE ==============

$editMode = false;
$editContent = '';
$editAbsPath = '';
$editName = '';

if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['path'])) {
    $filePath = resolvePath($_GET['path']);
    if ($filePath && is_file($filePath)) {
        $editMode = true;
        $editAbsPath = $filePath;
        $editName = basename($filePath);
        if (filesize($filePath) <= 2 * 1024 * 1024) {
            $editContent = file_get_contents($filePath);
        } else {
            $editContent = false;
        }
    }
}

// ============== DIRECTORY LISTING ==============

$currentDir = '';
$items = [];
$breadcrumbs = [];

if (!$editMode) {
    $currentDir = getCurrentDir();
    $breadcrumbs = buildBreadcrumbs($currentDir);

    $scan = @scandir($currentDir);
    if ($scan) {
        $dirs = [];
        $files = [];
        foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            $fullPath = rtrim($currentDir, '/') . '/' . $f;
            if (is_dir($fullPath)) {
                $dirs[] = [
                    'name' => $f,
                    'path' => $fullPath,
                    'type' => 'dir',
                    'modified' => @filemtime($fullPath) ?: 0,
                    'size' => '-',
                ];
            } else {
                $files[] = [
                    'name' => $f,
                    'path' => $fullPath,
                    'type' => 'file',
                    'modified' => @filemtime($fullPath) ?: 0,
                    'size' => @filesize($fullPath) ?: 0,
                ];
            }
        }
        usort($dirs, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        usort($files, function ($a, $b) { return $b['modified'] - $a['modified']; });
        $items = array_merge($dirs, $files);
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($CONFIG['title']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
    .container { max-width: 960px; margin: 0 auto; padding: 20px; }

    .header { display: flex; align-items: center; justify-content: space-between; padding: 16px 0; border-bottom: 1px solid #334155; margin-bottom: 16px; }
    .header h1 { font-size: 20px; color: #f1f5f9; }
    .header .btn-sm { color: #94a3b8; text-decoration: none; font-size: 13px; padding: 6px 14px; border: 1px solid #334155; border-radius: 6px; cursor: pointer; background: none; transition: all 0.2s; }
    .header .btn-sm:hover { color: #f1f5f9; border-color: #64748b; }

    .msg { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
    .msg.success { background: #064e3b; color: #6ee7b7; border: 1px solid #065f46; }
    .msg.error { background: #7f1d1d; color: #fca5a5; border: 1px solid #991b1b; }
    .msg.warning { background: #78350f; color: #fcd34d; border: 1px solid #92400e; }

    .breadcrumbs { display: flex; flex-wrap: wrap; gap: 2px; align-items: center; margin-bottom: 16px; font-size: 14px; padding: 10px 14px; background: #1e293b; border-radius: 8px; }
    .breadcrumbs a { color: #fbbf24; text-decoration: none; padding: 2px 4px; border-radius: 4px; }
    .breadcrumbs a:hover { background: #334155; text-decoration: underline; }
    .breadcrumbs .sep { color: #475569; margin: 0 2px; }
    .breadcrumbs .current { color: #e2e8f0; font-weight: 500; padding: 2px 4px; }
    .breadcrumbs .folder-icon { margin-right: 6px; }

    .upload-section { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .upload-area { flex: 1; min-width: 250px; background: #1e293b; border: 2px dashed #334155; border-radius: 10px; padding: 24px; text-align: center; cursor: pointer; transition: all 0.2s; }
    .upload-area:hover, .upload-area.dragover { border-color: #3b82f6; background: #1e3a5f; }
    .upload-area p { color: #94a3b8; margin-bottom: 8px; font-size: 14px; }
    .upload-area .browse-btn { display: inline-block; padding: 8px 20px; background: #3b82f6; color: white; border-radius: 6px; font-weight: 600; font-size: 13px; }
    .upload-area input[type="file"] { display: none; }
    .upload-area .selected-files { margin-top: 8px; color: #6ee7b7; font-size: 13px; }
    .upload-btn { padding: 10px 24px; border: none; border-radius: 8px; background: #059669; color: white; font-size: 14px; font-weight: 600; cursor: pointer; display: none; align-self: center; }
    .upload-btn:hover { background: #047857; }
    .upload-btn.show { display: block; }

    .newfolder-bar { display: flex; gap: 8px; margin-bottom: 20px; }
    .newfolder-bar input { flex: 1; padding: 8px 12px; border: 1px solid #334155; border-radius: 6px; background: #0f172a; color: #e2e8f0; font-size: 14px; outline: none; }
    .newfolder-bar input:focus { border-color: #3b82f6; }
    .newfolder-bar button { padding: 8px 16px; border: none; border-radius: 6px; background: #6366f1; color: white; font-size: 13px; font-weight: 600; cursor: pointer; }
    .newfolder-bar button:hover { background: #4f46e5; }

    .progress-wrap { display: none; margin-bottom: 16px; }
    .progress-bar { height: 6px; background: #334155; border-radius: 3px; overflow: hidden; }
    .progress-fill { height: 100%; background: #3b82f6; border-radius: 3px; transition: width 0.3s; width: 0%; }
    .progress-text { font-size: 12px; color: #94a3b8; margin-top: 4px; text-align: center; }

    .file-table { width: 100%; border-collapse: collapse; }
    .file-table th { text-align: left; padding: 8px 10px; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #334155; }
    .file-table td { padding: 10px; border-bottom: 1px solid #1e293b; font-size: 14px; vertical-align: middle; }
    .file-table tr:hover td { background: #1e293b; }

    .item-icon { width: 20px; text-align: center; color: #64748b; font-size: 16px; }
    .item-name a { color: #e2e8f0; text-decoration: none; word-break: break-all; }
    .item-name a:hover { color: #3b82f6; }
    .item-name .dir-link { color: #fbbf24; font-weight: 500; }
    .item-size { color: #94a3b8; white-space: nowrap; font-size: 13px; }
    .item-date { color: #94a3b8; white-space: nowrap; font-size: 13px; }
    .item-actions { white-space: nowrap; text-align: right; }
    .item-actions a, .item-actions button { color: #3b82f6; text-decoration: none; font-size: 12px; margin-left: 8px; cursor: pointer; background: none; border: none; font-family: inherit; padding: 4px 8px; border-radius: 4px; }
    .item-actions a:hover, .item-actions button:hover { background: #1e293b; color: #60a5fa; }
    .item-actions .del { color: #ef4444; }
    .item-actions .del:hover { color: #f87171; background: #1e293b; }

    .empty { text-align: center; padding: 40px; color: #64748b; }

    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 100; align-items: center; justify-content: center; }
    .modal-overlay.show { display: flex; }
    .modal { background: #1e293b; border-radius: 12px; padding: 24px; width: 90%; max-width: 420px; }
    .modal h3 { color: #f1f5f9; margin-bottom: 16px; font-size: 16px; }
    .modal input { width: 100%; padding: 10px 14px; border: 1px solid #334155; border-radius: 8px; background: #0f172a; color: #e2e8f0; font-size: 15px; outline: none; margin-bottom: 16px; }
    .modal input:focus { border-color: #3b82f6; }
    .modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
    .modal-btns button { padding: 8px 20px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .modal-btns .cancel { background: #334155; color: #94a3b8; }
    .modal-btns .cancel:hover { background: #475569; }
    .modal-btns .save { background: #3b82f6; color: white; }
    .modal-btns .save:hover { background: #2563eb; }

    .editor-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
    .editor-header h2 { font-size: 16px; color: #f1f5f9; word-break: break-all; }
    .editor-header .back-link { color: #3b82f6; text-decoration: none; font-size: 14px; }
    .editor-header .back-link:hover { text-decoration: underline; }
    .editor-path { font-size: 12px; color: #64748b; margin-bottom: 12px; word-break: break-all; }
    .editor textarea { width: 100%; min-height: 500px; padding: 14px; border: 1px solid #334155; border-radius: 8px; background: #0f172a; color: #e2e8f0; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 14px; line-height: 1.5; resize: vertical; outline: none; tab-size: 4; }
    .editor textarea:focus { border-color: #3b82f6; }
    .editor .save-bar { display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px; }
    .editor .save-bar button { padding: 10px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .editor .save-bar .save-btn { background: #059669; color: white; }
    .editor .save-bar .save-btn:hover { background: #047857; }

    @media (max-width: 640px) {
        .container { padding: 12px; }
        .item-date { display: none; }
        .header h1 { font-size: 16px; }
        .upload-area { padding: 16px; }
        .editor textarea { min-height: 300px; font-size: 13px; }
    }
</style>
</head>
<body>

<?php if ($editMode): ?>
<!-- EDITOR -->
<div class="container">
    <div class="header">
        <h1><?= htmlspecialchars($CONFIG['title']) ?></h1>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="editor">
        <div class="editor-header">
            <h2>Editing: <?= htmlspecialchars($editName) ?></h2>
            <?php $parentDir = dirname($editAbsPath); ?>
            <a href="?dir=<?= urlencode($parentDir) ?>" class="back-link">Back to folder</a>
        </div>
        <div class="editor-path"><?= htmlspecialchars($editAbsPath) ?></div>

        <?php if ($editContent === false): ?>
            <div class="msg error">File is too large to edit (max 2MB).</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_file">
                <input type="hidden" name="path" value="<?= htmlspecialchars($editAbsPath) ?>">
                <input type="hidden" name="return_dir" value="<?= htmlspecialchars($parentDir) ?>">
                <textarea name="content" spellcheck="false"><?= htmlspecialchars($editContent) ?></textarea>
                <div class="save-bar">
                    <a href="?dir=<?= urlencode($parentDir) ?>" class="btn-sm" style="padding:10px 24px;font-size:14px;">Cancel</a>
                    <button type="submit" class="save-btn">Save File</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- FILE BROWSER -->
<div class="container">
    <div class="header">
        <h1><?= htmlspecialchars($CONFIG['title']) ?></h1>
    </div>

    <?php if ($message): ?>
        <div class="msg <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Breadcrumbs -->
    <div class="breadcrumbs">
        <span class="folder-icon">&#128193;</span>
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
            <?php if ($i === count($breadcrumbs) - 1): ?>
                <span class="current"><?= htmlspecialchars($crumb['name']) ?></span>
            <?php else: ?>
                <a href="?dir=<?= urlencode($crumb['path']) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Upload -->
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="upload_dir" value="<?= htmlspecialchars($currentDir) ?>">
        <input type="hidden" name="return_dir" value="<?= htmlspecialchars($currentDir) ?>">

        <div class="upload-section">
            <div class="upload-area" id="dropZone" onclick="document.getElementById('fileInput').click()">
                <p>Drag & drop or click to upload into this folder</p>
                <span class="browse-btn">Browse Files</span>
                <input type="file" name="files[]" id="fileInput" multiple>
                <div class="selected-files" id="selectedFiles"></div>
            </div>
            <button type="submit" class="upload-btn" id="uploadBtn">Upload</button>
        </div>

        <div class="progress-wrap" id="progressWrap">
            <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
            <div class="progress-text" id="progressText">Uploading...</div>
        </div>
    </form>

    <!-- New Folder -->
    <form method="POST" class="newfolder-bar">
        <input type="hidden" name="action" value="create_folder">
        <input type="hidden" name="parent_dir" value="<?= htmlspecialchars($currentDir) ?>">
        <input type="hidden" name="return_dir" value="<?= htmlspecialchars($currentDir) ?>">
        <input type="text" name="folder_name" placeholder="New folder name..." required>
        <button type="submit">Create Folder</button>
    </form>

    <!-- File/Folder List -->
    <?php if (empty($items)): ?>
        <div class="empty">This folder is empty.</div>
    <?php else: ?>
        <table class="file-table">
            <thead>
                <tr>
                    <th style="width:24px"></th>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td class="item-icon"><?= $item['type'] === 'dir' ? '&#128193;' : '&#128196;' ?></td>
                    <td class="item-name">
                        <?php if ($item['type'] === 'dir'): ?>
                            <a href="?dir=<?= urlencode($item['path']) ?>" class="dir-link"><?= htmlspecialchars($item['name']) ?></a>
                        <?php else: ?>
                            <a href="?action=edit&path=<?= urlencode($item['path']) ?>"><?= htmlspecialchars($item['name']) ?></a>
                        <?php endif; ?>
                    </td>
                    <td class="item-size"><?= $item['type'] === 'dir' ? '-' : formatSize($item['size']) ?></td>
                    <td class="item-date"><?= $item['modified'] ? date('Y-m-d H:i', $item['modified']) : '-' ?></td>
                    <td class="item-actions">
                        <?php if ($item['type'] === 'file'): ?>
                            <a href="?action=edit&path=<?= urlencode($item['path']) ?>">Edit</a>
                            <a href="?action=download&path=<?= urlencode($item['path']) ?>">Download</a>
                        <?php endif; ?>
                        <button onclick="openRename('<?= htmlspecialchars(addslashes($item['path'])) ?>', '<?= htmlspecialchars(addslashes($item['name'])) ?>')">Rename</button>
                        <?php if ($item['type'] === 'dir'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete folder \'<?= htmlspecialchars(addslashes($item['name'])) ?>\'? It must be empty.')">
                                <input type="hidden" name="action" value="delete_folder">
                                <input type="hidden" name="path" value="<?= htmlspecialchars($item['path']) ?>">
                                <input type="hidden" name="return_dir" value="<?= htmlspecialchars($currentDir) ?>">
                                <button type="submit" class="del">Delete</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete \'<?= htmlspecialchars(addslashes($item['name'])) ?>\'?')">
                                <input type="hidden" name="action" value="delete_file">
                                <input type="hidden" name="path" value="<?= htmlspecialchars($item['path']) ?>">
                                <input type="hidden" name="return_dir" value="<?= htmlspecialchars($currentDir) ?>">
                                <button type="submit" class="del">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Rename Modal -->
<div class="modal-overlay" id="renameModal">
    <div class="modal">
        <h3>Rename</h3>
        <form method="POST" id="renameForm">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="path" id="renamePath" value="">
            <input type="hidden" name="return_dir" value="<?= htmlspecialchars($currentDir) ?>">
            <input type="text" name="new_name" id="renameInput" placeholder="New name" required autocomplete="off">
            <div class="modal-btns">
                <button type="button" class="cancel" onclick="closeRename()">Cancel</button>
                <button type="submit" class="save">Rename</button>
            </div>
        </form>
    </div>
</div>

<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const uploadBtn = document.getElementById('uploadBtn');
const selectedFiles = document.getElementById('selectedFiles');
const uploadForm = document.getElementById('uploadForm');
const progressWrap = document.getElementById('progressWrap');
const progressFill = document.getElementById('progressFill');
const progressText = document.getElementById('progressText');

['dragenter', 'dragover'].forEach(e => {
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('dragover'); });
});
['dragleave', 'drop'].forEach(e => {
    dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('dragover'); });
});
dropZone.addEventListener('drop', ev => {
    fileInput.files = ev.dataTransfer.files;
    updateFileList();
});

fileInput.addEventListener('change', updateFileList);

function updateFileList() {
    const count = fileInput.files.length;
    if (count > 0) {
        const names = Array.from(fileInput.files).map(f => f.name);
        selectedFiles.textContent = count + ' file(s): ' + names.join(', ');
        uploadBtn.classList.add('show');
    } else {
        selectedFiles.textContent = '';
        uploadBtn.classList.remove('show');
    }
}

uploadForm.addEventListener('submit', function(e) {
    e.preventDefault();
    if (!fileInput.files.length) return;

    const formData = new FormData(uploadForm);
    const xhr = new XMLHttpRequest();

    uploadBtn.style.display = 'none';
    progressWrap.style.display = 'block';

    xhr.upload.addEventListener('progress', function(ev) {
        if (ev.lengthComputable) {
            const pct = Math.round((ev.loaded / ev.total) * 100);
            progressFill.style.width = pct + '%';
            progressText.textContent = 'Uploading... ' + pct + '%';
        }
    });

    xhr.addEventListener('load', function() { window.location.reload(); });
    xhr.addEventListener('error', function() {
        progressText.textContent = 'Upload failed.';
        progressFill.style.background = '#ef4444';
    });

    xhr.open('POST', window.location.href);
    xhr.send(formData);
});

function openRename(path, name) {
    document.getElementById('renamePath').value = path;
    document.getElementById('renameInput').value = name;
    document.getElementById('renameModal').classList.add('show');
    setTimeout(() => document.getElementById('renameInput').focus(), 100);
}

function closeRename() {
    document.getElementById('renameModal').classList.remove('show');
}

document.getElementById('renameModal').addEventListener('click', function(e) {
    if (e.target === this) closeRename();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRename();
});
</script>

<?php endif; ?>

</body>
</html>