<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/mikrotik.php';
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
                    <div class="column is-12-mobile is-4-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-value" id="total-users">-</div>
                                    <div class="stat-label">Total Users</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-4-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-value has-text-success" id="online-users">-</div>
                                    <div class="stat-label">Online Users</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-4-desktop">
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
                        <div class="column is-12-mobile is-4-desktop">
                            <div class="field">
                                <label for="searchInput" class="label admin-label">Search Users</label>
                                <div class="control has-icons-left">
                                    <input type="text" class="input admin-input" id="searchInput" placeholder="Search by username or IP">
                                    <span class="icon is-left"><i class="bi bi-search"></i></span>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12-mobile is-3-desktop">
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
                        <div class="column is-12-mobile is-3-desktop">
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
                        <div class="column is-12-mobile is-2-desktop is-flex is-align-items-flex-end">
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

    <!-- Add User Modal -->
    <div class="modal" id="addUserModal" role="dialog" aria-modal="true" aria-labelledby="addUserModalTitle">
        <div class="modal-background" data-close-modal="addUserModal"></div>
        <form class="modal-card app-modal-card is-modal-medium" id="addUserForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="addUserModalTitle">
                    <span class="icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                    <span>Add PPP User</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="addUserModal"></button>
            </header>
                <section class="modal-card-body app-modal-body">
                    <div class="columns is-multiline is-variable is-4">
                        <div class="column is-12-mobile is-6-tablet">
                            <div class="field">
                                <label for="userName" class="label admin-label">Username</label>
                                <div class="field has-addons admin-field-addons">
                                    <div class="control is-expanded">
                                        <input type="text" class="input admin-input" id="userName" name="name" required>
                                    </div>
                                    <div class="control">
                                        <button type="button" class="button is-info is-light admin-addon-button" onclick="generateRandomName()" title="Generate Random Username">
                                            <span class="icon"><i class="bi bi-shuffle"></i></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12-mobile is-6-tablet">
                            <div class="field">
                                <label for="userPassword" class="label admin-label">Password</label>
                                <div class="field has-addons admin-field-addons">
                                    <div class="control is-expanded">
                                        <input type="password" class="input admin-input" id="userPassword" name="password" required>
                                    </div>
                                    <div class="control">
                                        <button type="button" class="button is-info is-light admin-addon-button" onclick="generateRandomPassword()" title="Generate Random Password">
                                            <span class="icon"><i class="bi bi-shuffle"></i></span>
                                        </button>
                                    </div>
                                    <div class="control">
                                        <button type="button" class="button is-dark is-outlined admin-addon-button" onclick="togglePassword('userPassword')" title="Show/Hide Password">
                                            <span class="icon"><i class="bi bi-eye" id="userPasswordIcon"></i></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12-mobile is-6-tablet">
                            <div class="field">
                                <label for="userService" class="label admin-label">Service</label>
                                <div class="control">
                                    <div class="select is-fullwidth">
                                        <select id="userService" name="service" required>
                                        <option value="">Select Service</option>
                                        <option value="l2tp">L2TP</option>
                                        <option value="pptp">PPTP</option>
                                        <option value="sstp">SSTP</option>
                                        <option value="any">Any</option>
                                        </select>
                                    </div>
                                </div>
                                <p class="help has-text-grey-light">The PPP profile will follow the selected service.</p>
                            </div>
                        </div>
                        <input type="hidden" id="userRemoteAddress" name="remote_address">
                        <div class="column is-12-mobile is-6-tablet">
                            <div class="field">
                                <label class="label admin-label">Forwarded Ports</label>
                                <input type="hidden" id="userPorts" name="ports">
                                <div class="ppp-port-builder">
                                    <div class="ppp-port-list" id="userPortsList"></div>
                                    <div class="buttons ppp-port-presets">
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="80">
                                            <span>HTTP 80</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="443">
                                            <span>HTTPS 443</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8291">
                                            <span>Winbox 8291</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8728">
                                            <span>API 8728</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="22">
                                            <span>SSH 22</span>
                                        </button>
                                    </div>
                                    <div class="buttons ppp-port-actions">
                                        <button type="button" class="button is-link is-light is-small admin-action-button" id="addUserPortButton">
                                            <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                                            <span>Add Port</span>
                                        </button>
                                    </div>
                                </div>
                                <p class="help has-text-grey-light">Each internal port listed here gets its own random public port that forwards to the VPN client router. From that router, you can forward traffic again to local devices such as AP1 or AP2. If left empty, the app uses the defaults 8291 (Winbox) and 8728 (API).</p>
                            </div>
                        </div>
                        <div class="column is-12">
                            <div class="field">
                                <div class="control">
                                    <label class="checkbox admin-checkbox" for="createNatRule">
                                        <input type="checkbox" id="createNatRule" checked>
                                        <span>Create public NAT mapping</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12 ppp-multi-port-options" id="multiPortOptions">
                            <div class="field">
                                <div class="control">
                                    <label class="checkbox admin-checkbox" for="createMultipleNat">
                                        <input type="checkbox" id="createMultipleNat">
                                        <span>Create one random public port for each internal port</span>
                                    </label>
                                </div>
                                <p class="help has-text-grey-light">Enable this if you want each internal port to receive a different random public port, for example for AP1, AP2, and other devices behind the client router.</p>
                            </div>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot app-modal-foot">
                    <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="addUserModal">Cancel</button>
                    <button type="submit" class="button is-primary admin-action-button">
                        <span class="icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                        <span>Create User</span>
                    </button>
                </footer>
        </form>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal" role="dialog" aria-modal="true" aria-labelledby="editUserModalTitle">
        <div class="modal-background" data-close-modal="editUserModal"></div>
        <form class="modal-card app-modal-card is-modal-compact" id="editUserForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="editUserModalTitle">
                    <span class="icon"><i class="bi bi-pencil" aria-hidden="true"></i></span>
                    <span>Edit PPP User</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="editUserModal"></button>
            </header>
                <section class="modal-card-body app-modal-body">
                    <input type="hidden" id="editUserId" name="user_id">
                    <input type="hidden" id="editExistingNatSnapshot" name="existing_nat_snapshot">
                    <div class="columns is-multiline is-variable is-4">
                        <div class="column is-12">
                            <div class="field">
                                <label for="editUserName" class="label admin-label">Username</label>
                                <div class="control">
                                    <input type="text" class="input admin-input" id="editUserName" name="name" required>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12">
                            <div class="field">
                                <label for="editUserService" class="label admin-label">Service</label>
                                <div class="control">
                                    <div class="select is-fullwidth">
                                        <select id="editUserService" name="service" required>
                                            <option value="l2tp">L2TP</option>
                                            <option value="pptp">PPTP</option>
                                            <option value="sstp">SSTP</option>
                                            <option value="any">Any</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="column is-12">
                            <div class="field">
                                <label for="editUserRemoteAddress" class="label admin-label">Remote Address (IP)</label>
                                <div class="control">
                                    <input type="text" class="input admin-input" id="editUserRemoteAddress" name="remote_address"
                                           pattern="^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$">
                                </div>
                            </div>
                        </div>
                        <div class="column is-12">
                            <div class="field">
                                <div class="control">
                                    <label class="checkbox admin-checkbox" for="editSyncNatRule">
                                        <input type="checkbox" id="editSyncNatRule" name="sync_nat_ports">
                                        <span>Sync public NAT mappings</span>
                                    </label>
                                </div>
                                <p class="help has-text-grey-light">Enable this to add, remove, or refresh the public port mappings attached to this PPP user. If disabled, existing NAT mappings stay untouched.</p>
                            </div>
                        </div>
                        <div class="column is-12" id="editNatMappingSection">
                            <div class="field">
                                <label class="label admin-label">Forwarded Ports</label>
                                <input type="hidden" id="editUserPorts" name="ports">
                                <div class="ppp-port-builder">
                                    <div class="ppp-port-list" id="editUserPortsList"></div>
                                    <div class="buttons ppp-port-presets">
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="80" data-port-target="edit">
                                            <span>HTTP 80</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="443" data-port-target="edit">
                                            <span>HTTPS 443</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8291" data-port-target="edit">
                                            <span>Winbox 8291</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8728" data-port-target="edit">
                                            <span>API 8728</span>
                                        </button>
                                        <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="22" data-port-target="edit">
                                            <span>SSH 22</span>
                                        </button>
                                    </div>
                                    <div class="buttons ppp-port-actions">
                                        <button type="button" class="button is-link is-light is-small admin-action-button" id="addEditUserPortButton">
                                            <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                                            <span>Add Port</span>
                                        </button>
                                    </div>
                                </div>
                                <p class="help has-text-grey-light">Ports kept in this list preserve their current public port when possible. Removed ports delete their public mapping, and new ports receive a new random public port.</p>
                            </div>
                        </div>
                        <div class="column is-12 ppp-multi-port-options" id="editMultiPortOptions">
                            <div class="field">
                                <div class="control">
                                    <label class="checkbox admin-checkbox" for="editCreateMultipleNat">
                                        <input type="checkbox" id="editCreateMultipleNat" name="createMultipleNat">
                                        <span>Create one random public port for each internal port</span>
                                    </label>
                                </div>
                                <p class="help has-text-grey-light">Use per-port comments when you want each forwarded service to remain clearly labeled in RouterOS.</p>
                            </div>
                        </div>
                    </div>
                </section>
                <footer class="modal-card-foot app-modal-foot">
                    <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="editUserModal">Cancel</button>
                    <button type="submit" class="button is-warning admin-action-button">
                        <span class="icon"><i class="bi bi-pencil" aria-hidden="true"></i></span>
                        <span>Update User</span>
                    </button>
                </footer>
        </form>
    </div>

    <!-- User Details Modal -->
    <div class="modal" id="userDetailsModal" role="dialog" aria-modal="true" aria-labelledby="userDetailsModalTitle">
        <div class="modal-background" data-close-modal="userDetailsModal"></div>
        <div class="modal-card app-modal-card is-modal-large">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="userDetailsModalTitle">
                    <span class="icon"><i class="bi bi-info-circle" aria-hidden="true"></i></span>
                    <span>User Details</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="userDetailsModal"></button>
            </header>
            <section class="modal-card-body app-modal-body" id="userDetailsContent">
                    <!-- Details will be loaded here -->
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="userDetailsModal">Close</button>
            </footer>
        </div>
    </div>
    
    <!-- PPP Users JavaScript -->
    <script>
        window.PPP_APP_CONFIG = {
            serverHost: <?php echo sanitizeOutput($mikrotik_config['host'] ?? '', 'js'); ?>,
            externalPorts: <?php echo json_encode([
                'rest_http_port' => $mikrotik_config['rest_http_port'] ?? '7004',
                'rest_https_port' => $mikrotik_config['rest_https_port'] ?? '7005',
                'winbox_port' => $mikrotik_config['winbox_port'] ?? '7000',
                'api_port' => $mikrotik_config['api_port'] ?? '7001',
                'api_ssl_port' => $mikrotik_config['api_ssl_port'] ?? '7002',
                'ssh_port' => $mikrotik_config['ssh_port'] ?? '7003',
                'l2tp_port' => $mikrotik_config['l2tp_port'] ?? '1701',
                'l2tp_host' => $mikrotik_config['l2tp_host'] ?? '',
                'pptp_port' => $mikrotik_config['pptp_port'] ?? '1723',
                'pptp_host' => $mikrotik_config['pptp_host'] ?? '',
                'sstp_port' => $mikrotik_config['sstp_port'] ?? '443',
                'sstp_host' => $mikrotik_config['sstp_host'] ?? '',
                'ipsec_port' => $mikrotik_config['ipsec_port'] ?? '500',
                'ipsec_nat_t_port' => $mikrotik_config['ipsec_nat_t_port'] ?? '4500'
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        };

        class PPPManager {
            constructor() {
                this.users = [];
                this.activeSessions = [];
                this.previousTrafficData = new Map(); // Store previous traffic data for rate calculation
                this.selectedUsers = new Set();
                this.openNATRows = new Set(); // Track which NAT rows are open
                this.natCache = new Map(); // Cache NAT content per userId
                this.sortField = 'name';
                this.sortDirection = 'asc';
                this.portBuilders = {
                    add: {
                        listId: 'userPortsList',
                        hiddenId: 'userPorts',
                        multiOptionsId: 'multiPortOptions',
                        multipleNatCheckboxId: 'createMultipleNat'
                    },
                    edit: {
                        listId: 'editUserPortsList',
                        hiddenId: 'editUserPorts',
                        multiOptionsId: 'editMultiPortOptions',
                        multipleNatCheckboxId: 'editCreateMultipleNat'
                    }
                };
                this.init();
            }
            
            init() {
                this.bindEvents();
                this.bindModalControls();
                this.loadUsers();
                this.loadActiveSessions();
                this.updateStats();
                this.loadAvailableServices();
                this.updateSortHeaders();

                // Start periodic updates for real-time traffic
                setInterval(() => {
                    this.loadActiveSessions();
                }, 2000); // Update traffic every 2 seconds for real-time

                // Update stats every 10 seconds
                setInterval(() => {
                    this.updateStats();
                }, 10000); // Update stats every 10 seconds
            }
            
            bindEvents() {
                // Search and filter events
                document.getElementById('searchInput').addEventListener('input', () => this.filterUsers());
                document.getElementById('serviceFilter').addEventListener('change', () => this.filterUsers());
                document.getElementById('statusFilter').addEventListener('change', () => this.filterUsers());

                const topNavbarBurger = document.getElementById('topNavbarBurger');
                const topNavbarMenu = document.getElementById('topNavbarMenu');
                if (topNavbarBurger && topNavbarMenu) {
                    topNavbarBurger.addEventListener('click', () => {
                        const isActive = topNavbarMenu.classList.toggle('is-active');
                        topNavbarBurger.classList.toggle('is-active', isActive);
                        topNavbarBurger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                    });
                }
                
                // Select all checkbox
                document.getElementById('selectAll').addEventListener('change', (e) => {
                    const checkboxes = document.querySelectorAll('.user-checkbox');
                    checkboxes.forEach(cb => {
                        cb.checked = e.target.checked;
                        if (e.target.checked) {
                            this.selectedUsers.add(cb.dataset.userId);
                        } else {
                            this.selectedUsers.delete(cb.dataset.userId);
                        }
                    });
                    this.updateBulkActions();
                });
                
                // Form submissions
                const addUserForm = document.getElementById('addUserForm');
                const editUserForm = document.getElementById('editUserForm');

                if (addUserForm) {
                    addUserForm.addEventListener('submit', (e) => this.handleAddUser(e));
                }

                if (editUserForm) {
                    editUserForm.addEventListener('submit', (e) => this.handleEditUser(e));
                }
                
                // Service change event for auto IP assignment
                const userService = document.getElementById('userService');
                if (userService) {
                    userService.addEventListener('change', (e) => this.handleServiceChange(e));
                }

                document.getElementById('addUserPortButton')?.addEventListener('click', () => this.addPortInput('add'));
                document.getElementById('addEditUserPortButton')?.addEventListener('click', () => this.addPortInput('edit'));
                document.getElementById('userPortsList')?.addEventListener('input', (event) => {
                    if (event.target.classList.contains('ppp-port-input')) {
                        this.syncPortInputs('add');
                    }
                });
                document.getElementById('editUserPortsList')?.addEventListener('input', (event) => {
                    if (event.target.classList.contains('ppp-port-input')) {
                        this.syncPortInputs('edit');
                    }
                });
                document.getElementById('userPortsList')?.addEventListener('click', (event) => {
                    const removeButton = event.target.closest('[data-remove-port]');
                    if (!removeButton) {
                        return;
                    }

                    const row = removeButton.closest('.ppp-port-row');
                    row?.remove();
                    this.syncPortInputs('add');
                });
                document.getElementById('editUserPortsList')?.addEventListener('click', (event) => {
                    const removeButton = event.target.closest('[data-remove-port]');
                    if (!removeButton) {
                        return;
                    }

                    const row = removeButton.closest('.ppp-port-row');
                    row?.remove();
                    this.syncPortInputs('edit');
                });
                document.querySelectorAll('[data-port-preset]').forEach((button) => {
                    button.addEventListener('click', () => this.addPortPreset(button.dataset.portPreset, button.dataset.portTarget || 'add'));
                });
                document.getElementById('editSyncNatRule')?.addEventListener('change', (event) => this.toggleEditNatSection(event.target.checked));
            }

            bindModalControls() {
                document.addEventListener('click', (event) => {
                    const openTarget = event.target.closest('[data-open-modal]');
                    if (openTarget) {
                        this.openModal(openTarget.getAttribute('data-open-modal'));
                        return;
                    }

                    const closeTarget = event.target.closest('[data-close-modal]');
                    if (closeTarget) {
                        this.closeModal(closeTarget.getAttribute('data-close-modal'));
                        return;
                    }

                    if (event.target.classList.contains('delete')) {
                        event.target.parentElement?.remove();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        ['addUserModal', 'editUserModal', 'userDetailsModal'].forEach((modalId) => this.closeModal(modalId));
                    }
                });
            }

            openModal(modalId) {
                document.getElementById(modalId)?.classList.add('is-active');
                document.documentElement.classList.add('is-clipped');

                if (modalId === 'addUserModal') {
                    this.syncPortInputs('add');
                }
            }

            closeModal(modalId) {
                document.getElementById(modalId)?.classList.remove('is-active');

                if (!document.querySelector('.modal.is-active')) {
                    document.documentElement.classList.remove('is-clipped');
                }
            }

            getPortBuilderConfig(target = 'add') {
                return this.portBuilders[target] || this.portBuilders.add;
            }

            getPortValues(target = 'add') {
                const config = this.getPortBuilderConfig(target);
                const list = document.getElementById(config.listId);

                if (!list) {
                    return [];
                }

                return Array.from(list.querySelectorAll('.ppp-port-input'))
                    .map((input) => input.value.trim())
                    .filter((value) => value !== '');
            }

            setPortInputs(target = 'add', ports = []) {
                const config = this.getPortBuilderConfig(target);
                const list = document.getElementById(config.listId);
                const normalizedPorts = Array.from(new Set((ports || [])
                    .map((port) => String(port || '').trim())
                    .filter((port) => port !== '')));

                if (!list) {
                    return;
                }

                list.innerHTML = '';
                normalizedPorts.forEach((port) => this.addPortInput(target, port, false));
                this.syncPortInputs(target);
            }

            addPortInput(target = 'add', value = '', focus = true) {
                const config = this.getPortBuilderConfig(target);
                const list = document.getElementById(config.listId);
                if (!list) {
                    return;
                }

                const row = document.createElement('div');
                row.className = 'field has-addons ppp-port-row';
                row.innerHTML = `
                    <div class="control is-expanded">
                        <input type="number" class="input admin-input ppp-port-input" min="1" max="65535" placeholder="8291" value="${this.escapeHtml(value)}">
                    </div>
                    <div class="control">
                        <button type="button" class="button is-danger is-light admin-addon-button" data-remove-port title="Remove Port">
                            <span class="icon"><i class="bi bi-dash-circle" aria-hidden="true"></i></span>
                        </button>
                    </div>
                `;

                list.appendChild(row);
                this.syncPortInputs(target);

                if (focus) {
                    row.querySelector('.ppp-port-input')?.focus();
                }
            }

            addPortPreset(port, target = 'add') {
                const normalizedPort = String(port || '').trim();

                if (!normalizedPort) {
                    return;
                }

                const existingPorts = this.getPortValues(target);

                if (existingPorts.includes(normalizedPort)) {
                    this.showAlert(`Port ${normalizedPort} is already in the list.`, 'warning');
                    return;
                }

                this.addPortInput(target, normalizedPort);
            }

            syncPortInputs(target = 'add') {
                const config = this.getPortBuilderConfig(target);
                const list = document.getElementById(config.listId);
                const hiddenInput = document.getElementById(config.hiddenId);
                const multiPortDiv = document.getElementById(config.multiOptionsId);
                const multipleNatCheckbox = document.getElementById(config.multipleNatCheckboxId);

                if (!list || !hiddenInput) {
                    return;
                }

                const ports = Array.from(list.querySelectorAll('.ppp-port-input'))
                    .map((input) => input.value.trim())
                    .filter((value) => value !== '');

                hiddenInput.value = ports.join(',');

                if (multiPortDiv) {
                    multiPortDiv.classList.toggle('is-active', ports.length > 1);
                }

                if (multipleNatCheckbox && ports.length <= 1) {
                    multipleNatCheckbox.checked = false;
                }
            }

            resetPortInputs(target = 'add') {
                const config = this.getPortBuilderConfig(target);
                const list = document.getElementById(config.listId);
                const hiddenInput = document.getElementById(config.hiddenId);
                const multipleNatCheckbox = document.getElementById(config.multipleNatCheckboxId);

                if (list) {
                    list.innerHTML = '';
                }

                if (hiddenInput) {
                    hiddenInput.value = '';
                }

                if (multipleNatCheckbox) {
                    multipleNatCheckbox.checked = false;
                }

                this.syncPortInputs(target);
            }

            toggleEditNatSection(enabled) {
                document.getElementById('editNatMappingSection')?.classList.toggle('is-hidden', !enabled);
                const multiSection = document.getElementById('editMultiPortOptions');

                if (multiSection && !enabled) {
                    multiSection.classList.remove('is-active');
                }
            }

            validatePortList(ports) {
                const invalidPorts = ports.filter((port) => !/^\d{1,5}$/.test(port) || port < 1 || port > 65535);
                if (invalidPorts.length > 0) {
                    throw new Error('Invalid port numbers: ' + invalidPorts.join(', '));
                }

                const duplicatePorts = ports.filter((port, index) => ports.indexOf(port) !== index);
                if (duplicatePorts.length > 0) {
                    throw new Error('Duplicate ports are not allowed: ' + Array.from(new Set(duplicatePorts)).join(', '));
                }
            }

            showLoading() {
                document.getElementById('loadingOverlay').classList.add('is-active');
            }
            
            hideLoading() {
                document.getElementById('loadingOverlay').classList.remove('is-active');
            }
            
            showAlert(message, type = 'success') {
                if (window.AppSwal) {
                    window.AppSwal.toast(message, type);
                    return;
                }

                const alertsContainer = document.getElementById('alerts-container');
                const alertId = 'alert-' + Date.now();
                
                const alertHtml = `
                    <div class="notification ${this.getAlertClass(type)} admin-notification fade-in" id="${alertId}" role="alert">
                        <button type="button" class="delete" aria-label="Close"></button>
                        <i class="bi bi-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'x-circle'}"></i>
                        ${message}
                    </div>
                `;
                
                alertsContainer.insertAdjacentHTML('beforeend', alertHtml);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    const alert = document.getElementById(alertId);
                    if (alert) {
                        alert.remove();
                    }
                }, 5000);
            }

            getAlertClass(type) {
                const classes = {
                    success: 'is-success',
                    warning: 'is-warning',
                    danger: 'is-danger',
                    error: 'is-danger'
                };

                return classes[type] || 'is-info is-light';
            }

            async confirmAction(options) {
                if (window.AppSwal) {
                    return window.AppSwal.confirm(options);
                }

                return confirm(options.text || 'Are you sure?');
            }

            getPublishedPortsConfig() {
                const configuredPorts = window.PPP_APP_CONFIG?.externalPorts || {};

                return {
                    rest_http_port: configuredPorts.rest_http_port || '7004',
                    rest_https_port: configuredPorts.rest_https_port || '7005',
                    winbox_port: configuredPorts.winbox_port || '7000',
                    api_port: configuredPorts.api_port || '7001',
                    api_ssl_port: configuredPorts.api_ssl_port || '7002',
                    ssh_port: configuredPorts.ssh_port || '7003',
                    l2tp_port: configuredPorts.l2tp_port || '1701',
                    l2tp_host: configuredPorts.l2tp_host || '',
                    pptp_port: configuredPorts.pptp_port || '1723',
                    pptp_host: configuredPorts.pptp_host || '',
                    sstp_port: configuredPorts.sstp_port || '443',
                    sstp_host: configuredPorts.sstp_host || '',
                    ipsec_port: configuredPorts.ipsec_port || '500',
                    ipsec_nat_t_port: configuredPorts.ipsec_nat_t_port || '4500'
                };
            }

            buildEndpoint(host, port, protocol = 'tcp', connectTarget = null) {
                const resolvedConnectTarget = connectTarget || (host ? (port ? `${host}:${port}` : host) : '[server_ip]');

                if (!host) {
                    return {
                        connectTarget: resolvedConnectTarget,
                        display: `[server_ip]:${port}/${protocol}`
                    };
                }

                return {
                    connectTarget: resolvedConnectTarget,
                    display: port ? `${host}:${port}/${protocol}` : host
                };
            }

            getServiceEndpoint(service, serverHost) {
                const ports = this.getPublishedPortsConfig();
                const normalizedService = (service || 'l2tp').toLowerCase();

                switch (normalizedService) {
                    case 'pptp':
                        {
                            const host = ports.pptp_host || serverHost;
                        return {
                            name: 'PPTP',
                            ...this.buildEndpoint(host, ports.pptp_port, 'tcp', host || '[server_ip]'),
                            notes: [
                                'RouterOS PPTP client uses connect-to=<host> and does not accept host:port in the generated command.',
                                ports.pptp_port !== '1723' ? `Published TCP port is ${ports.pptp_port}. For MikroTik-to-MikroTik clients, use external port 1723 for compatibility.` : 'Published TCP port matches the default PPTP port 1723.'
                            ]
                        };
                        }
                    case 'sstp':
                        {
                            const host = ports.sstp_host || serverHost;
                        return {
                            name: 'SSTP',
                            ...this.buildEndpoint(host, ports.sstp_port, 'tcp'),
                            notes: []
                        };
                        }
                    case 'any':
                    case 'l2tp':
                    default:
                        {
                            const host = ports.l2tp_host || serverHost;
                        return {
                            name: 'L2TP',
                            ...this.buildEndpoint(host, ports.l2tp_port, 'udp', host || '[server_ip]'),
                            notes: [
                                'RouterOS L2TP client uses connect-to=<host> and does not accept host:port in the generated command.',
                                ports.l2tp_port !== '1701' ? `Published UDP port is ${ports.l2tp_port}. For MikroTik-to-MikroTik clients, use external port 1701 plus IPsec ports 500 and 4500 on their defaults.` : 'Published UDP port matches the default L2TP port 1701.',
                                `IPsec IKE: ${this.buildEndpoint(host, ports.ipsec_port, 'udp').display}`,
                                `IPsec NAT-T: ${this.buildEndpoint(host, ports.ipsec_nat_t_port, 'udp').display}`
                            ]
                        };
                        }
                }
            }
            
            async fetchAPI(action, data = null, method = 'GET') {
                const url = '../api/mikrotik.php?action=' + action;
                const options = {
                    method: method,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                };
                
                if (data && method !== 'GET') {
                    options.headers['Content-Type'] = 'application/json';
                    options.body = JSON.stringify({ action, ...data });
                }
                
                try {
                    const response = await fetch(url, options);
                    const responseText = await response.text();

                    // Try to parse JSON response
                    let jsonData;
                    try {
                        jsonData = JSON.parse(responseText);
                    } catch (jsonError) {
                        throw new Error(`Invalid JSON response: ${responseText.substring(0, 200)}`);
                    }

                    // If response not OK, throw error with message
                    if (!response.ok) {
                        throw new Error(jsonData.message || `HTTP error! status: ${response.status}`);
                    }

                    return jsonData;
                } catch (error) {
                    throw error;
                }
            }
            
            async loadUsers() {
                try {
                    this.showLoading();
                    const result = await this.fetchAPI('get_ppp_users');

                    if (result.success) {
                        this.users = result.data || [];
                        this.renderUsers();
                    } else {
                        throw new Error(result.message || 'Failed to load users');
                    }
                } catch (error) {
                    this.showAlert('Error loading users: ' + error.message, 'danger');
                } finally {
                    this.hideLoading();
                }
            }

            async loadAvailableServices() {
                try {
                    const result = await this.fetchAPI('get_available_services');

                    if (result.success && result.data) {
                        this.updateServiceDropdown(result.data);
                    }
                } catch (error) {
                    // Silent error - keep default services
                    console.error('Error loading available services:', error);
                }
            }

            updateServiceDropdown(availableServices) {
                // Update Add User modal dropdown
                const serviceDropdown = document.getElementById('userService');
                if (serviceDropdown) {
                    // Clear current options except the first one (placeholder)
                    serviceDropdown.innerHTML = '<option value="">Select Service</option>';

                    // Add only available services
                    if (availableServices.includes('l2tp')) {
                        serviceDropdown.innerHTML += '<option value="l2tp">L2TP</option>';
                    }
                    if (availableServices.includes('pptp')) {
                        serviceDropdown.innerHTML += '<option value="pptp">PPTP</option>';
                    }
                    if (availableServices.includes('sstp')) {
                        serviceDropdown.innerHTML += '<option value="sstp">SSTP</option>';
                    }

                    // Always add "Any" option if at least one service is available
                    if (availableServices.length > 0) {
                        serviceDropdown.innerHTML += '<option value="any">Any</option>';
                    }
                }

                // Update Edit User modal dropdown
                const editServiceDropdown = document.getElementById('editUserService');
                if (editServiceDropdown) {
                    const currentValue = editServiceDropdown.value; // Save current selection

                    editServiceDropdown.innerHTML = '';

                    // Add only available services
                    if (availableServices.includes('l2tp')) {
                        editServiceDropdown.innerHTML += '<option value="l2tp">L2TP</option>';
                    }
                    if (availableServices.includes('pptp')) {
                        editServiceDropdown.innerHTML += '<option value="pptp">PPTP</option>';
                    }
                    if (availableServices.includes('sstp')) {
                        editServiceDropdown.innerHTML += '<option value="sstp">SSTP</option>';
                    }

                    // Always add "Any" option
                    if (availableServices.length > 0) {
                        editServiceDropdown.innerHTML += '<option value="any">Any</option>';
                    }

                    // Restore selection if it's still available
                    if (currentValue && availableServices.includes(currentValue)) {
                        editServiceDropdown.value = currentValue;
                    }
                }
            }

            async loadActiveSessions() {
                try {
                    const result = await this.fetchAPI('get_ppp_active');

                    if (result.success) {
                        // Update traffic data BEFORE updating activeSessions
                        const newSessions = result.data || [];

                        // Calculate traffic rates for all users
                        newSessions.forEach(session => {
                            const username = session.name;
                            const rxBytes = parseInt(session['bytes-in'] || session['rx-byte'] || session.rx || 0);
                            const txBytes = parseInt(session['bytes-out'] || session['tx-byte'] || session.tx || 0);
                            const currentTime = Date.now();

                            const previousData = this.previousTrafficData.get(username);

                            // Calculate and store rate
                            if (previousData && previousData.time) {
                                const timeDiff = (currentTime - previousData.time) / 1000;

                                if (timeDiff > 1.5) { // Only calculate if at least 1.5 seconds passed
                                    const rxDiff = rxBytes - previousData.rx;
                                    const txDiff = txBytes - previousData.tx;

                                    if (rxDiff >= 0 && txDiff >= 0) {
                                        const rxRate = rxDiff / timeDiff;
                                        const txRate = txDiff / timeDiff;

                                        // Store calculated rate
                                        this.previousTrafficData.set(username, {
                                            rx: rxBytes,
                                            tx: txBytes,
                                            rxRate: rxRate,
                                            txRate: txRate,
                                            time: currentTime
                                        });
                                    }
                                }
                            } else {
                                // First time - just store the data
                                this.previousTrafficData.set(username, {
                                    rx: rxBytes,
                                    tx: txBytes,
                                    rxRate: 0,
                                    txRate: 0,
                                    time: currentTime
                                });
                            }
                        });

                        this.activeSessions = newSessions;
                        this.renderUsers(); // Re-render to update online/offline status and traffic
                    }
                } catch (error) {
                    // Silent error for active sessions - no action needed
                }
            }

            isUserOnline(username) {
                if (!this.activeSessions || this.activeSessions.length === 0) {
                    return false;
                }

                return this.activeSessions.some(session => session.name === username);
            }

            getUserTraffic(username) {
                if (!this.activeSessions || this.activeSessions.length === 0) {
                    return { rx: '-', tx: '-', rxRate: '-', txRate: '-' };
                }

                const session = this.activeSessions.find(s => s.name === username);
                if (!session) {
                    return { rx: '-', tx: '-', rxRate: '-', txRate: '-' };
                }

                // Get traffic data from cache (calculated in loadActiveSessions)
                const trafficData = this.previousTrafficData.get(username);

                if (trafficData) {
                    return {
                        rx: this.formatBytes(trafficData.rx),
                        tx: this.formatBytes(trafficData.tx),
                        rxRate: this.formatBitrate(trafficData.rxRate || 0),
                        txRate: this.formatBitrate(trafficData.txRate || 0)
                    };
                }

                // No traffic data yet
                return { rx: '-', tx: '-', rxRate: '-', txRate: '-' };
            }

            formatBytes(bytes) {
                if (bytes === 0 || !bytes) return '0 B';

                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));

                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            formatBitrate(bytesPerSecond) {
                if (bytesPerSecond === 0 || !bytesPerSecond) return '0.0 Kbps';

                // Convert bytes to bits
                const bitsPerSecond = bytesPerSecond * 8;

                const k = 1000; // Use 1000 for network speeds (not 1024)
                const sizes = ['bps', 'Kbps', 'Mbps', 'Gbps'];
                const i = Math.floor(Math.log(bitsPerSecond) / Math.log(k));

                const value = parseFloat((bitsPerSecond / Math.pow(k, i)).toFixed(2));

                // Format with appropriate decimals for consistency
                let formatted;
                if (i === 0) { // bps - convert to Kbps for consistency
                    formatted = (value / 1000).toFixed(1);
                    return formatted + ' Kbps';
                } else if (i === 1) { // Kbps
                    formatted = value.toFixed(1);
                } else { // Mbps and above
                    formatted = value.toFixed(2);
                }

                return formatted + ' ' + sizes[i];
            }

            getAccountStatusMeta(user) {
                const isDisabled = user.disabled === 'true' || user.disabled === true;

                return isDisabled
                    ? { label: 'Disabled', className: 'is-danger is-light' }
                    : { label: 'Active', className: 'is-success is-light' };
            }

            getConnectionModeMeta(isOnline) {
                return isOnline
                    ? { label: 'Online', className: 'is-info is-light' }
                    : { label: 'Offline', className: 'is-dark is-light' };
            }
            
            
            async updateStats() {
                try {
                    const result = await this.fetchAPI('ppp_stats');
                    
                    if (result.success && result.data) {
                        document.getElementById('total-users').textContent = result.data.total || 0;
                        document.getElementById('online-users').textContent = result.data.online || 0;
                        document.getElementById('offline-users').textContent = result.data.offline || 0;
                    }
                } catch (error) {
                    // Silent error for stats update
                }
            }
            
            async handleServiceChange(e) {
                const service = e.target.value;
                const remoteAddressField = document.getElementById('userRemoteAddress');
                
                if (service) {
                    try {
                        const result = await this.fetchAPI('get_available_ip', { service: service }, 'POST');
                        
                        if (result.success && result.data.ip) {
                            remoteAddressField.value = result.data.ip;
                        } else {
                            remoteAddressField.value = '';
                        }
                    } catch (error) {
                        remoteAddressField.value = '';
                    }
                } else {
                    remoteAddressField.value = '';
                }
            }
            
            renderUsers() {
                const tbody = document.getElementById('usersTableBody');

                if (!this.users || this.users.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="9" class="has-text-centered">
                                <div class="app-empty-state">
                                    <span class="icon"><i class="bi bi-people has-text-grey-light"></i></span>
                                    <p>No PPP users found</p>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                const filteredUsers = this.getFilteredUsers();

                tbody.innerHTML = filteredUsers.map(user => {
                    const isOnline = this.isUserOnline(user.name);
                    const trafficData = this.getUserTraffic(user.name);
                    const isChecked = this.selectedUsers.has(user['.id']);
                    const accountStatus = this.getAccountStatusMeta(user);
                    const connectionMode = this.getConnectionModeMeta(isOnline);
                    return `
                    <tr>
                        <td>
                            <input type="checkbox" class="user-checkbox"
                                   data-user-id="${user['.id']}"
                                   ${isChecked ? 'checked' : ''}
                                   onchange="pppManager.handleUserSelection(this)">
                        </td>
                        <td>
                            <a href="#" class="username-link"
                               onclick="pppManager.toggleNATRules(event, '${this.escapeHtml(user.name)}', '${user['.id']}')">
                                ${this.escapeHtml(user.name || '-')}
                            </a>
                        </td>
                        <td class="has-text-centered">
                            <span class="service-badge service-${user.service || 'any'}">
                                ${(user.service || 'any').toUpperCase()}
                            </span>
                        </td>
                        <td class="has-text-centered">${this.escapeHtml(user['remote-address'] || '-')}</td>
                        <td class="is-hidden-touch has-text-centered">${this.escapeHtml(user['last-caller-id'] || 'Never')}</td>
                        <td class="has-text-centered">
                            <span class="tag ppp-state-badge ${accountStatus.className}">
                                ${accountStatus.label}
                            </span>
                        </td>
                        <td class="has-text-centered is-hidden-touch">
                            <span class="tag ppp-state-badge ${connectionMode.className}">
                                ${connectionMode.label}
                            </span>
                        </td>
                        <td class="is-hidden-touch has-text-centered">
                            ${isOnline ? `
                                <div class="traffic-info-compact">
                                    <span class="traffic-item-compact traffic-upload">
                                        <i class="bi bi-arrow-up-circle-fill"></i>
                                        <span class="traffic-value">${trafficData.txRate}</span>
                                    </span>
                                    <span class="traffic-separator">-</span>
                                    <span class="traffic-item-compact traffic-download">
                                        <i class="bi bi-arrow-down-circle-fill"></i>
                                        <span class="traffic-value">${trafficData.rxRate}</span>
                                    </span>
                                </div>
                            ` : '<small class="has-text-grey-light">-</small>'}
                        </td>
                        <td>
                            <div class="ppp-table-actions">
                            <button class="button is-small is-info is-light btn-action"
                                    type="button"
                                    onclick="pppManager.showUserDetails('${user['.id']}')"
                                    title="View Details">
                                <i class="bi bi-info-circle"></i>
                            </button>
                            <button class="button is-small is-warning is-light btn-action"
                                    type="button"
                                    onclick="pppManager.editUser('${user['.id']}')"
                                    title="Edit User">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="button is-small ${user.disabled === 'true' ? 'is-success is-light' : 'is-warning is-light'} btn-action"
                                    type="button"
                                    onclick="pppManager.toggleUserStatus('${user['.id']}')"
                                    title="${user.disabled === 'true' ? 'Enable User' : 'Disable User'}">
                                <i class="bi bi-${user.disabled === 'true' ? 'unlock' : 'lock'}"></i>
                            </button>
                            <button class="button is-small is-danger is-light btn-action"
                                    type="button"
                                    onclick="pppManager.deleteUser('${user['.id']}')"
                                    title="Delete User">
                                <i class="bi bi-trash"></i>
                            </button>
                            </div>
                        </td>
                    </tr>
                    <tr id="nat-row-${user['.id']}" class="nat-details-row">
                        <td colspan="9">
                            <div class="nat-details-container">
                                <span class="icon"><i class="bi bi-arrow-repeat spin has-text-info"></i></span>
                                <span class="nat-loading-text">Loading NAT rules...</span>
                            </div>
                        </td>
                    </tr>
                `}).join('');

                // Restore open NAT rows after render
                this.restoreNATState();

                // Update "Select All" checkbox state
                this.updateSelectAllCheckbox();
            }

            restoreNATState() {
                // Restore NAT rows that were open before re-render
                this.openNATRows.forEach(userId => {
                    const natRow = document.getElementById(`nat-row-${userId}`);

                    if (natRow) {
                        natRow.classList.add('is-visible');

                        // Restore cached NAT content
                        const escapedUserId = CSS.escape(userId);
                        const container = document.querySelector(`#nat-row-${escapedUserId} .nat-details-container`);

                        if (container && this.natCache.has(userId)) {
                            container.innerHTML = this.natCache.get(userId);
                        }
                    }
                });
            }

            getFilteredUsers() {
                let filtered = [...this.users];
                
                // Search filter
                const searchTerm = document.getElementById('searchInput').value.toLowerCase();
                if (searchTerm) {
                    filtered = filtered.filter(user => 
                        (user.name || '').toLowerCase().includes(searchTerm) ||
                        (user['remote-address'] || '').toLowerCase().includes(searchTerm)
                    );
                }
                
                // Service filter
                const serviceFilter = document.getElementById('serviceFilter').value;
                if (serviceFilter) {
                    filtered = filtered.filter(user => user.service === serviceFilter);
                }
                
                // Status filter
                const statusFilter = document.getElementById('statusFilter').value;
                if (statusFilter) {
                    const isDisabled = statusFilter === 'disabled';
                    filtered = filtered.filter(user => (user.disabled === 'true') === isDisabled);
                }
                
                // Sort
                filtered.sort((a, b) => {
                    let aVal, bVal;

                    // Handle special sort fields
                    if (this.sortField === 'mode') {
                        // Sort by online/offline status
                        aVal = this.isUserOnline(a.name) ? 'online' : 'offline';
                        bVal = this.isUserOnline(b.name) ? 'online' : 'offline';
                    } else if (this.sortField === 'traffic') {
                        // Sort by traffic RATE (bps) - combined rx + tx rate
                        const aTrafficData = this.previousTrafficData.get(a.name);
                        const bTrafficData = this.previousTrafficData.get(b.name);

                        // Get total rate (rx + tx) in bytes per second
                        const aTotalRate = aTrafficData ? ((aTrafficData.rxRate || 0) + (aTrafficData.txRate || 0)) : 0;
                        const bTotalRate = bTrafficData ? ((bTrafficData.rxRate || 0) + (bTrafficData.txRate || 0)) : 0;

                        // Sort: ascending = lowest first, descending = highest first
                        return this.sortDirection === 'asc' ? aTotalRate - bTotalRate : bTotalRate - aTotalRate;
                    } else if (this.sortField === 'disabled') {
                        // Sort by status (enabled/disabled)
                        aVal = a.disabled === 'true' ? 'disabled' : 'enabled';
                        bVal = b.disabled === 'true' ? 'disabled' : 'enabled';
                    } else if (this.sortField === 'remote-address') {
                        // Sort IP addresses numerically
                        const aIP = a['remote-address'] || '';
                        const bIP = b['remote-address'] || '';

                        // Convert IP to numeric value for proper sorting
                        const ipToNum = (ip) => {
                            const parts = ip.split('.');
                            if (parts.length !== 4) return 0;
                            return parts.reduce((acc, octet, index) => {
                                return acc + (parseInt(octet) || 0) * Math.pow(256, 3 - index);
                            }, 0);
                        };

                        const aNum = ipToNum(aIP);
                        const bNum = ipToNum(bIP);

                        return this.sortDirection === 'asc' ? aNum - bNum : bNum - aNum;
                    } else if (this.sortField === 'last-caller-id') {
                        // Sort Caller ID (also IP addresses) numerically
                        const aCallerID = a['last-caller-id'] || '';
                        const bCallerID = b['last-caller-id'] || '';

                        // Convert IP to numeric value for proper sorting
                        const ipToNum = (ip) => {
                            const parts = ip.split('.');
                            if (parts.length !== 4) return 0;
                            return parts.reduce((acc, octet, index) => {
                                return acc + (parseInt(octet) || 0) * Math.pow(256, 3 - index);
                            }, 0);
                        };

                        const aNum = ipToNum(aCallerID);
                        const bNum = ipToNum(bCallerID);

                        return this.sortDirection === 'asc' ? aNum - bNum : bNum - aNum;
                    } else {
                        // Default string sort
                        aVal = a[this.sortField] || '';
                        bVal = b[this.sortField] || '';
                    }

                    // String comparison for non-numeric values
                    if (typeof aVal === 'string' && typeof bVal === 'string') {
                        if (this.sortDirection === 'asc') {
                            return aVal.localeCompare(bVal);
                        } else {
                            return bVal.localeCompare(aVal);
                        }
                    }

                    return 0;
                });

                return filtered;
            }
            
            filterUsers() {
                this.renderUsers();
            }
            
            handleUserSelection(checkbox) {
                const userId = checkbox.dataset.userId;
                
                if (checkbox.checked) {
                    this.selectedUsers.add(userId);
                } else {
                    this.selectedUsers.delete(userId);
                }
                
                this.updateBulkActions();
            }
            
            updateBulkActions() {
                const bulkActions = document.getElementById('bulkActions');
                const selectedCount = document.getElementById('selectedCount');

                selectedCount.textContent = this.selectedUsers.size;

                if (this.selectedUsers.size > 0) {
                    bulkActions.classList.add('show');
                } else {
                    bulkActions.classList.remove('show');
                }

                // Update "Select All" checkbox
                this.updateSelectAllCheckbox();
            }

            updateSelectAllCheckbox() {
                const selectAllCheckbox = document.getElementById('selectAll');
                if (!selectAllCheckbox) return;

                const visibleCheckboxes = document.querySelectorAll('.user-checkbox');
                const visibleCount = visibleCheckboxes.length;
                const selectedVisibleCount = Array.from(visibleCheckboxes).filter(cb => cb.checked).length;

                if (visibleCount > 0 && selectedVisibleCount === visibleCount) {
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else if (selectedVisibleCount > 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                } else {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                }
            }
            
            async handleAddUser(e) {
                e.preventDefault();

                const requestedPorts = this.getPortValues('add');
                this.syncPortInputs('add');
                const formData = new FormData(e.target);
                const userData = Object.fromEntries(formData);
                userData.ports = requestedPorts.join(',');
                userData.requested_ports_json = JSON.stringify(requestedPorts);
                
                // Handle checkbox values explicitly since unchecked checkboxes don't appear in FormData
                const createNatRuleElement = document.getElementById('createNatRule');
                const createMultipleNatElement = document.getElementById('createMultipleNat');
                
                userData.createNatRule = createNatRuleElement ? createNatRuleElement.checked : false;
                userData.createMultipleNat = createMultipleNatElement ? createMultipleNatElement.checked : false;
                
                
                // Validate ports if provided (if empty, default ports 8291,8728 will be used)
                if (requestedPorts.length > 0) {

                    try {
                        this.validatePortList(requestedPorts);
                    } catch (validationError) {
                        this.showAlert(validationError.message, 'danger');
                        return;
                    }
                }
                
                try {
                    this.showLoading();
                    
                    const result = await this.fetchAPI('add_ppp_user', userData, 'POST');
                    
                    if (result.success) {
                        this.showAlert(result.message || 'User created successfully!');
                        this.closeModal('addUserModal');
                        e.target.reset();
                        this.resetPortInputs('add');
                        await this.loadUsers();
                        await this.updateStats();
                    } else {
                        throw new Error(result.message || 'Failed to create user');
                    }
                } catch (error) {
                    this.showAlert('Error creating user: ' + error.message, 'danger');
                } finally {
                    this.hideLoading();
                }
            }
            
            async handleEditUser(e) {
                e.preventDefault();

                const requestedPorts = this.getPortValues('edit');
                this.syncPortInputs('edit');
                const formData = new FormData(e.target);
                const userData = Object.fromEntries(formData);
                userData.ports = requestedPorts.join(',');
                userData.requested_ports_json = JSON.stringify(requestedPorts);
                const syncNatRuleElement = document.getElementById('editSyncNatRule');
                const createMultipleNatElement = document.getElementById('editCreateMultipleNat');

                userData.sync_nat_ports = syncNatRuleElement ? syncNatRuleElement.checked : false;
                userData.createMultipleNat = createMultipleNatElement ? createMultipleNatElement.checked : false;

                if (userData.sync_nat_ports) {
                    try {
                        this.validatePortList(requestedPorts);
                    } catch (validationError) {
                        this.showAlert(validationError.message, 'danger');
                        return;
                    }
                }
                
                try {
                    this.showLoading();
                    
                    const result = await this.fetchAPI('edit_ppp_user', userData, 'POST');
                    
                    if (result.success) {
                        this.showAlert(result.message || 'User updated successfully!');
                        this.closeModal('editUserModal');
                        e.target.reset();
                        this.resetPortInputs('edit');
                        this.toggleEditNatSection(false);
                        await this.loadUsers();
                    } else {
                        throw new Error(result.message || 'Failed to update user');
                    }
                } catch (error) {
                    this.showAlert('Error updating user: ' + error.message, 'danger');
                } finally {
                    this.hideLoading();
                }
            }
            
            async editUser(userId) {
                const user = this.users.find(u => u['.id'] === userId);
                if (!user) return;

                const syncNatCheckbox = document.getElementById('editSyncNatRule');
                const multipleNatCheckbox = document.getElementById('editCreateMultipleNat');
                const natSnapshotInput = document.getElementById('editExistingNatSnapshot');

                document.getElementById('editUserId').value = userId;
                document.getElementById('editUserName').value = user.name || '';
                document.getElementById('editUserService').value = user.service || '';
                document.getElementById('editUserRemoteAddress').value = user['remote-address'] || '';
                this.resetPortInputs('edit');
                if (natSnapshotInput) {
                    natSnapshotInput.value = '[]';
                }

                if (syncNatCheckbox) {
                    syncNatCheckbox.checked = false;
                }

                if (multipleNatCheckbox) {
                    multipleNatCheckbox.checked = false;
                }

                this.toggleEditNatSection(false);

                try {
                    this.showLoading();
                    const result = await this.fetchAPI('get_user_details', { user_id: userId }, 'POST');

                    if (!result.success) {
                        throw new Error(result.message || 'Failed to load user details');
                    }

                    if (result.data) {
                        const allNatRules = Array.isArray(result.data.nat_rules) ? result.data.nat_rules : [];
                        const usernamePattern = new RegExp(`^${String(user.name || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?: \\(Port \\d+\\))?$`);
                        const natRuleMap = new Map();
                        const managedNatRules = allNatRules
                            .filter((rule) => usernamePattern.test(String(rule.comment || '')));
                        const legacyNatRules = allNatRules.filter((rule) => {
                            const internalPort = String(rule['to-ports'] || '').trim();
                            const externalPort = String(rule['dst-port'] || '').trim();
                            const ruleRemoteAddress = String(rule['to-addresses'] || '').trim();
                            const protocol = String(rule.protocol || 'tcp').toLowerCase();
                            const chain = String(rule.chain || 'dstnat').toLowerCase();
                            const action = String(rule.action || 'dst-nat').toLowerCase();

                            return /^\d{1,5}$/.test(internalPort)
                                && /^\d{1,5}$/.test(externalPort)
                                && ruleRemoteAddress === String(user['remote-address'] || '').trim()
                                && protocol === 'tcp'
                                && chain === 'dstnat'
                                && action === 'dst-nat';
                        });

                        [...managedNatRules, ...legacyNatRules].forEach((rule) => {
                            const key = String(rule['.id'] || `${rule['dst-port']}-${rule['to-ports']}-${rule.comment || ''}`);
                            natRuleMap.set(key, rule);
                        });

                        let natRules = Array.from(natRuleMap.values());

                        if (natRules.length === 0) {
                            natRules = allNatRules.filter((rule) => /^\d{1,5}$/.test(String(rule['to-ports'] || '').trim()) && /^\d{1,5}$/.test(String(rule['dst-port'] || '').trim()));
                        }

                        const internalPorts = Array.from(new Set(natRules
                            .map((rule) => String(rule['to-ports'] || '').trim())
                            .filter((port) => /^\d{1,5}$/.test(port))));
                        const hasIndividualComments = natRules.some((rule) => /\(Port\s+\d+\)/i.test(rule.comment || ''));

                        if (natSnapshotInput) {
                            natSnapshotInput.value = JSON.stringify(natRules);
                        }

                        this.setPortInputs('edit', internalPorts);

                        if (syncNatCheckbox) {
                            syncNatCheckbox.checked = natRules.length > 0;
                        }

                        if (multipleNatCheckbox) {
                            multipleNatCheckbox.checked = hasIndividualComments;
                        }

                        this.toggleEditNatSection(Boolean(syncNatCheckbox?.checked));
                        this.syncPortInputs('edit');
                    }
                } catch (error) {
                    this.showAlert('Unable to load current NAT mappings for this user. Edit will keep existing mappings unless you enable sync manually.', 'warning');
                } finally {
                    this.hideLoading();
                }

                this.openModal('editUserModal');
            }
            
            async showUserDetails(userId) {
                const user = this.users.find(u => u['.id'] === userId);
                if (!user) return;
                
                try {
                    // Use user password from the existing data (already loaded from getPPPUsers)
                    let userPassword = user.password || '••••••••';
                    let serverIP = window.PPP_APP_CONFIG?.serverHost || '[server_ip]';
                    let ports = [];
                    let detailsWarning = '';
                    
                    // Try to fetch additional details for password, server IP and NAT rules
                    try {
                        const result = await this.fetchAPI('get_user_details', { user_id: userId }, 'POST');
                        
                        
                        if (result.success && result.data) {
                            // Get server IP first
                            if (result.data.server_ip && result.data.server_ip !== '[server_ip]') {
                                serverIP = result.data.server_ip;
                            }
                            
                            // Extract ports from NAT rules for connection info  
                            // API searches by user remote-address (to-addresses field) in firewall NAT rules
                            if (result.data.nat_rules && result.data.nat_rules.length > 0) {
                                ports = result.data.nat_rules
                                    .filter(nat => nat['dst-port'] && nat['dst-port'] !== '')
                                    .map(nat => `${serverIP}:${nat['dst-port']} > ${nat['to-ports'] || 'N/A'}`);
                                
                                // Debug log to check what we're getting
                            }
                            
                            // Update password if available
                            if (result.data.user && result.data.user.password) {
                                userPassword = result.data.user.password;
                            }
                        }
                    } catch (detailsError) {
                        detailsWarning = detailsError.message || 'Unable to refresh user details from RouterOS.';
                    }

                    const serviceEndpoint = this.getServiceEndpoint(user.service, serverIP);
                    const mikrotikConfig = this.generateMikroTikConfig(user, userPassword, serviceEndpoint, ports);
                    
                    const detailsHtml = `
                        <div class="columns is-multiline is-variable is-4">
                            ${detailsWarning ? `
                                <div class="column is-12">
                                    <div class="notification is-warning is-light">
                                        <span class="icon is-small"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></span>
                                        <span>${this.escapeHtml(detailsWarning)} Management port mapping below may be incomplete until RouterOS becomes reachable again.</span>
                                    </div>
                                </div>
                            ` : ''}
                            <div class="column is-12-mobile is-4-tablet">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">User</span>
                                    ${this.escapeHtml(user.name || '-')}
                                </div>
                            </div>
                            <div class="column is-12-mobile is-4-tablet">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">Password</span>
                                    ${this.escapeHtml(userPassword || '-')}
                                </div>
                            </div>
                            <div class="column is-12-mobile is-4-tablet">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">Remote Address</span>
                                    ${this.escapeHtml(user['remote-address'] || '-')}
                                </div>
                            </div>
                            <div class="column is-12-mobile is-6-tablet">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">Service</span>
                                    ${this.escapeHtml(user.service || '-')}
                                </div>
                            </div>
                            <div class="column is-12-mobile is-6-tablet">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">Status</span>
                                    <span class="tag ${user.disabled === 'false' || user.disabled === false || !user.disabled ? 'is-success' : 'is-danger'} is-light">
                                        ${user.disabled === 'false' || user.disabled === false || !user.disabled ? 'Active' : 'Disabled'}
                                    </span>
                                </div>
                            </div>

                            <div class="column is-12">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">VPN Service Endpoint</span>
                                    ${this.escapeHtml(serviceEndpoint.display)}
                                    ${serviceEndpoint.notes.length > 0 ? `<br><small>${serviceEndpoint.notes.map((note) => this.escapeHtml(note)).join('<br>')}</small>` : ''}
                                </div>
                                <p class="help has-text-grey-light">This endpoint follows the published Docker port values saved on the admin page.</p>
                            </div>
                            
                            <div class="column is-12">
                                <div class="ppp-detail-panel">
                                    <span class="ppp-detail-label">Management Port Mapping</span>
                                    ${ports.length > 0 ? ports.join('<br>') : 'No management port mappings configured'}
                                </div>
                                <p class="help has-text-grey-light">These port mappings are intended for management access to the client router after the VPN tunnel is active. They are not the ports used in the <code>connect-to</code> field of the client configuration below.</p>
                            </div>
                            
                            <div class="column is-12">
                                <label class="label admin-label">MikroTik Client VPN Configuration</label>
                                <div class="ppp-code-shell">
                                    <textarea class="textarea admin-input ppp-code-textarea" 
                                              id="mikrotikConfigText" 
                                              readonly 
                                              rows="4">${mikrotikConfig}</textarea>
                                    <button type="button" 
                                            class="button is-success is-light is-small ppp-code-copy" 
                                            onclick="pppManager.copyToClipboard('mikrotikConfigText')"
                                            title="Copy to Clipboard">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                                <p class="help has-text-grey-light">Use this configuration on the client router to establish the VPN tunnel to the server. For L2TP and PPTP, RouterOS clients use <code>connect-to=&lt;host&gt;</code> without a custom port. The port mappings above are separate management access paths after the tunnel is connected.</p>
                            </div>
                            
                            <div class="column is-12">
                                <label class="label admin-label">Additional User Information</label>
                                <div class="ppp-details-grid">
                                    <div class="ppp-detail-panel">
                                        <span class="ppp-detail-label">Service</span>
                                                            <span class="service-badge service-${user.service || 'any'}">
                                                                ${(user.service || 'any').toUpperCase()}
                                                            </span>
                                    </div>
                                    <div class="ppp-detail-panel">
                                        <span class="ppp-detail-label">Profile</span>
                                        ${this.escapeHtml(user.profile || '-')}
                                    </div>
                                    <div class="ppp-detail-panel">
                                        <span class="ppp-detail-label">Status</span>
                                                            <span class="status-badge status-${user.disabled === 'true' ? 'disabled' : 'enabled'}">
                                                                ${user.disabled === 'true' ? 'Disabled' : 'Enabled'}
                                                            </span>
                                    </div>
                                    <div class="ppp-detail-panel">
                                        <span class="ppp-detail-label">Last Caller</span>
                                        ${this.escapeHtml(user['last-caller-id'] || 'Never')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('userDetailsContent').innerHTML = detailsHtml;
                    this.openModal('userDetailsModal');
                    
                } catch (error) {
                    this.showAlert('Error loading user details: ' + error.message, 'danger');
                }
            }
            
            async showPassword(userId) {
                try {
                    const result = await this.fetchAPI('get_user_password', { user_id: userId }, 'POST');
                    
                    if (result.success) {
                        document.getElementById('userDetailPassword').textContent = result.data.password || 'N/A';
                    }
                } catch (error) {
                    this.showAlert('Error loading password: ' + error.message, 'danger');
                }
            }
            
            async toggleUserStatus(userId) {
                const user = this.users.find(u => u['.id'] === userId);
                if (!user) {
                    this.showAlert('User not found', 'danger');
                    return;
                }
                
                const currentStatus = user.disabled === 'true' || user.disabled === true ? 'disabled' : 'enabled';
                const actionText = currentStatus === 'disabled' ? 'enable' : 'disable';
                
                try {
                    this.showLoading();
                    
                    const result = await this.fetchAPI('toggle_ppp_user_status', { user_id: userId }, 'POST');
                    
                    if (result.success) {
                        // Show success message from API response or construct one
                        const message = result.message || `User ${user.name || 'user'} ${actionText}d successfully!`;
                        this.showAlert(message, 'success');
                        
                        // Reload users to get updated status
                        await this.loadUsers();
                        await this.updateStats();
                    } else {
                        throw new Error(result.message || 'Failed to toggle user status');
                    }
                } catch (error) {
                    this.showAlert('Error updating user status: ' + error.message, 'danger');
                } finally {
                    this.hideLoading();
                }
            }
            
            async toggleNATRules(event, username, userId) {
                event.preventDefault();

                const natRow = document.getElementById(`nat-row-${userId}`);

                if (!natRow) return;

                if (!natRow.classList.contains('is-visible')) {
                    // Show NAT rules
                    natRow.classList.add('is-visible');

                    // Track as open
                    this.openNATRows.add(userId);

                    // Load NAT rules
                    await this.loadNATRules(username, userId);
                } else {
                    // Hide NAT rules
                    natRow.classList.remove('is-visible');

                    // Remove from open tracking
                    this.openNATRows.delete(userId);
                }
            }

            async loadNATRules(username, userId) {
                // Escape special characters in userId for CSS selector
                const escapedUserId = CSS.escape(userId);
                const container = document.querySelector(`#nat-row-${escapedUserId} .nat-details-container`);

                if (!container) {
                    return;
                }

                // Check if already cached - use cache to avoid re-loading
                if (this.natCache.has(userId)) {
                    container.innerHTML = this.natCache.get(userId);
                    return;
                }

                try {
                    // Get user details to find remote-address (IP)
                    const user = this.users.find(u => u['.id'] === userId);
                    if (!user) {
                        throw new Error('User not found in local data');
                    }

                    // Try to get NAT rules via get_user_details API (searches by IP and comment)
                    const result = await this.fetchAPI('get_user_details', { user_id: userId }, 'POST');

                    if (result.success && result.data) {
                        // Handle different response structures
                        let natRules = [];

                        // Check if nat_rules is in result.data.nat_rules
                        if (result.data.nat_rules && Array.isArray(result.data.nat_rules)) {
                            natRules = result.data.nat_rules;
                        }
                        // Or if data itself is the array of NAT rules
                        else if (Array.isArray(result.data)) {
                            natRules = result.data;
                        }

                        let natContent;

                        if (natRules.length === 0) {
                            natContent = '<p class="nat-empty">No NAT rules configured for this user</p>';
                        } else {
                            // Get server IP from config for display
                            const serverIP = result.data.server_ip || '103.187.147.74';

                            const rulesHTML = natRules.map((rule, index) => {
                                const dstPort = rule['dst-port'] || '';
                                const toPort = rule['to-ports'] || '';
                                const protocol = rule['protocol'] || 'tcp';

                                // Format sama dengan User Details: serverIP:dst-port > to-port
                                const displayText = `${serverIP}:${dstPort} > ${toPort || 'N/A'}`;
                                const copyText = `${serverIP}:${dstPort}`;

                                return `
                                    <div class="nat-rule-item nat-rule-clickable"
                                         onclick="pppManager.copyNATPort('${this.escapeHtml(copyText)}', event)"
                                         title="Click to copy: ${this.escapeHtml(copyText)}">
                                        <i class="bi bi-arrow-right-circle-fill"></i>
                                        <span class="tag is-dark nat-protocol-tag">${protocol.toUpperCase()}</span>
                                        <span class="nat-port-text">${this.escapeHtml(displayText)}</span>
                                        <i class="bi bi-clipboard-check copy-icon copy-icon-start"></i>
                                    </div>
                                `;
                            }).join('');

                            natContent = `
                                <div class="nat-section-title"><strong>Port Information:</strong></div>
                                <div>${rulesHTML}</div>
                            `;
                        }

                        // Cache the content and display it
                        this.natCache.set(userId, natContent);
                        container.innerHTML = natContent;
                    } else {
                        throw new Error(result.message || 'Failed to load NAT rules');
                    }
                } catch (error) {
                    container.innerHTML = `<p class="has-text-danger nat-error">Error loading NAT rules: ${error.message}</p>`;
                }
            }

            async deleteUser(userId) {
                const user = this.users.find(u => u['.id'] === userId);
                if (!user) {
                    this.showAlert('User not found', 'error');
                    return;
                }
                
                const confirmed = await this.confirmAction({
                    title: `Delete ${user.name}?`,
                    html: `
                        <p>This will also delete all related NAT rules.</p>
                        <p><strong>This action cannot be undone.</strong></p>
                    `,
                    confirmButtonText: 'Delete User',
                    icon: 'warning'
                });

                if (!confirmed) {
                    return;
                }
                
                try {
                    this.showLoading();
                    
                    
                    const result = await this.fetchAPI('delete_ppp_user', { user_id: userId }, 'POST');
                    
                    
                    if (result.success) {
                        this.showAlert(`User "${user.name}" and related NAT rules deleted successfully!`);
                        await this.loadUsers();
                        await this.updateStats();
                    } else {
                        throw new Error(result.message || 'Failed to delete user');
                    }
                } catch (error) {
                    this.showAlert('Error deleting user: ' + error.message, 'error');
                } finally {
                    this.hideLoading();
                }
            }
            
            generateMikroTikConfig(user, password, serviceEndpoint, managementPorts = []) {
                const connectTarget = serviceEndpoint?.connectTarget || '[server_ip]';
                const endpointNotes = serviceEndpoint?.notes || [];
                const endpointDisplay = serviceEndpoint?.display || '[server_ip]';
                const username = user.name || '[username]';
                const service = user.service || 'l2tp';
                const userPassword = password !== '••••••••' ? password : '[password]';
                const clientProfileName = 'heryan-NET';
                const interfaceProfileSlug = clientProfileName.replace(/[^a-zA-Z0-9_-]/g, '-');
                const quoteRouterOsValue = (value) => `"${String(value ?? '')
                    .replace(/\\/g, '\\\\')
                    .replace(/"/g, '\\"')
                    .replace(/\r?\n/g, ' ')}"`;
                
                // Generate service-specific client configuration
                let clientConfig = '';
                let interfaceName = '';
                
                switch (service.toLowerCase()) {
                    case 'l2tp':
                        interfaceName = `l2tp-${interfaceProfileSlug}`;
                        clientConfig = `/interface l2tp-client add connect-to=${quoteRouterOsValue(connectTarget)} disabled=no name=${quoteRouterOsValue(interfaceName)} profile=${quoteRouterOsValue(clientProfileName)} password=${quoteRouterOsValue(userPassword)} user=${quoteRouterOsValue(username)} ;`;
                        break;
                    case 'pptp':
                        interfaceName = `pptp-${interfaceProfileSlug}`;
                        clientConfig = `/interface pptp-client add connect-to=${quoteRouterOsValue(connectTarget)} disabled=no name=${quoteRouterOsValue(interfaceName)} profile=${quoteRouterOsValue(clientProfileName)} password=${quoteRouterOsValue(userPassword)} user=${quoteRouterOsValue(username)} ;`;
                        break;
                    case 'sstp':
                        interfaceName = `sstp-${interfaceProfileSlug}`;
                        clientConfig = `/interface sstp-client add connect-to=${quoteRouterOsValue(connectTarget)} disabled=no name=${quoteRouterOsValue(interfaceName)} profile=${quoteRouterOsValue(clientProfileName)} password=${quoteRouterOsValue(userPassword)} user=${quoteRouterOsValue(username)} ;`;
                        break;
                    case 'any':
                    default:
                        interfaceName = `l2tp-${interfaceProfileSlug}`;
                        clientConfig = `/interface l2tp-client add connect-to=${quoteRouterOsValue(connectTarget)} disabled=no name=${quoteRouterOsValue(interfaceName)} profile=${quoteRouterOsValue(clientProfileName)} password=${quoteRouterOsValue(userPassword)} user=${quoteRouterOsValue(username)} ;`;
                        break;
                }
                
                const serviceNotes = [
                    `VPN Service Endpoint: ${endpointDisplay}`,
                    ...endpointNotes
                ];

                const publishedPortNotes = serviceNotes.length > 0
                    ? `\n# Published Docker Ports\n# ${serviceNotes.join('\n# ')}`
                    : '';

                const managementNotes = managementPorts.length > 0
                    ? `\n# Management Port Mapping (after VPN is connected)\n# ${managementPorts.join('\n# ')}`
                    : '';
                
                const fullConfig = `/ppp profile add name=${quoteRouterOsValue(clientProfileName)};
${clientConfig}${publishedPortNotes}${managementNotes}`;
                
                return fullConfig;
            }
            
            copyToClipboard(elementId) {
                const element = document.getElementById(elementId);
                if (!element) return;

                element.select();
                element.setSelectionRange(0, 99999); // For mobile devices

                try {
                    document.execCommand('copy');
                    this.showAlert('Configuration copied to clipboard!', 'success');
                } catch (err) {
                    // Failed to copy text
                    this.showAlert('Failed to copy to clipboard. Please select and copy manually.', 'warning');
                }
            }

            copyNATPort(portText, event) {
                // Prevent row collapse when clicking
                if (event) {
                    event.stopPropagation();
                }

                // Use modern Clipboard API if available
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(portText).then(() => {
                        this.showAlert(`NAT Port copied: ${portText}`, 'success');
                    }).catch(err => {
                        this.fallbackCopy(portText);
                    });
                } else {
                    this.fallbackCopy(portText);
                }
            }

            fallbackCopy(text) {
                // Fallback method for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-9999px';
                document.body.appendChild(textArea);
                textArea.select();

                try {
                    document.execCommand('copy');
                    this.showAlert(`NAT Port copied: ${text}`, 'success');
                } catch (err) {
                    this.showAlert('Failed to copy to clipboard.', 'danger');
                } finally {
                    document.body.removeChild(textArea);
                }
            }
            
            escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
            }
            
            // Bulk operations
            getSelectedUsers() {
                const checkboxes = document.querySelectorAll('input[data-user-id]:checked');
                return Array.from(checkboxes).map(cb => cb.dataset.userId);
            }
            
            async bulkDeleteUsers() {
                const selectedIds = this.getSelectedUsers();
                if (selectedIds.length === 0) {
                    this.showAlert('Please select users to delete.', 'warning');
                    return;
                }
                
                // Get user names for confirmation
                const selectedUsers = this.users.filter(u => selectedIds.includes(u['.id']));
                const userNames = selectedUsers.map(u => u.name).join(', ');
                
                const confirmed = await this.confirmAction({
                    title: `Delete ${selectedIds.length} selected user(s)?`,
                    html: `
                        <p><strong>Users:</strong> ${this.escapeHtml(userNames)}</p>
                        <p>This will also delete all related NAT rules.</p>
                        <p><strong>This action cannot be undone.</strong></p>
                    `,
                    confirmButtonText: 'Delete Users',
                    icon: 'warning'
                });

                if (!confirmed) {
                    return;
                }
                
                try {
                    this.showLoading();
                    
                    
                    const result = await this.fetchAPI('bulk_delete_ppp_users', { user_ids: selectedIds }, 'POST');
                    
                    
                    if (result.success) {
                        this.showAlert(`${selectedIds.length} user(s) and their NAT rules deleted successfully!`);
                        await this.loadUsers();
                        await this.updateStats();
                        // Clear selections after successful delete
                        document.querySelectorAll('input[data-user-id]:checked').forEach(cb => cb.checked = false);
                        document.getElementById('selectAll').checked = false;
                        this.selectedUsers.clear();
                        this.updateBulkActions();
                    } else {
                        this.showAlert('Failed to delete users: ' + result.message, 'error');
                    }
                } catch (error) {
                    this.showAlert('Error deleting users: ' + error.message, 'error');
                } finally {
                    this.hideLoading();
                }
            }
            
            async bulkToggleStatus() {
                const selectedIds = this.getSelectedUsers();
                if (selectedIds.length === 0) {
                    this.showAlert('Please select users to toggle status.', 'warning');
                    return;
                }
                
                // Get user names for confirmation
                const selectedUsers = this.users.filter(u => selectedIds.includes(u['.id']));
                const userNames = selectedUsers.map(u => u.name).join(', ');
                
                const confirmed = await this.confirmAction({
                    title: `Toggle status for ${selectedIds.length} user(s)?`,
                    html: `<p><strong>Users:</strong> ${this.escapeHtml(userNames)}</p>`,
                    confirmButtonText: 'Toggle Status',
                    icon: 'warning'
                });

                if (!confirmed) {
                    return;
                }
                
                try {
                    this.showLoading();
                    
                    
                    const result = await this.fetchAPI('bulk_toggle_ppp_users', { user_ids: selectedIds }, 'POST');
                    
                    
                    if (result.success) {
                        this.showAlert(`Status toggled for ${selectedIds.length} user(s) successfully!`);
                        await this.loadUsers();
                        await this.updateStats();
                    } else {
                        this.showAlert('Failed to toggle status: ' + result.message, 'error');
                    }
                } catch (error) {
                    this.showAlert('Error toggling status: ' + error.message, 'error');
                } finally {
                    this.hideLoading();
                }
            }
            
            // Sorting functionality
            sortUsers(column) {
                if (this.sortField === column) {
                    this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortField = column;
                    this.sortDirection = 'asc';
                }
                this.renderUsers();
                this.updateSortHeaders();
            }
            
            updateSortHeaders() {
                // Remove all active indicators
                document.querySelectorAll('.sort-header').forEach(th => {
                    th.classList.remove('has-text-link');
                    th.classList.add('has-text-grey-light');
                });

                // Add indicator to current column
                const currentHeader = document.querySelector(`[data-sort="${this.sortField}"]`);
                if (currentHeader) {
                    currentHeader.classList.remove('has-text-grey-light');
                    currentHeader.classList.add('has-text-link');
                }
            }
        }
        
        // Utility functions
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('serviceFilter').value = '';
            document.getElementById('statusFilter').value = '';
            pppManager.filterUsers();
        }
        
        function generateRandomName() {
            const prefixes = ['user', 'vpn', 'client', 'guest'];
            const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
            const number = Math.floor(Math.random() * 9999) + 1;
            document.getElementById('userName').value = prefix + number.toString().padStart(4, '0');
        }
        
        function generateRandomPassword() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let password = '';
            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('userPassword').value = password;
        }
        
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + 'Icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'bi bi-eye';
            }
        }
        
        function bulkDeleteUsers() {
            pppManager.bulkDeleteUsers();
        }
        
        function bulkToggleStatus() {
            pppManager.bulkToggleStatus();
        }
        
        
        // Initialize PPP Manager when DOM is loaded
        let pppManager;
        document.addEventListener('DOMContentLoaded', function() {
            pppManager = new PPPManager();
        });
        
        // Session timeout handler
        let sessionTimeout;
        function resetSessionTimeout() {
            clearTimeout(sessionTimeout);
            sessionTimeout = setTimeout(() => {
                if (window.AppSwal) {
                    window.AppSwal.sessionExpired('../index.php?timeout=1');
                } else {
                    alert('Session expired. Redirecting to login page.');
                    window.location.href = '../index.php?timeout=1';
                }
            }, <?php echo SESSION_TIMEOUT * 1000; ?>);
        }
        
        // Reset timeout on user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetSessionTimeout, { passive: true });
        });
        
        resetSessionTimeout();
    </script>
</body>
</html>
