<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/admin_ui.php';
require_once '../includes/admin_script.php';
require_once '../includes/ui.php';

// Constants
define('SESSION_TIMEOUT', 3600); // 60 minutes
define('MAX_FORM_ATTEMPTS', 10);
define('FORM_LOCKOUT_TIME', 300); // 5 minutes

// Security functions
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sanitizeOutput($data, $context = 'html') {
    if (is_array($data)) {
        return array_map(function($item) use ($context) {
            return sanitizeOutput($item, $context);
        }, $data);
    }
    
    switch ($context) {
        case 'html':
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        case 'js':
            return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        case 'url':
            return urlencode($data);
        default:
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}

// Check authentication
checkSession();

// Session security check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?timeout=1');
    exit;
}

// Regenerate session ID periodically for security
if (!isset($_SESSION['created']) || (time() - $_SESSION['created']) > 1800) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Update last activity
$_SESSION['last_activity'] = time();

// Page variables
$current_page = 'admin';
$page_title = 'Admin';
$page_subtitle = 'Manage MikroTik settings and system configuration';

// Generate CSRF token
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo sanitizeOutput($page_title); ?> - Management</title>
    <?php renderThemeBootScript(); ?>
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderSweetAlertAssets('..'); ?>
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php renderThemeScript('../assets/js/theme.js'); ?>
</head>
<body class="admin-body">
    <div class="app-shell">
        <?php renderAppNavbar($current_page); ?>

        <main class="main-content topbar-main-content">
                <?php renderPageHeader('bi bi-gear-fill', $page_title, $page_subtitle); ?>
                
                <div id="alerts-container">
                    <?php if (isset($_SESSION['dashboard_error'])): ?>
                        <div class="notification is-warning admin-notification" role="alert">
                            <button type="button" class="delete" aria-label="Close"></button>
                            <span class="admin-alert-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                            <strong>Dashboard Access:</strong> <?php echo sanitizeOutput($_SESSION['dashboard_error']); ?>
                        </div>
                        <?php unset($_SESSION['dashboard_error']); ?>
                    <?php endif; ?>
                </div>
                
                <?php renderAdminTabsAndPanels($csrf_token); ?>
        </main>
    </div>

    <?php renderAdminPageScript($csrf_token); ?>
</body>
</html>
