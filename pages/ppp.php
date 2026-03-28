<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/mikrotik.php';
require_once '../includes/ppp_ui.php';
require_once '../includes/ppp_script.php';
require_once '../includes/ui.php';

// Constants
define('SESSION_TIMEOUT', 3600); // 60 minutes

// Check authentication
checkSession();

// Page info
$current_page = 'ppp';
$page_title = 'PPP Users';
$page_subtitle = 'Manage MikroTik PPP users and connections';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Check if MikroTik configuration exists
$mikrotik_config = getConfig('mikrotik');
if (!$mikrotik_config || empty($mikrotik_config['host']) || empty($mikrotik_config['username'])) {
    // Redirect to admin with message
    $_SESSION['ppp_error'] = 'MikroTik configuration is required. Please configure your router settings first.';
    header('Location: admin.php');
    exit;
}

// Test MikroTik connection
try {
    $mikrotik = new MikroTikAPI($mikrotik_config);
    $test_result = $mikrotik->getSystemResource();
    if (!$test_result) {
        throw new Exception('Cannot connect to MikroTik router');
    }
} catch (Exception $e) {
    $_SESSION['ppp_error'] = 'Cannot connect to MikroTik router. Please check your credentials and network connection.';
    header('Location: admin.php');
    exit;
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
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitizeOutput($page_title); ?> - VPN Remote</title>
    <?php renderThemeBootScript(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderSweetAlertAssets('..'); ?>
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php renderThemeScript('../assets/js/theme.js'); ?>
</head>
<body class="admin-body">
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <span class="icon"><i class="bi bi-arrow-repeat spin"></i></span>
            <div class="loading-label">Loading...</div>
        </div>
    </div>

    <div class="app-shell">
        <?php renderAppNavbar($current_page); ?>
            
            <!-- Main Content -->
            <main class="main-content topbar-main-content">
                <?php renderPageHeader('bi bi-people-fill', $page_title, $page_subtitle); ?>
                
                <div id="alerts-container"></div>
                
                <!-- PPP Statistics Cards -->
                <div class="columns is-multiline is-variable is-4 page-card-grid">
                    <div class="column is-12-mobile is-4-tablet is-4-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-value" id="total-users">-</div>
                                    <div class="stat-label">Total Users</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-4-tablet is-4-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-value has-text-success" id="online-users">-</div>
                                    <div class="stat-label">Online Users</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-4-tablet is-4-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-value has-text-warning" id="offline-users">-</div>
                                    <div class="stat-label">Offline Users</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Search and Filters -->
                <div class="search-filters ppp-filter-shell">
                    <div class="columns is-multiline is-variable is-4">
                        <div class="column is-12-mobile is-6-tablet is-4-desktop">
                            <div class="field">
                                <label for="searchInput" class="label admin-label">Search Users</label>
                                <div class="control has-icons-left">
                                    <input type="text" class="input admin-input" id="searchInput" placeholder="Search by username or IP">
                                    <span class="icon is-left"><i class="bi bi-search"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12-mobile is-6-tablet is-3-desktop">
                            <div class="field">
                                <label for="serviceFilter" class="label admin-label">Service</label>
                                <div class="control">
                                    <div class="select is-fullwidth">
                                        <select id="serviceFilter">
                                    <option value="">All Services</option>
                                    <option value="l2tp">L2TP</option>
                                    <option value="pptp">PPTP</option>
                                    <option value="sstp">SSTP</option>
                                    <option value="any">Any</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12-mobile is-6-tablet is-3-desktop">
                            <div class="field">
                                <label for="statusFilter" class="label admin-label">Status</label>
                                <div class="control">
                                    <div class="select is-fullwidth">
                                        <select id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="enabled">Enabled</option>
                                    <option value="disabled">Disabled</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12-mobile is-6-tablet is-2-desktop is-flex is-align-items-flex-end">
                            <button class="button is-dark is-outlined admin-action-button is-fullwidth" onclick="clearFilters()">
                                <i class="bi bi-x-circle"></i>
                                <span>Clear</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <div class="bulk-actions" id="bulkActions">
                    <div class="ppp-bulk-actions-bar is-flex is-justify-content-space-between is-align-items-center is-flex-direction-column-mobile is-align-items-flex-start-mobile">
                        <div class="has-text-weight-semibold">
                            <span id="selectedCount">0</span> users selected
                        </div>
                        <div class="buttons ppp-buttons-inline">
                            <button class="button is-warning is-small admin-action-button" onclick="bulkToggleStatus()">
                                <i class="bi bi-toggle-off"></i>
                                <span class="is-hidden-mobile">Toggle Status</span>
                                <span class="is-hidden-tablet">Toggle</span>
                            </button>
                            <button class="button is-danger is-small admin-action-button" onclick="bulkDeleteUsers()">
                                <i class="bi bi-trash"></i>
                                <span class="is-hidden-mobile">Delete Selected</span>
                                <span class="is-hidden-tablet">Delete</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Users Table -->
                <div class="card users-table app-table-shell">
                    <div class="card-header admin-card-header ppp-table-header">
                        <div class="card-header-content">
                            <div class="card-icon">
                                <i class="bi bi-person-lines-fill"></i>
                            </div>
                            <div class="card-title-group">
                                <h5 class="card-title">PPP Users</h5>
                                <small class="card-subtitle">Manage your MikroTik PPP users</small>
                            </div>
                        </div>
                        <button class="button is-primary admin-action-button" type="button" data-open-modal="addUserModal">
                            <i class="bi bi-plus-circle"></i>
                            <span class="is-hidden-mobile">Add User</span>
                            <span class="is-hidden-tablet">Add</span>
                        </button>
                    </div>
                    <div class="table-container app-table-wrapper">
                            <table class="table is-fullwidth is-hoverable app-table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th scope="col" width="40">
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header" data-sort="name" onclick="pppManager.sortUsers('name')">
                                            Name
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header has-text-centered" data-sort="service" onclick="pppManager.sortUsers('service')">
                                            Service
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header has-text-centered" data-sort="remote-address" onclick="pppManager.sortUsers('remote-address')">
                                            Local IP
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header is-hidden-touch has-text-centered" data-sort="last-caller-id" onclick="pppManager.sortUsers('last-caller-id')">
                                            Caller ID
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header has-text-centered" data-sort="disabled" onclick="pppManager.sortUsers('disabled')">
                                            Status
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header is-hidden-touch has-text-centered" data-sort="mode" onclick="pppManager.sortUsers('mode')">
                                            Mode
                                        </th>
                                        <th scope="col" class="sort-header ppp-sort-header is-hidden-touch has-text-centered" data-sort="traffic" onclick="pppManager.sortUsers('traffic')">
                                            Traffic
                                        </th>
                                        <th scope="col" width="200">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTableBody">
                                    <tr>
                                        <td colspan="9" class="has-text-centered">
                                            <div class="app-empty-state">
                                                <span class="icon"><i class="bi bi-hourglass-split has-text-info"></i></span>
                                                <p>Loading users...</p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                    </div>
                </div>
            </main>
    </div>

    <?php renderPPPModals(); ?>
    
    <?php renderPPPScript($mikrotik_config, SESSION_TIMEOUT); ?>
</body>
</html>
