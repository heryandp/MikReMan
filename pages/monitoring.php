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
$current_page = 'monitoring';
$page_title = 'Network Monitoring';
$page_subtitle = 'Monitor network hosts using Netwatch';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Check if MikroTik configuration exists
$mikrotik_config = getConfig('mikrotik');
if (!$mikrotik_config || empty($mikrotik_config['host']) || empty($mikrotik_config['username'])) {
    // Redirect to admin with message
    $_SESSION['monitoring_error'] = 'MikroTik configuration is required. Please configure your router settings first.';
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
    $_SESSION['monitoring_error'] = 'Cannot connect to MikroTik router. Please check your credentials and network connection.';
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

        <!-- Main Content -->
        <main class="main-content topbar-main-content">
                <?php
                renderPageHeader(
                    'bi bi-activity',
                    $page_title,
                    $page_subtitle,
                    '<button class="button is-primary admin-action-button" type="button" data-open-modal="addNetwatchModal"><i class="bi bi-plus-circle"></i><span class="is-hidden-mobile">Add Host</span><span class="is-hidden-tablet">Add</span></button>'
                );
                ?>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Statistics Cards -->
                <div class="columns is-multiline is-variable is-4 page-card-grid">
                    <div class="column is-12-mobile is-6-tablet is-3-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-icon">
                                        <i class="bi bi-hdd-network"></i>
                                    </div>
                                    <div class="stat-value" id="totalHosts">0</div>
                                    <div class="stat-label">Total Hosts</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet is-3-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-icon has-text-success">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                    <div class="stat-value has-text-success" id="hostsUp">0</div>
                                    <div class="stat-label">Hosts Up</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet is-3-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-icon has-text-danger">
                                        <i class="bi bi-x-circle"></i>
                                    </div>
                                    <div class="stat-value has-text-danger" id="hostsDown">0</div>
                                    <div class="stat-label">Hosts Down</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet is-3-desktop">
                        <div class="card ppp-card page-card page-card-compact">
                            <div class="card-body page-card-body">
                                <div class="stat-card">
                                    <div class="stat-icon has-text-info">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <div class="stat-value" id="avgResponse">-</div>
                                    <div class="stat-label">Avg Response</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Netwatch Table -->
                <div class="card users-table app-table-shell">
                    <div class="card-header admin-card-header">
                        <div class="card-header-content">
                            <div class="card-icon">
                                <i class="bi bi-broadcast-pin"></i>
                            </div>
                            <div class="card-title-group">
                                <h5 class="card-title">Network Hosts</h5>
                                <small class="card-subtitle">Monitor your network hosts with Netwatch</small>
                            </div>
                        </div>
                    </div>
                    <div class="table-container app-table-wrapper">
                        <div>
                            <table class="table is-fullwidth is-hoverable is-striped app-table">
                        <thead>
                            <tr>
                                <th scope="col">Host</th>
                                <th scope="col">Name</th>
                                <th scope="col">Status</th>
                                <th scope="col">Response Time</th>
                                <th scope="col" class="is-hidden-touch">Since</th>
                                <th scope="col" class="is-hidden-touch">Interval</th>
                                <th scope="col" class="is-hidden-touch">Timeout</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="netwatchTableBody">
                            <tr>
                                <td colspan="8" class="has-text-centered">
                                    <div class="app-empty-state">
                                        <span class="icon"><i class="bi bi-arrow-repeat spin has-text-info"></i></span>
                                        <p>Loading netwatch hosts...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                        </div>
                    </div>
                </div>
        </main>

        <div class="modal" id="addNetwatchModal" role="dialog" aria-modal="true" aria-labelledby="addNetwatchModalTitle">
                <div class="modal-background" data-close-modal="addNetwatchModal"></div>
                <form class="modal-card app-modal-card" id="addNetwatchForm">
                    <header class="modal-card-head app-modal-head">
                        <p class="modal-card-title app-modal-title" id="addNetwatchModalTitle">
                            <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                            <span>Add Network Host</span>
                        </p>
                        <button class="delete" aria-label="close" data-close-modal="addNetwatchModal"></button>
                    </header>
                    <section class="modal-card-body app-modal-body">
                        <div class="field">
                            <label class="label admin-label">Host IP/Domain</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="netwatchHost" required placeholder="192.168.1.1 or google.com">
                            </div>
                        </div>
                        <div class="field">
                            <label class="label admin-label">Name (Optional)</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="netwatchName" placeholder="Gateway, DNS Server, etc.">
                            </div>
                        </div>
                        <div class="columns is-variable is-4">
                            <div class="column">
                                <div class="field">
                                    <label class="label admin-label">Interval</label>
                                    <div class="control">
                                        <input type="text" class="input admin-input" id="netwatchInterval" value="10s" placeholder="10s">
                                    </div>
                                    <p class="help has-text-grey-light">e.g., 10s, 1m, 5m</p>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label class="label admin-label">Timeout</label>
                                    <div class="control">
                                        <input type="text" class="input admin-input" id="netwatchTimeout" value="5s" placeholder="5s">
                                    </div>
                                    <p class="help has-text-grey-light">e.g., 1s, 5s, 10s</p>
                                </div>
                            </div>
                        </div>
                    </section>
                    <footer class="modal-card-foot app-modal-foot">
                        <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="addNetwatchModal">Cancel</button>
                        <button type="submit" class="button is-primary admin-action-button">
                            <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                            <span>Add Host</span>
                        </button>
                    </footer>
                </form>
            </div>
        </div>
    </div>

    <script>
        class MonitoringManager {
            constructor() {
                this.refreshInterval = null;
                this.init();
            }

            init() {
                this.loadNetwatch();
                this.startAutoRefresh();
            }

            async loadNetwatch() {
                try {
                    const response = await fetch('/api/mikrotik.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'get_netwatch' })
                    });

                    const result = await response.json();

                    if (result.success) {
                        this.renderNetwatch(result.data);
                        this.updateStats(result.data);
                    } else {
                        this.showError(result.message);
                    }
                } catch (error) {
                    console.error('Error loading netwatch:', error);
                    this.showError('Failed to load netwatch hosts');
                }
            }

            renderNetwatch(hosts) {
                const tbody = document.getElementById('netwatchTableBody');

                if (!hosts || hosts.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8">
                                <div class="app-empty-state">
                                    <span class="icon"><i class="bi bi-inbox has-text-grey-light"></i></span>
                                    <p>No netwatch hosts configured</p>
                                    <button class="button is-primary is-small admin-action-button" type="button" data-open-modal="addNetwatchModal">
                                        <i class="bi bi-plus-circle"></i>
                                        <span>Add First Host</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                tbody.innerHTML = hosts.map(host => {
                    const status = host.status || 'unknown';
                    const statusClass = status === 'up' ? 'status-up' :
                                      status === 'down' ? 'status-down' : 'status-unknown';
                    const statusIcon = status === 'up' ? 'check-circle' :
                                     status === 'down' ? 'x-circle' : 'question-circle';

                    return `
                        <tr>
                            <td><code>${this.escapeHtml(host.host)}</code></td>
                            <td>${this.escapeHtml(host.comment || host.name || '-')}</td>
                            <td>
                                <span class="status-badge ${statusClass}">
                                    <i class="bi bi-${statusIcon}"></i> ${status.toUpperCase()}
                                </span>
                            </td>
                            <td>
                                <span class="response-time ${host['done-tests'] > 0 ? 'has-text-info' : 'has-text-grey-light'}">
                                    ${host['done-tests'] > 0 ? (host['response-time'] || '-') : '-'}
                                </span>
                            </td>
                            <td class="has-text-grey-light is-hidden-touch">${host.since || '-'}</td>
                            <td class="has-text-grey-light is-hidden-touch">${host.interval || '10s'}</td>
                            <td class="has-text-grey-light is-hidden-touch">${host.timeout || '5s'}</td>
                            <td>
                                <button class="button is-danger is-small app-inline-action" onclick="monitoring.deleteNetwatch('${host['.id']}')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            updateStats(hosts) {
                const total = hosts.length;
                const up = hosts.filter(h => h.status === 'up').length;
                const down = hosts.filter(h => h.status === 'down').length;

                document.getElementById('totalHosts').textContent = total;
                document.getElementById('hostsUp').textContent = up;
                document.getElementById('hostsDown').textContent = down;

                // Calculate average response time
                const responseTimes = hosts
                    .filter(h => h['response-time'] && h.status === 'up')
                    .map(h => {
                        const time = h['response-time'];
                        if (time.includes('ms')) {
                            return parseInt(time);
                        }
                        return 0;
                    })
                    .filter(t => t > 0);

                if (responseTimes.length > 0) {
                    const avg = Math.round(responseTimes.reduce((a, b) => a + b, 0) / responseTimes.length);
                    document.getElementById('avgResponse').textContent = avg + 'ms';
                } else {
                    document.getElementById('avgResponse').textContent = '-';
                }
            }

            async deleteNetwatch(id) {
                const confirmed = await (window.AppSwal
                    ? window.AppSwal.confirm({
                        title: 'Delete Netwatch Host?',
                        text: 'This netwatch host will be removed permanently.',
                        confirmButtonText: 'Delete',
                        icon: 'warning'
                    })
                    : Promise.resolve(confirm('Are you sure you want to delete this netwatch host?')));

                if (!confirmed) {
                    return;
                }

                try {
                    const response = await fetch('/api/mikrotik.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'delete_netwatch',
                            id: id
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        this.showSuccess('Netwatch host deleted successfully');
                        this.loadNetwatch();
                    } else {
                        this.showError(result.message);
                    }
                } catch (error) {
                    console.error('Error deleting netwatch:', error);
                    this.showError('Failed to delete netwatch host');
                }
            }

            startAutoRefresh() {
                // Refresh every 10 seconds
                this.refreshInterval = setInterval(() => {
                    this.loadNetwatch();
                }, 10000);
            }

            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            showSuccess(message) {
                this.showAlert(message, 'success');
            }

            showError(message) {
                this.showAlert(message, 'danger');
            }

            showAlert(message, type) {
                if (window.AppSwal) {
                    window.AppSwal.toast(message, type);
                    return;
                }

                const alertHtml = `
                    <div class="notification ${type === 'success' ? 'is-success' : 'is-danger'} admin-notification fade-in" role="alert">
                        <button type="button" class="delete"></button>
                        ${message}
                    </div>
                `;
                document.getElementById('alertContainer').innerHTML = alertHtml;
                setTimeout(() => {
                    document.getElementById('alertContainer').innerHTML = '';
                }, 5000);
            }
        }

        // Global functions
        async function addNetwatch() {
            const host = document.getElementById('netwatchHost').value.trim();
            const name = document.getElementById('netwatchName').value.trim();
            const interval = document.getElementById('netwatchInterval').value.trim();
            const timeout = document.getElementById('netwatchTimeout').value.trim();

            if (!host) {
                if (window.AppSwal) {
                    window.AppSwal.alert({
                        title: 'Host Required',
                        text: 'Please enter a host IP or domain.',
                        icon: 'warning'
                    });
                } else {
                    alert('Please enter a host IP or domain');
                }
                return;
            }

            try {
                const response = await fetch('/api/mikrotik.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_netwatch',
                        host: host,
                        name: name,
                        interval: interval,
                        timeout: timeout
                    })
                });

                const result = await response.json();

                if (result.success) {
                    closeModal('addNetwatchModal');
                    document.getElementById('addNetwatchForm').reset();
                    monitoring.showSuccess('Netwatch host added successfully');
                    monitoring.loadNetwatch();
                } else {
                    if (window.AppSwal) {
                        window.AppSwal.alert({
                            title: 'Failed to Add Host',
                            text: result.message || 'Unknown error',
                            icon: 'error'
                        });
                    } else {
                        alert(result.message);
                    }
                }
            } catch (error) {
                console.error('Error adding netwatch:', error);
                if (window.AppSwal) {
                    window.AppSwal.alert({
                        title: 'Failed to Add Host',
                        text: 'Failed to add netwatch host',
                        icon: 'error'
                    });
                } else {
                    alert('Failed to add netwatch host');
                }
            }
        }

        // Initialize
        function openModal(id) {
            document.getElementById(id)?.classList.add('is-active');
            document.documentElement.classList.add('is-clipped');
        }

        function closeModal(id) {
            document.getElementById(id)?.classList.remove('is-active');

            if (!document.querySelector('.modal.is-active')) {
                document.documentElement.classList.remove('is-clipped');
            }
        }

        document.getElementById('addNetwatchForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            addNetwatch();
        });

        document.addEventListener('click', (event) => {
            const openId = event.target.closest('[data-open-modal]')?.getAttribute('data-open-modal');
            if (openId) {
                openModal(openId);
                return;
            }

            const closeId = event.target.closest('[data-close-modal]')?.getAttribute('data-close-modal');
            if (closeId) {
                closeModal(closeId);
                return;
            }

            if (event.target.classList.contains('delete')) {
                event.target.parentElement?.remove();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal('addNetwatchModal');
            }
        });

        const topNavbarBurger = document.getElementById('topNavbarBurger');
        const topNavbarMenu = document.getElementById('topNavbarMenu');
        if (topNavbarBurger && topNavbarMenu) {
            topNavbarBurger.addEventListener('click', () => {
                const isActive = topNavbarMenu.classList.toggle('is-active');
                topNavbarBurger.classList.toggle('is-active', isActive);
                topNavbarBurger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
            });
        }

        const monitoring = new MonitoringManager();
    </script>
</body>
</html>
