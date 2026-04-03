<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/mikrotik.php';
require_once '../includes/wireguard_ui.php';
require_once '../includes/wireguard_script.php';
require_once '../includes/ui.php';

define('SESSION_TIMEOUT', 3600);

checkSession();

$current_page = 'wireguard';
$page_title = 'WireGuard Peers';
$page_subtitle = 'Manage WireGuard client peers and configuration exports';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mikrotik_config = getConfig('mikrotik');
if (!$mikrotik_config || empty($mikrotik_config['host']) || empty($mikrotik_config['username'])) {
    $_SESSION['ppp_error'] = 'MikroTik configuration is required. Please configure your router settings first.';
    header('Location: admin.php');
    exit;
}

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
        return array_map(function ($item) use ($context) {
            return sanitizeOutput($item, $context);
        }, $data);
    }

    switch ($context) {
        case 'html':
            return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
        case 'js':
            return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        default:
            return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
    }
}

$wireguard_defaults = [
    'interface' => trim((string)($mikrotik_config['wireguard_interface'] ?? 'wireguard1')) ?: 'wireguard1',
    'endpoint' => trim((string)($mikrotik_config['wireguard_host'] ?? $mikrotik_config['host'] ?? '')),
    'port' => trim((string)($mikrotik_config['wireguard_port'] ?? '13231')) ?: '13231',
    'server_address' => trim((string)($mikrotik_config['wireguard_server_address'] ?? '10.66.66.1/24')) ?: '10.66.66.1/24',
];
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
    <div class="app-shell">
        <?php renderAppNavbar($current_page); ?>

        <main class="main-content topbar-main-content">
            <?php renderPageHeader('bi bi-hurricane', $page_title, $page_subtitle); ?>

            <div id="alerts-container"></div>

            <div class="columns is-multiline is-variable is-4 page-card-grid">
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value" id="wg-total-peers">-</div>
                                <div class="stat-label">Total Peers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value has-text-success" id="wg-online-peers">-</div>
                                <div class="stat-label">Online Peers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value has-text-warning" id="wg-offline-peers">-</div>
                                <div class="stat-label">Offline Peers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value is-size-6" id="wg-endpoint-card"><?php echo sanitizeOutput(($wireguard_defaults['endpoint'] !== '' ? $wireguard_defaults['endpoint'] : 'not-set') . ':' . $wireguard_defaults['port']); ?></div>
                                <div class="stat-label">Published Endpoint</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="notification is-light order-notice order-notice-inline">
                <div class="order-simple-list">
                    <div><strong>Interface:</strong> <?php echo sanitizeOutput($wireguard_defaults['interface']); ?></div>
                    <div><strong>Server Address:</strong> <?php echo sanitizeOutput($wireguard_defaults['server_address']); ?></div>
                    <div><strong>Endpoint:</strong> <?php echo sanitizeOutput(($wireguard_defaults['endpoint'] !== '' ? $wireguard_defaults['endpoint'] : 'not-set') . ':' . $wireguard_defaults['port'] . '/udp'); ?></div>
                </div>
            </div>

            <div class="search-filters ppp-filter-shell">
                <div class="columns is-multiline is-variable is-4">
                    <div class="column is-12-mobile is-6-tablet is-5-desktop">
                        <div class="field">
                            <label for="wgSearchInput" class="label admin-label">Search Peers</label>
                            <div class="control has-icons-left">
                                <input type="text" class="input admin-input" id="wgSearchInput" placeholder="Search by name, comment, IP, or key">
                                <span class="icon is-left"><i class="bi bi-search"></i></span>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet is-3-desktop">
                        <div class="field">
                            <label for="wgStatusFilter" class="label admin-label">Status</label>
                            <div class="control">
                                <div class="select is-fullwidth">
                                    <select id="wgStatusFilter">
                                        <option value="">All Status</option>
                                        <option value="online">Online</option>
                                        <option value="offline">Offline</option>
                                        <option value="disabled">Disabled</option>
                                        <option value="enabled">Enabled</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet is-2-desktop is-flex is-align-items-flex-end">
                        <button class="button is-dark is-outlined admin-action-button is-fullwidth" type="button" onclick="clearWireGuardFilters()">
                            <i class="bi bi-x-circle"></i>
                            <span>Clear</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="bulk-actions" id="wgBulkActions">
                <div class="ppp-bulk-actions-bar is-flex is-justify-content-space-between is-align-items-center is-flex-direction-column-mobile is-align-items-flex-start-mobile">
                    <div class="has-text-weight-semibold">
                        <span id="wgSelectedCount">0</span> peers selected
                    </div>
                    <div class="buttons ppp-buttons-inline">
                        <button class="button is-warning is-small admin-action-button" type="button" onclick="bulkToggleWireGuardPeers()">
                            <i class="bi bi-toggle-off"></i>
                            <span class="is-hidden-mobile">Toggle Status</span>
                            <span class="is-hidden-tablet">Toggle</span>
                        </button>
                        <button class="button is-danger is-small admin-action-button" type="button" onclick="bulkDeleteWireGuardPeers()">
                            <i class="bi bi-trash"></i>
                            <span class="is-hidden-mobile">Delete Selected</span>
                            <span class="is-hidden-tablet">Delete</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="card users-table app-table-shell">
                <div class="card-header admin-card-header ppp-table-header">
                    <div class="card-header-content">
                        <div class="card-icon">
                            <i class="bi bi-hurricane"></i>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">WireGuard Peers</h5>
                            <small class="card-subtitle">Create, export, and manage WireGuard client peers</small>
                        </div>
                    </div>
                    <button class="button is-primary admin-action-button" type="button" data-open-modal="addWireGuardPeerModal">
                        <i class="bi bi-plus-circle"></i>
                        <span class="is-hidden-mobile">Add Peer</span>
                        <span class="is-hidden-tablet">Add</span>
                    </button>
                </div>
                <div class="table-container app-table-wrapper">
                    <table class="table is-fullwidth is-hoverable app-table" id="wireGuardPeersTable">
                        <thead>
                            <tr>
                                <th scope="col" width="40"><input type="checkbox" id="wgSelectAll"></th>
                                <th scope="col">Peer</th>
                                <th scope="col" class="has-text-centered">Client Address</th>
                                <th scope="col" class="has-text-centered is-hidden-touch">Endpoint</th>
                                <th scope="col" class="has-text-centered">Handshake</th>
                                <th scope="col" class="has-text-centered">Status</th>
                                <th scope="col" class="has-text-centered is-hidden-touch">Traffic</th>
                                <th scope="col" width="220">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="wireGuardPeersTableBody">
                            <tr>
                                <td colspan="8" class="has-text-centered">
                                    <div class="app-empty-state">
                                        <div class="empty-icon"><i class="bi bi-hurricane"></i></div>
                                        <div class="empty-title">Loading WireGuard peers...</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <?php renderWireGuardModals(); ?>
    <?php renderWireGuardPageScript($mikrotik_config, SESSION_TIMEOUT); ?>
</body>
</html>
