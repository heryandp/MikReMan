<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/auth.php';
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
                
                <div class="tabs is-toggle is-fullwidth admin-tabs" role="tablist" aria-label="Admin Sections">
                    <ul>
                        <li class="is-active" data-admin-tab="mikrotik" role="presentation">
                            <a id="admin-tab-mikrotik-link" href="#admin-tab-mikrotik" role="tab" aria-controls="admin-tab-mikrotik" aria-selected="true">
                                <span class="icon is-small"><i class="bi bi-router" aria-hidden="true"></i></span>
                                <span>MikroTik</span>
                            </a>
                        </li>
                        <li data-admin-tab="auth" role="presentation">
                            <a id="admin-tab-auth-link" href="#admin-tab-auth" role="tab" aria-controls="admin-tab-auth" aria-selected="false">
                                <span class="icon is-small"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span>
                                <span>Login</span>
                            </a>
                        </li>
                        <li data-admin-tab="telegram" role="presentation">
                            <a id="admin-tab-telegram-link" href="#admin-tab-telegram" role="tab" aria-controls="admin-tab-telegram" aria-selected="false">
                                <span class="icon is-small"><i class="bi bi-telegram" aria-hidden="true"></i></span>
                                <span>Telegram</span>
                            </a>
                        </li>
                        <li data-admin-tab="vpn" role="presentation">
                            <a id="admin-tab-vpn-link" href="#admin-tab-vpn" role="tab" aria-controls="admin-tab-vpn" aria-selected="false">
                                <span class="icon is-small"><i class="bi bi-hdd-network-fill" aria-hidden="true"></i></span>
                                <span>VPN Services</span>
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="admin-tab-panels">
                    <section class="admin-tab-panel is-active" id="admin-tab-mikrotik" data-admin-panel="mikrotik" role="tabpanel" aria-labelledby="admin-tab-mikrotik-link">
                        <div class="card enhanced-card admin-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <span class="icon"><i class="bi bi-router" aria-hidden="true"></i></span>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">MikroTik Configuration</h5>
                                        <small class="card-subtitle">Router connection settings</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body admin-card-body">
                                <form id="mikrotik-form">
                                    <!-- CSRF Token -->
                                    <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                                    <div class="field">
                                        <label for="mt_host" class="label admin-label">IP MikroTik</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="mt_host" name="host" placeholder="192.168.1.1" required autocomplete="off" maxlength="253">
                                        </div>
                                    </div>

                                    <div class="columns is-variable is-4">
                                        <div class="column">
                                            <div class="field">
                                                <label for="mt_username" class="label admin-label">Username</label>
                                                <div class="control">
                                                    <input type="text" class="input admin-input" id="mt_username" name="username" placeholder="admin" required autocomplete="username" maxlength="50">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column">
                                            <div class="field">
                                                <label for="mt_password" class="label admin-label">Password</label>
                                                <div class="field has-addons admin-field-addons">
                                                    <div class="control is-expanded">
                                                        <input type="password" class="input admin-input" id="mt_password" name="password" placeholder="Password" autocomplete="current-password" maxlength="255">
                                                    </div>
                                                    <div class="control">
                                                        <button class="button is-dark is-outlined admin-addon-button" type="button" id="toggleMtPassword" onclick="toggleMikrotikPasswordVisibility()">
                                                            <span class="icon"><i class="bi bi-eye"></i></span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="field">
                                        <label for="mt_port" class="label admin-label">Port</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="mt_port" name="port" value="443" min="1" max="65535" autocomplete="off">
                                        </div>
                                    </div>

                                    <div class="profile-section">
                                        <div class="section-header">
                                            <h6 class="section-title">Docker Published Ports</h6>
                                        </div>
                                        <div class="columns is-multiline is-variable is-4">
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="rest_https_port" class="label admin-label">REST HTTPS</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="rest_https_port" name="rest_https_port" min="1" max="65535" placeholder="7005">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="rest_http_port" class="label admin-label">REST HTTP</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="rest_http_port" name="rest_http_port" min="1" max="65535" placeholder="7004">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="winbox_port" class="label admin-label">Winbox</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="winbox_port" name="winbox_port" min="1" max="65535" placeholder="7000">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="api_port" class="label admin-label">API</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="api_port" name="api_port" min="1" max="65535" placeholder="7001">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="api_ssl_port" class="label admin-label">API SSL</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="api_ssl_port" name="api_ssl_port" min="1" max="65535" placeholder="7002">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="ssh_port" class="label admin-label">SSH</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="ssh_port" name="ssh_port" min="1" max="65535" placeholder="7003">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                                <div class="field">
                                                    <label for="l2tp_port" class="label admin-label">L2TP</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="l2tp_port" name="l2tp_port" min="1" max="65535" placeholder="1701">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                                <div class="field">
                                                    <label for="pptp_port" class="label admin-label">PPTP</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="pptp_port" name="pptp_port" min="1" max="65535" placeholder="1723">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                                <div class="field">
                                                    <label for="sstp_port" class="label admin-label">SSTP</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="sstp_port" name="sstp_port" min="1" max="65535" placeholder="443">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                                <div class="field">
                                                    <label for="ipsec_port" class="label admin-label">IPsec IKE</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="ipsec_port" name="ipsec_port" min="1" max="65535" placeholder="500">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                                <div class="field">
                                                    <label for="ipsec_nat_t_port" class="label admin-label">IPsec NAT-T</label>
                                                    <div class="control">
                                                        <input type="number" class="input admin-input" id="ipsec_nat_t_port" name="ipsec_nat_t_port" min="1" max="65535" placeholder="4500">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="help has-text-grey-light">Gunakan port eksternal host Docker, bukan port internal RouterOS. Nilai ini dipakai untuk dokumentasi endpoint dan generator konfigurasi client.</p>
                                    </div>

                                    <div class="profile-section">
                                        <div class="section-header">
                                            <h6 class="section-title">Public Hostname Per Service</h6>
                                        </div>
                                        <div class="columns is-multiline is-variable is-4">
                                            <div class="column is-12-mobile is-4-tablet">
                                                <div class="field">
                                                    <label for="l2tp_host" class="label admin-label">L2TP Hostname</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="l2tp_host" name="l2tp_host" placeholder="l2tp.example.com">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-4-tablet">
                                                <div class="field">
                                                    <label for="pptp_host" class="label admin-label">PPTP Hostname</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="pptp_host" name="pptp_host" placeholder="pptp.example.com">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-4-tablet">
                                                <div class="field">
                                                    <label for="sstp_host" class="label admin-label">SSTP Hostname</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="sstp_host" name="sstp_host" placeholder="sstp.example.com">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="help has-text-grey-light">Kalau diisi, hostname ini akan dipakai untuk tester service dan generator konfigurasi client. Jika kosong, app memakai host utama MikroTik.</p>
                                    </div>

                                    <div class="profile-section">
                                        <div class="section-header">
                                            <h6 class="section-title">QEMU Dynamic Host Forward</h6>
                                        </div>
                                        <div class="field">
                                            <div class="control">
                                                <label class="checkbox admin-checkbox" for="qemu_hostfwd_enabled">
                                                    <input type="checkbox" id="qemu_hostfwd_enabled" name="qemu_hostfwd_enabled">
                                                    Enable runtime `hostfwd_add/remove` for random NAT ports
                                                </label>
                                            </div>
                                        </div>
                                        <div class="columns is-variable is-4">
                                            <div class="column is-12-mobile is-7-tablet">
                                                <div class="field">
                                                    <label for="qemu_hmp_socket" class="label admin-label">QEMU HMP Socket</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="qemu_hmp_socket" name="qemu_hmp_socket" placeholder="/opt/ros7-monitor/hmp.sock">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-5-tablet">
                                                <div class="field">
                                                    <label for="qemu_hostfwd_binary" class="label admin-label">socat Binary</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="qemu_hostfwd_binary" name="qemu_hostfwd_binary" placeholder="/usr/bin/socat">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="help has-text-grey-light">Gunakan ini hanya untuk deployment CHR berbasis QEMU `user,hostfwd`. App akan menambah forward `external-port -> guest external-port` saat NAT dibuat, lalu menghapusnya kembali saat NAT dihapus.</p>
                                    </div>

                                    <div class="buttons admin-button-group">
                                        <button type="submit" class="button is-primary admin-action-button">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Save</span>
                                        </button>
                                        <button type="button" class="button is-info is-light admin-action-button" id="test-connection">
                                            <i class="bi bi-plug"></i>
                                            <span class="is-hidden-mobile">Test</span>
                                            <span class="is-hidden-tablet">Test</span>
                                        </button>
                                        <button type="button" class="button is-success admin-action-button" id="connect-mikrotik" onclick="handleConnectClick()">
                                            <i class="bi bi-link-45deg"></i>
                                            <span class="is-hidden-mobile" id="connect-text">Connect</span>
                                            <span class="is-hidden-tablet" id="connect-text-mobile">Link</span>
                                        </button>
                                        <button type="button" class="button is-primary is-light admin-action-button" id="ssl-toggle" data-ssl="true">
                                            <i class="bi bi-shield-lock"></i>
                                            <span class="is-hidden-mobile">HTTPS/SSL</span>
                                            <span class="is-hidden-tablet">SSL</span>
                                        </button>
                                    </div>

                                    <!-- Connection Status Indicator -->
                                    <div class="admin-connection-status is-hidden" id="connection-status">
                                        <div class="notification is-success admin-notification admin-status-notification" role="alert">
                                            <span class="admin-alert-icon"><i class="bi bi-check-circle-fill"></i></span>
                                            <div>
                                                <strong>Connected to MikroTik</strong>
                                                <br>
                                                <small id="connection-info">Router: Loading...</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Hidden input to maintain form compatibility -->
                                    <input type="hidden" id="mt_use_ssl" name="use_ssl" value="true">
                                </form>
                            </div>
                        </div>
                    </section>

                    <section class="admin-tab-panel" id="admin-tab-auth" data-admin-panel="auth" role="tabpanel" aria-labelledby="admin-tab-auth-link">
                        <div class="card enhanced-card admin-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <span class="icon"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">Login Settings</h5>
                                        <small class="card-subtitle">Web interface credentials</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body admin-card-body">
                                <form id="auth-form">
                                    <!-- CSRF Token -->
                                    <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                                    <div class="field">
                                        <label for="auth_username" class="label admin-label">Username</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="auth_username" name="username" placeholder="Enter new username or leave empty to keep current" autocomplete="username" maxlength="50">
                                        </div>
                                    </div>

                                    <div class="field">
                                        <label for="auth_password" class="label admin-label">Password</label>
                                        <div class="field has-addons admin-field-addons">
                                            <div class="control is-expanded">
                                                <input type="password" class="input admin-input" id="auth_password" name="password" placeholder="" autocomplete="new-password" maxlength="255">
                                            </div>
                                            <div class="control">
                                                <button class="button is-dark is-outlined admin-addon-button" type="button" id="toggleAuthPassword" onclick="togglePasswordVisibility()">
                                                    <span class="icon"><i class="bi bi-eye"></i></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" class="button is-warning admin-action-button">
                                        <i class="bi bi-shield-check"></i>
                                        Update Login
                                    </button>
                                </form>
                            </div>
                        </div>
                    </section>

                    <section class="admin-tab-panel" id="admin-tab-telegram" data-admin-panel="telegram" role="tabpanel" aria-labelledby="admin-tab-telegram-link">
                        <div class="card enhanced-card admin-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon telegram-icon">
                                        <span class="icon"><i class="bi bi-telegram" aria-hidden="true"></i></span>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">Telegram Bot</h5>
                                        <small class="card-subtitle">Backup notification settings</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body admin-card-body">
                                <form id="telegram-form">
                                    <!-- CSRF Token -->
                                    <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                                    <div class="field">
                                        <label for="bot_token" class="label admin-label">Bot Token</label>
                                        <div class="field has-addons admin-field-addons">
                                            <div class="control is-expanded">
                                                <input type="password" class="input admin-input" id="bot_token" name="bot_token" placeholder="123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" autocomplete="off" maxlength="500">
                                            </div>
                                            <div class="control">
                                                <button class="button is-dark is-outlined admin-addon-button" type="button" id="toggleBotToken">
                                                    <span class="icon"><i class="bi bi-eye"></i></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="field">
                                        <label for="chat_id" class="label admin-label">Chat ID</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="chat_id" name="chat_id" placeholder="-123456789" autocomplete="off" maxlength="20">
                                        </div>
                                    </div>

                                    <div class="field">
                                        <div class="control">
                                            <label class="checkbox admin-checkbox" for="telegram_enabled">
                                                <input type="checkbox" id="telegram_enabled" name="enabled">
                                                <span>Enable</span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="buttons admin-button-group">
                                        <button type="submit" class="button is-success admin-action-button">
                                            <i class="bi bi-check-lg"></i>
                                            <span>Save</span>
                                        </button>
                                        <button type="button" class="button is-info is-light admin-action-button" id="test-telegram">
                                            <i class="bi bi-send"></i>
                                            <span class="is-hidden-mobile">Test Bot</span>
                                            <span class="is-hidden-tablet">Test</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </section>

                    <section class="admin-tab-panel" id="admin-tab-vpn" data-admin-panel="vpn" role="tabpanel" aria-labelledby="admin-tab-vpn-link">
                        <div class="card enhanced-card admin-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <span class="icon"><i class="bi bi-hdd-network-fill" aria-hidden="true"></i></span>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">VPN Services</h5>
                                        <small class="card-subtitle">Server management & control</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body admin-card-body">
                                <!-- Service Toggle Cards -->
                                <div class="columns is-multiline admin-service-grid">
                                    <div class="column is-12-mobile is-4-tablet">
                                        <div class="service-card">
                                            <div class="service-header">
                                                <i class="bi bi-shield-fill-check service-icon l2tp"></i>
                                                <h6 class="service-name">L2TP Server</h6>
                                            </div>
                                            <div class="buttons service-actions">
                                                <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-l2tp" data-service="l2tp">
                                                    <i class="bi bi-power"></i>
                                                    <span>Enable</span>
                                                </button>
                                                <button class="button is-info is-light service-btn admin-service-test" id="test-l2tp" data-service="l2tp">
                                                    <i class="bi bi-search"></i>
                                                    <span>Test</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="column is-12-mobile is-4-tablet">
                                        <div class="service-card">
                                            <div class="service-header">
                                                <i class="bi bi-shield-fill-plus service-icon pptp"></i>
                                                <h6 class="service-name">PPTP Server</h6>
                                            </div>
                                            <div class="buttons service-actions">
                                                <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-pptp" data-service="pptp">
                                                    <i class="bi bi-power"></i>
                                                    <span>Enable</span>
                                                </button>
                                                <button class="button is-info is-light service-btn admin-service-test" id="test-pptp" data-service="pptp">
                                                    <i class="bi bi-search"></i>
                                                    <span>Test</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="column is-12-mobile is-4-tablet">
                                        <div class="service-card">
                                            <div class="service-header">
                                                <i class="bi bi-shield-fill-exclamation service-icon sstp"></i>
                                                <h6 class="service-name">SSTP Server</h6>
                                            </div>
                                            <div class="buttons service-actions">
                                                <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-sstp" data-service="sstp">
                                                    <i class="bi bi-power"></i>
                                                    <span>Enable</span>
                                                </button>
                                                <button class="button is-info is-light service-btn admin-service-test" id="test-sstp" data-service="sstp">
                                                    <i class="bi bi-search"></i>
                                                    <span>Test</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <hr class="admin-divider">

                                <!-- Profile Management -->
                                <div class="profile-section">
                                    <div class="section-header">
                                        <h6 class="section-title">Service Profiles</h6>
                                    </div>
                                    <div class="columns is-mobile is-multiline admin-profile-grid">
                                        <div class="column is-12-mobile is-4-tablet">
                                            <button class="button is-link is-light is-small is-fullwidth profile-btn" id="create-l2tp-profile" data-service="l2tp">
                                                <i class="bi bi-plus-circle"></i>
                                                L2TP Profile
                                            </button>
                                        </div>
                                        <div class="column is-12-mobile is-4-tablet">
                                            <button class="button is-link is-light is-small is-fullwidth profile-btn" id="create-pptp-profile" data-service="pptp">
                                                <i class="bi bi-plus-circle"></i>
                                                PPTP Profile
                                            </button>
                                        </div>
                                        <div class="column is-12-mobile is-4-tablet">
                                            <button class="button is-link is-light is-small is-fullwidth profile-btn" id="create-sstp-profile" data-service="sstp">
                                                <i class="bi bi-plus-circle"></i>
                                                SSTP Profile
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- NAT Management -->
                                <div class="nat-section">
                                    <div class="section-header">
                                        <h6 class="section-title">NAT Configuration</h6>
                                    </div>
                                    <div class="columns is-mobile is-multiline admin-profile-grid">
                                        <div class="column is-12-mobile is-6-tablet">
                                            <button class="button is-warning is-light is-small is-fullwidth" id="create-nat-masquerade">
                                                <i class="bi bi-router"></i>
                                                NAT Masquerade
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Quick Actions -->
                                <div class="quick-actions">
                                    <div class="section-header">
                                        <h6 class="section-title">Quick Actions</h6>
                                    </div>
                                    <div class="buttons">
                                        <button class="button is-info admin-action-button admin-wide-button" id="backup-config">
                                            <i class="bi bi-cloud-download"></i>
                                            <span class="is-hidden-mobile">Backup Config</span>
                                            <span class="is-hidden-tablet">Backup</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
        </main>
    </div>

    <script src="../assets/js/admin.js"></script>

    <script>
        // Security configurations
        window.APP_CONFIG = {
            CSRF_TOKEN: <?php echo sanitizeOutput($csrf_token, 'js'); ?>,
            MAX_RETRIES: 3,
            TIMEOUT: 30000
        };

        document.addEventListener('click', (event) => {
            if (event.target.classList.contains('delete')) {
                event.target.parentElement?.remove();
            }
        });

        function showAdminErrorAlert(title, text) {
            if (window.AppSwal) {
                window.AppSwal.alert({ title, text, icon: 'error' });
                return;
            }

            alert(`${title}: ${text}`);
        }

        // Global function to handle Connect button click
        async function handleConnectClick() {
            // Try to initialize if not already done
            if (!window.adminPanelInstance && typeof window.initializeAdminPanel === 'function') {
                window.initializeAdminPanel();
            }

            // Wait for AdminPanel to be initialized
            if (window.adminPanelInstance) {
                window.adminPanelInstance.connectMikrotik();
            } else {
                setTimeout(() => {
                    if (window.adminPanelInstance) {
                        window.adminPanelInstance.connectMikrotik();
                    } else {
                        showAdminErrorAlert('Admin Panel Error', 'AdminPanel not initialized. Please refresh the page (Ctrl+Shift+R).');
                    }
                }, 200);
            }
        }

        // Global function to toggle password visibility
        async function togglePasswordVisibility() {
            const passwordInput = document.getElementById('auth_password');
            const usernameInput = document.getElementById('auth_username');
            const toggleBtn = document.getElementById('toggleAuthPassword');
            const eyeIcon = toggleBtn.querySelector('i');
            
            if (!passwordInput || !toggleBtn || !eyeIcon) {
                console.error('Missing elements');
                return;
            }
            
            // If password field shows bullets, fetch current password first
            if (passwordInput.value === '••••••••') {
                const originalBtnContent = toggleBtn.innerHTML;
                toggleBtn.disabled = true;
                toggleBtn.innerHTML = '<span class="icon"><i class="bi bi-arrow-repeat spin"></i></span>';
                
                try {
                    const response = await fetch('../api/config.php?action=get_auth_credentials', {
                        headers: {
                            'X-CSRF-Token': window.APP_CONFIG?.CSRF_TOKEN || ''
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    
                    const result = await response.json();
                    
                    if (result.success && result.credentials) {
                        // Fill the fields with current credentials
                        if (usernameInput && !usernameInput.value) {
                            usernameInput.value = result.credentials.username;
                        }
                        passwordInput.value = result.credentials.password;
                        
                        // Update JavaScript userPasswords object
                        if (window.adminPanel && window.adminPanel.userPasswords) {
                            window.adminPanel.userPasswords.auth = result.credentials.password;
                        }
                        
                        // Show password
                        passwordInput.type = 'text';
                        eyeIcon.className = 'bi bi-eye-slash';
                    } else {
                        showAdminErrorAlert('Failed to Load Credentials', result.message || 'Unknown error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAdminErrorAlert('Credential Error', error.message);
                } finally {
                    toggleBtn.disabled = false;
                    toggleBtn.innerHTML = originalBtnContent;
                }
            } else {
                // Toggle visibility of existing password
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.className = 'bi bi-eye-slash';
                } else {
                    // Hide password - change back to bullets and password type
                    passwordInput.value = '';  // Clear first
                    passwordInput.type = 'password';
                    // Use setTimeout to ensure type change is processed first
                    setTimeout(() => {
                        passwordInput.value = '••••••••';
                    }, 10);
                    eyeIcon.className = 'bi bi-eye';
                }
            }
        }
        
        // Global function to toggle MikroTik password visibility
        async function toggleMikrotikPasswordVisibility() {
            
            const passwordInput = document.getElementById('mt_password');
            const toggleBtn = document.getElementById('toggleMtPassword');
            const eyeIcon = toggleBtn.querySelector('i');
            
            if (!passwordInput || !toggleBtn || !eyeIcon) {
                console.error('Missing elements');
                return;
            }
            
            // If password field is empty or shows bullets, fetch current password first
            if (!passwordInput.value || passwordInput.value === '••••••••') {
                const originalBtnContent = toggleBtn.innerHTML;
                toggleBtn.disabled = true;
                toggleBtn.innerHTML = '<span class="icon"><i class="bi bi-arrow-repeat spin"></i></span>';
                
                try {
                    const response = await fetch('../api/config.php?action=get_mikrotik_credentials', {
                        headers: {
                            'X-CSRF-Token': window.APP_CONFIG?.CSRF_TOKEN || ''
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    
                    const result = await response.json();
                    
                    if (result.success && result.credentials) {
                        if (result.credentials.password && !result.credentials.password_masked) {
                            // Set actual password value
                            passwordInput.value = result.credentials.password;
                            
                            // Show password
                            passwordInput.type = 'text';
                            eyeIcon.className = 'bi bi-eye-slash';
                            
                        } else {
                            // Password is masked or empty - let user enter new password
                            passwordInput.value = '';
                            passwordInput.type = 'text';
                            passwordInput.placeholder = 'Enter your MikroTik router password';
                            passwordInput.focus();
                            eyeIcon.className = 'bi bi-eye-slash';
                            
                        }
                    } else {
                        // API failed - let user enter password
                        passwordInput.value = '';
                        passwordInput.type = 'text';
                        passwordInput.placeholder = 'Enter your MikroTik router password';
                        passwordInput.focus();
                        eyeIcon.className = 'bi bi-eye-slash';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAdminErrorAlert('Credential Error', error.message);
                } finally {
                    toggleBtn.disabled = false;
                    toggleBtn.innerHTML = originalBtnContent;
                }
            } else {
                // Toggle visibility of existing password
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.className = 'bi bi-eye-slash';
                } else {
                    // Hide password - change back to bullets and password type
                    passwordInput.value = '••••••••';
                    passwordInput.type = 'password';
                    eyeIcon.className = 'bi bi-eye';
                }
            }
        }
    </script>
</body>
</html>
