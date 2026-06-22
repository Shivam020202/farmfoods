<?php
// PHP built-in dev server router.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;
$path = $root . DIRECTORY_SEPARATOR . trim($uri, '/');

// 1) Real file or directory in project root? Serve it directly.
if ($uri !== '/' && file_exists($path) && !is_dir($path)) {
    return false;
}

// 2) Extensionless /api/* -> /api/*.php
if (strpos($uri, '/api/') === 0 && !preg_match('/\.[a-zA-Z0-9]+$/', $uri)) {
    $candidate = $root . DIRECTORY_SEPARATOR . trim($uri, '/') . '.php';
    $candidate = str_replace('\\', '/', $candidate);
    if (file_exists($candidate)) {
        // Let PHP handle it as if it were requested directly
        $_SERVER['SCRIPT_NAME'] = $uri . '.php';
        $_SERVER['SCRIPT_FILENAME'] = $candidate;
        require $candidate;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Not Found']);
    return true;
}

// 3) /dashboard -> /dashboard.html
if ($uri === '/dashboard' || $uri === '/dashboard.php') {
    require $root . '/dashboard.html';
    return true;
}

// 4) Fallback: SPA index.html
$indexPath = $root . '/index.html';
if (file_exists($indexPath)) {
    require $indexPath;
    return true;
}

return false;