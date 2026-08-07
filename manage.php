<?php
session_start();

// --- Configuration ---
$PASSWORD = 'admin123'; // Change this!
$ROOT_DIR = DIRECTORY_SEPARATOR; // Server root — full access
$MAX_UPLOAD_SIZE = 50 * 1024 * 1024; // 50MB


// --- Authentication ---
if (isset($_POST['password'])) {
    if ($_POST['password'] === $PASSWORD) {
        $_SESSION['fm_auth'] = true;
    } else {
        $error = "Invalid password.";
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['fm_auth']);
    header("Location: ?");
    exit;
}

if (!isset($_SESSION['fm_auth']) || $_SESSION['fm_auth'] !== true) {
    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Login - File Manager</title>
        <style>
            body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); width: 100%; max-width: 400px; }
            h2 { margin-top: 0; color: #111827; }
            input[type="password"] { width: 100%; padding: 0.75rem; margin: 1rem 0; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; }
            button { width: 100%; padding: 0.75rem; background-color: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
            button:hover { background-color: #2563eb; }
            .error { color: #ef4444; margin-bottom: 1rem; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>File Manager</h2>
            <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
            <form method="POST">
                <input type="password" name="password" placeholder="Password" required autofocus>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- Helper Functions ---
function get_absolute_path($path) {
    // Resolve to an absolute path safely from server root
    if (empty($path)) {
        return DIRECTORY_SEPARATOR;
    }
    $path = str_replace('\\', '/', $path);
    // Prepend / if not already absolute
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    $resolved = realpath($path);
    if ($resolved === false) {
        // Path doesn't exist yet (e.g. new folder), sanitize manually
        $parts = array_filter(explode('/', $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part) {
            if ($part === '.') continue;
            if ($part === '..') { array_pop($absolutes); }
            else { $absolutes[] = $part; }
        }
        $resolved = '/' . implode('/', $absolutes);
    }
    return $resolved;
}


// --- API Handlers ---
// current_path is always an absolute path like /home/kroetzs1/public_html
$current_path = isset($_GET['path']) ? $_GET['path'] : realpath(__DIR__);
$absolute_path = get_absolute_path($current_path);

if (!file_exists($absolute_path) || !is_dir($absolute_path)) {
    $absolute_path = realpath(__DIR__);
    $current_path = realpath(__DIR__);
}


if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    try {
        if ($action === 'list') {
            $items = array();
            $files = scandir($absolute_path);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    if ($file === '..' && $absolute_path !== $ROOT_DIR) {
                         $items[] = array('name' => '..', 'type' => 'dir', 'size' => 0, 'mtime' => 0);
                    }
                    continue;
                }
                $full_path = $absolute_path . DIRECTORY_SEPARATOR . $file;
                $items[] = array(
                    'name' => $file,
                    'type' => is_dir($full_path) ? 'dir' : 'file',
                    'size' => is_dir($full_path) ? 0 : filesize($full_path),
                    'mtime' => filemtime($full_path)
                );
            }
            usort($items, function($a, $b) {
                if ($a['name'] === '..') return -1;
                if ($b['name'] === '..') return 1;
                if ($a['type'] === $b['type']) return strnatcasecmp($a['name'], $b['name']);
                return $a['type'] === 'dir' ? -1 : 1;
            });
            echo json_encode(['success' => true, 'items' => $items, 'current_path' => $current_path, 'absolute_path' => $absolute_path]);
            exit;
        }

        if ($action === 'upload' && isset($_FILES['file'])) {
            $target = $absolute_path . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Upload failed']);
            }
            exit;
        }

        if ($action === 'create_folder' && isset($_POST['name'])) {
            $name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['name']);
            $target = $absolute_path . DIRECTORY_SEPARATOR . $name;
            if (mkdir($target)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Could not create folder']);
            }
            exit;
        }
        
        if ($action === 'delete' && isset($_POST['name'])) {
             $target = get_absolute_path($current_path . DIRECTORY_SEPARATOR . $_POST['name']);
             if ($target === $ROOT_DIR) {
                 echo json_encode(['success' => false, 'error' => 'Cannot delete root']);
                 exit;
             }
             if (is_dir($target)) {
                 if (@rmdir($target)) {
                      echo json_encode(['success' => true]);
                 } else {
                      echo json_encode(['success' => false, 'error' => 'Directory not empty or permission denied']);
                 }
             } else {
                 if (@unlink($target)) {
                     echo json_encode(['success' => true]);
                 } else {
                     echo json_encode(['success' => false, 'error' => 'Permission denied']);
                 }
             }
             exit;
        }

        if ($action === 'rename' && isset($_POST['old_name']) && isset($_POST['new_name'])) {
             $old = get_absolute_path($current_path . DIRECTORY_SEPARATOR . $_POST['old_name']);
             $new = get_absolute_path($current_path . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['new_name']));
             if (@rename($old, $new)) {
                 echo json_encode(['success' => true]);
             } else {
                 echo json_encode(['success' => false, 'error' => 'Rename failed']);
             }
             exit;
        }

        if ($action === 'read' && isset($_GET['name'])) {
             $target = get_absolute_path($current_path . DIRECTORY_SEPARATOR . $_GET['name']);
             if (is_file($target)) {
                 $content = file_get_contents($target);
                 echo json_encode(['success' => true, 'content' => $content]);
             } else {
                 echo json_encode(['success' => false, 'error' => 'File not found']);
             }
             exit;
        }

        if ($action === 'save' && isset($_POST['name']) && isset($_POST['content'])) {
             $target = get_absolute_path($current_path . DIRECTORY_SEPARATOR . $_POST['name']);
             if (file_put_contents($target, $_POST['content']) !== false) {
                 echo json_encode(['success' => true]);
             } else {
                 echo json_encode(['success' => false, 'error' => 'Failed to save file']);
             }
             exit;
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

if (isset($_GET['download']) && isset($_GET['name'])) {
    $target = get_absolute_path($current_path . DIRECTORY_SEPARATOR . $_GET['name']);
    if (is_file($target)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($target).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($target));
        readfile($target);
        exit;
    }
}

// --- Frontend UI ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <style>
        :root {
            --bg-color: #f9fafb;
            --text-color: #111827;
            --header-bg: #ffffff;
            --border-color: #e5e7eb;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --row-hover: #f3f4f6;
        }
        body { font-family: 'Inter', system-ui, sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; display: flex; flex-direction: column; height: 100vh; }
        header { background-color: var(--header-bg); border-bottom: 1px solid var(--border-color); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); }
        .logo { font-size: 1.25rem; font-weight: bold; color: var(--primary); }
        main { flex: 1; padding: 2rem; overflow-y: auto; }
        .toolbar { display: flex; justify-content: space-between; margin-bottom: 1rem; align-items: center; }
        .breadcrumbs-wrap { background: #1e1e2e; border-radius: 6px; padding: 0.5rem 1rem; display: flex; align-items: center; gap: 0.4rem; font-family: monospace; font-size: 0.9rem; flex: 1; margin-right: 1rem; overflow-x: auto; white-space: nowrap; }
        .breadcrumbs-wrap .prompt { color: #a6e3a1; font-weight: bold; margin-right: 0.25rem; flex-shrink: 0; }
        .breadcrumbs-wrap .sep { color: #6c7086; }
        .breadcrumbs-wrap a { color: #89b4fa; text-decoration: none; cursor: pointer; }
        .breadcrumbs-wrap a:hover { color: #cdd6f4; text-decoration: underline; }
        .breadcrumbs-wrap .current-seg { color: #cdd6f4; font-weight: bold; }
        .actions button, .actions label.btn { background-color: var(--primary); color: white; border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.875rem; margin-left: 0.5rem; display: inline-block;}
        .actions button:hover, .actions label.btn:hover { background-color: var(--primary-hover); }
        .actions button.danger { background-color: var(--danger); }
        .actions button.danger:hover { background-color: var(--danger-hover); }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1); }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background-color: #f8fafc; font-weight: 600; color: #475569; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; }
        tr:hover { background-color: var(--row-hover); }
        tr:last-child td { border-bottom: none; }
        .icon { width: 24px; height: 24px; vertical-align: middle; margin-right: 0.5rem; display: inline-flex; align-items: center; justify-content: center; }
        .icon svg { width: 20px; height: 20px; fill: currentColor; }
        .file-name { display: flex; align-items: center; cursor: pointer; color: var(--text-color); text-decoration: none; font-weight: 500;}
        .file-name:hover { color: var(--primary); }
        .item-actions button { background: none; border: none; color: #6b7280; cursor: pointer; padding: 0.25rem; border-radius: 4px; }
        .item-actions button:hover { background-color: #e5e7eb; color: var(--text-color); }
        
        /* Modal Styles */
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 50; }
        .modal { background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column; }
        .modal h3 { margin-top: 0; margin-bottom: 1rem; }
        .modal textarea { flex: 1; width: 100%; min-height: 400px; padding: 0.5rem; font-family: monospace; border: 1px solid var(--border-color); border-radius: 4px; resize: vertical; box-sizing: border-box; }
        .modal-actions { margin-top: 1rem; display: flex; justify-content: flex-end; gap: 1rem; }
        .modal-actions button { padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; border: none; font-weight: 500; }
        .btn-cancel { background-color: #e5e7eb; color: #374151; }
        .btn-cancel:hover { background-color: #d1d5db; }
        .btn-primary { background-color: var(--primary); color: white; }
        .btn-primary:hover { background-color: var(--primary-hover); }
        #file-upload-input { display: none; }
    </style>
</head>
<body>
    <header>
        <div class="logo">PHP FileManager</div>
        <a href="?logout=1" style="color: #6b7280; text-decoration: none;">Logout</a>
    </header>
    <main>
        <div class="toolbar">
            <div class="breadcrumbs-wrap" id="breadcrumbs">
                <!-- Breadcrumbs rendered via JS -->
            </div>
            <div class="actions">
                <button onclick="promptCreateFolder()">New Folder</button>
                <label class="btn">
                    Upload File
                    <input type="file" id="file-upload-input" onchange="uploadFile(this)">
                </label>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="file-list">
                <!-- Files rendered via JS -->
            </tbody>
        </table>
    </main>

    <!-- Editor Modal -->
    <div class="modal-overlay" id="editor-modal">
        <div class="modal">
            <h3 id="editor-title">Edit File</h3>
            <textarea id="editor-content"></textarea>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeEditor()">Cancel</button>
                <button class="btn-primary" onclick="saveEditor()">Save</button>
            </div>
        </div>
    </div>    <script>
        let currentPath = '/';
        let editingFileName = '';

        const iconFolder = `<svg viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z" fill="#facc15"/></svg>`;
        const iconFile = `<svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" fill="#9ca3af"/></svg>`;

        function formatSize(bytes) {
            if (bytes === 0) return '-';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function formatDate(timestamp) {
            if (timestamp === 0) return '-';
            return new Date(timestamp * 1000).toLocaleString();
        }

        // Build parent path from an absolute path
        function parentPath(absPath) {
            const p = absPath.replace(/\/+$/, '');
            const idx = p.lastIndexOf('/');
            if (idx <= 0) return '/';
            return p.substring(0, idx);
        }

        // Join an absolute base path with a child name
        function joinPath(base, name) {
            return base.replace(/\/+$/, '') + '/' + name;
        }

        async function loadDirectory(path) {
            currentPath = path || '<?php echo realpath(__DIR__); ?>';
            const response = await fetch(`?api=list&path=${encodeURIComponent(currentPath)}`);
            const data = await response.json();

            if (data.success) {
                currentPath = data.current_path;
                renderBreadcrumbs(currentPath);
                renderFiles(data.items);
            } else {
                alert('Error loading directory: ' + data.error);
            }
        }

        function renderBreadcrumbs(absPath) {
            const container = document.getElementById('breadcrumbs');
            const parts = absPath.replace(/\\/g, '/').replace(/\/+/g, '/').split('/').filter(Boolean);

            let html = `<span class="prompt">server:</span>`;
            // Root slash — clickable
            html += `<a class="sep" onclick="loadDirectory('/')" title="Go to /">/</a>`;

            parts.forEach((part, i) => {
                const segPath = '/' + parts.slice(0, i + 1).join('/');
                const isLast = i === parts.length - 1;
                if (isLast) {
                    html += `<span class="current-seg">${part}</span>`;
                } else {
                    html += `<a onclick="loadDirectory('${segPath}')">${part}</a>`;
                    html += `<span class="sep">/</span>`;
                }
            });

            html += `<span class="sep"> $</span>`;
            container.innerHTML = html;
        }

        function renderFiles(items) {
            const tbody = document.getElementById('file-list');
            tbody.innerHTML = '';
            items.forEach(item => {
                const tr = document.createElement('tr');
                const isDir = item.type === 'dir';
                const icon = isDir ? iconFolder : iconFile;

                let clickHandler;
                if (item.name === '..') {
                    const parent = parentPath(currentPath);
                    clickHandler = `onclick="loadDirectory('${parent}')"`;
                } else if (isDir) {
                    const child = joinPath(currentPath, item.name);
                    clickHandler = `onclick="loadDirectory('${child}')"`;
                } else {
                    clickHandler = `onclick="openFile('${item.name}')"`;
                }

                let actionsHtml = '';
                if (item.name !== '..') {
                    actionsHtml = `
                        <div class="item-actions" style="text-align: right;">
                            <button onclick="renameItem('${item.name}')" title="Rename">✏️</button>
                            ${!isDir ? `<button onclick="downloadFile('${item.name}')" title="Download">⬇️</button>` : ''}
                            <button onclick="deleteItem('${item.name}')" title="Delete" style="color: var(--danger);">🗑️</button>
                        </div>
                    `;
                }

                tr.innerHTML = `
                    <td>
                        <a class="file-name" ${clickHandler}>
                            <span class="icon">${icon}</span>
                            ${item.name}
                        </a>
                    </td>
                    <td>${formatSize(item.size)}</td>
                    <td>${formatDate(item.mtime)}</td>
                    <td>${actionsHtml}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        async function uploadFile(input) {
            const file = input.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('file', file);
            try {
                const response = await fetch(`?api=upload&path=${encodeURIComponent(currentPath)}`, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    loadDirectory(currentPath);
                } else {
                    alert('Upload failed: ' + data.error);
                }
            } catch (e) {
                alert('Upload error');
            }
            input.value = '';
        }

        async function promptCreateFolder() {
            const name = prompt('Folder name:');
            if (!name) return;
            const formData = new FormData();
            formData.append('name', name);
            const response = await fetch(`?api=create_folder&path=${encodeURIComponent(currentPath)}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                loadDirectory(currentPath);
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function deleteItem(name) {
            if (!confirm(`Are you sure you want to delete "${name}"?`)) return;
            const formData = new FormData();
            formData.append('name', name);
            const response = await fetch(`?api=delete&path=${encodeURIComponent(currentPath)}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                loadDirectory(currentPath);
            } else {
                alert('Error: ' + data.error);
            }
        }

        async function renameItem(oldName) {
            const newName = prompt('New name:', oldName);
            if (!newName || newName === oldName) return;
            const formData = new FormData();
            formData.append('old_name', oldName);
            formData.append('new_name', newName);
            const response = await fetch(`?api=rename&path=${encodeURIComponent(currentPath)}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                loadDirectory(currentPath);
            } else {
                alert('Error: ' + data.error);
            }
        }

        function downloadFile(name) {
            window.location.href = `?download=1&path=${encodeURIComponent(currentPath)}&name=${encodeURIComponent(name)}`;
        }

        async function openFile(name) {
            const ext = name.split('.').pop().toLowerCase();
            const editable = ['php', 'html', 'css', 'js', 'txt', 'md', 'json', 'xml', 'csv'].includes(ext);
            if (editable) {
                const response = await fetch(`?api=read&path=${encodeURIComponent(currentPath)}&name=${encodeURIComponent(name)}`);
                const data = await response.json();
                if (data.success) {
                    editingFileName = name;
                    document.getElementById('editor-title').innerText = `Editing: ${name}`;
                    document.getElementById('editor-content').value = data.content;
                    document.getElementById('editor-modal').style.display = 'flex';
                } else {
                    alert('Error reading file: ' + data.error);
                }
            } else {
                downloadFile(name);
            }
        }

        function closeEditor() {
            document.getElementById('editor-modal').style.display = 'none';
            editingFileName = '';
        }

        async function saveEditor() {
            if (!editingFileName) return;
            const content = document.getElementById('editor-content').value;
            const formData = new FormData();
            formData.append('name', editingFileName);
            formData.append('content', content);
            const response = await fetch(`?api=save&path=${encodeURIComponent(currentPath)}`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                closeEditor();
                alert('File saved successfully.');
            } else {
                alert('Error saving file: ' + data.error);
            }
        }

        // Always start in the directory where filemanager.php is placed
        loadDirectory('<?php echo realpath(__DIR__); ?>');
    </script>
</body>
</html>
