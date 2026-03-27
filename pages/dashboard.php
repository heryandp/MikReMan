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
$current_page = 'dashboard';
$page_title = 'Dashboard';
$page_subtitle = 'Real-time monitoring and system overview';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Check if MikroTik configuration exists
$mikrotik_config = getConfig('mikrotik');
if (!$mikrotik_config || empty($mikrotik_config['host']) || empty($mikrotik_config['username'])) {
    // Redirect to admin with message
    $_SESSION['dashboard_error'] = 'MikroTik configuration is required. Please configure your router settings first.';
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
    $_SESSION['dashboard_error'] = 'Cannot connect to MikroTik router. Please check your credentials and network connection.';
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
    <div class="app-shell">
        <?php renderAppNavbar($current_page); ?>
            
            <main class="main-content topbar-main-content">
                <?php renderPageHeader('bi bi-speedometer2', $page_title, $page_subtitle); ?>
                
                <!-- Connection Status -->
                <div class="connection-status connection-toast">
                    <div class="notification is-success admin-notification admin-status-notification" id="connection-alert">
                        <span class="status-indicator status-online"></span>
                        <span>Connected to MikroTik</span>
                    </div>
                </div>
                
                <div id="alerts-container"></div>
                
                <div class="columns is-multiline is-variable is-4 page-card-grid">
                    <!-- Card 1: System Resources -->
                    <div class="column is-12-tablet is-4-desktop">
                        <div class="card dashboard-card page-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <i class="bi bi-cpu"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">System Resources</h5>
                                        <small class="card-subtitle">Router performance metrics</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body page-card-body">
                                <div id="system-resources">
                                    <div class="resource-item">
                                        <span class="resource-label">CPU Usage</span>
                                        <span class="resource-value" id="cpu-load">-</span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Memory</span>
                                        <span class="resource-value" id="memory-usage">-</span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Storage</span>
                                        <span class="resource-value" id="storage-usage">-</span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">RouterOS Version</span>
                                        <span class="resource-value" id="router-version">-</span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Timezone</span>
                                        <span class="resource-value" id="timezone">-</span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Uptime</span>
                                        <span class="resource-value" id="uptime">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card 2: PPP Statistics -->
                    <div class="column is-12-tablet is-4-desktop">
                        <div class="card dashboard-card page-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">PPP Users</h5>
                                        <small class="card-subtitle">User connection statistics</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body page-card-body">
                                <div class="columns is-mobile is-variable is-2 stat-grid has-text-centered">
                                    <div class="column is-4">
                                        <div class="stat-card">
                                            <div class="stat-value" id="total-users">-</div>
                                            <div class="stat-label">Total</div>
                                        </div>
                                    </div>
                                    <div class="column is-4">
                                        <div class="stat-card">
                                            <div class="stat-value has-text-success" id="online-users">-</div>
                                            <div class="stat-label">On</div>
                                        </div>
                                    </div>
                                    <div class="column is-4">
                                        <div class="stat-card">
                                            <div class="stat-value has-text-warning" id="offline-users">-</div>
                                            <div class="stat-label">Off</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Card 3: Selected MikroTik -->
                    <div class="column is-12-tablet is-4-desktop">
                        <div class="card dashboard-card page-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <i class="bi bi-router"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">Router Information</h5>
                                        <small class="card-subtitle">Connected MikroTik device</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body page-card-body">
                                <div id="router-info">
                                    <div class="resource-item">
                                        <span class="resource-label">Host</span>
                                        <span class="resource-value" id="router-host"><?php echo sanitizeOutput($mikrotik_config['host'] ?? '-'); ?></span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Username</span>
                                        <span class="resource-value" id="router-username"><?php echo sanitizeOutput($mikrotik_config['username'] ?? '-'); ?></span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Port</span>
                                        <span class="resource-value" id="router-port"><?php echo sanitizeOutput($mikrotik_config['port'] ?? '443'); ?></span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">SSL</span>
                                        <span class="resource-value" id="router-ssl"><?php echo ($mikrotik_config['use_ssl'] ?? true) ? 'Enabled' : 'Disabled'; ?></span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Board Name</span>
                                        <span class="resource-value" id="board-name">-</span>
                                    </div>
                                    <div class="resource-item">
                                        <span class="resource-label">Architecture</span>
                                        <span class="resource-value" id="architecture">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Card 4: PPP Logs -->
                <div class="columns is-multiline is-variable is-4 page-card-grid">
                    <div class="column is-12">
                        <div class="card dashboard-card page-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <i class="bi bi-file-text"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">PPP Connection Logs</h5>
                                        <small class="card-subtitle">Real-time connection activity</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body page-card-body">
                                <div class="log-container" id="ppp-logs">
                                    <div class="has-text-centered has-text-grey-light">
                                        <i class="bi bi-hourglass-split"></i> Loading logs...
                                    </div>
                                </div>
                                <div class="update-time" id="last-update">
                                    Last updated: -
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
    </div>

    <!-- Dashboard JavaScript -->
    <script>
        class Dashboard {
            constructor() {
                this.updateInterval = null;
                this.connectionStatus = true;
                this.init();
            }
            
            init() {
                this.startUpdates();
                this.bindEvents();
            }
            
            bindEvents() {
                // Handle page visibility change to pause/resume updates
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        this.startUpdates();
                    } else {
                        this.stopUpdates();
                    }
                });
                
                // Handle window unload
                window.addEventListener('beforeunload', () => {
                    this.stopUpdates();
                });
            }
            
            startUpdates() {
                this.stopUpdates(); // Clear any existing interval
                this.updateData(); // Initial update
                this.updateInterval = setInterval(() => {
                    this.updateData();
                }, 1000); // Update every 1 second
            }
            
            stopUpdates() {
                if (this.updateInterval) {
                    clearInterval(this.updateInterval);
                    this.updateInterval = null;
                }
            }
            
            async updateData() {
                try {
                    // Fetch system resources
                    const systemData = await this.fetchData('../api/mikrotik.php?action=system_resource');
                    if (systemData.success) {
                        this.updateSystemResources(systemData.data);
                    }
                    
                    // Fetch PPP statistics
                    const pppStats = await this.fetchData('../api/mikrotik.php?action=ppp_stats');
                    if (pppStats.success) {
                        this.updatePPPStats(pppStats.data);
                    }
                    
                    // Fetch PPP logs
                    const pppLogs = await this.fetchData('../api/mikrotik.php?action=ppp_logs');
                    if (pppLogs.success) {
                        this.updatePPPLogs(pppLogs.data);
                    }
                    
                    this.updateConnectionStatus(true);
                    this.updateLastUpdateTime();
                    
                } catch (error) {
                    console.error('Error updating dashboard:', error);
                    this.updateConnectionStatus(false);
                }
            }
            
            async fetchData(url) {
                const response = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                return await response.json();
            }
            
            updateSystemResources(data) {
                if (!data) return;
                
                document.getElementById('cpu-load').textContent = data['cpu-load'] + '%' || '-';
                
                // Memory calculation
                const totalMem = parseInt(data['total-memory']) || 0;
                const freeMem = parseInt(data['free-memory']) || 0;
                const usedMem = totalMem - freeMem;
                const memPercent = totalMem > 0 ? Math.round((usedMem / totalMem) * 100) : 0;
                document.getElementById('memory-usage').textContent = `${this.formatBytes(usedMem)} / ${this.formatBytes(totalMem)} (${memPercent}%)`;
                
                // Storage calculation
                const totalHdd = parseInt(data['total-hdd-space']) || 0;
                const freeHdd = parseInt(data['free-hdd-space']) || 0;
                const usedHdd = totalHdd - freeHdd;
                const hddPercent = totalHdd > 0 ? Math.round((usedHdd / totalHdd) * 100) : 0;
                document.getElementById('storage-usage').textContent = `${this.formatBytes(usedHdd)} / ${this.formatBytes(totalHdd)} (${hddPercent}%)`;
                
                document.getElementById('router-version').textContent = data.version || '-';
                document.getElementById('uptime').textContent = data.uptime || '-';
                document.getElementById('board-name').textContent = data['board-name'] || '-';
                document.getElementById('architecture').textContent = data['architecture-name'] || '-';
                
                // Update timezone from system clock if available
                const timezone = data.timezone || new Date().toString().match(/\((.+)\)$/)?.[1] || 'Local Time';
                document.getElementById('timezone').textContent = timezone;
            }
            
            updatePPPStats(data) {
                if (!data) return;
                
                document.getElementById('total-users').textContent = data.total || 0;
                document.getElementById('online-users').textContent = data.online || 0;
                document.getElementById('offline-users').textContent = data.offline || 0;
            }
            
            updatePPPLogs(logs) {
                if (!logs || !Array.isArray(logs)) return;
                
                const logContainer = document.getElementById('ppp-logs');
                
                if (logs.length === 0) {
                    logContainer.innerHTML = '<div class="has-text-centered has-text-grey-light">No recent PPP logs found</div>';
                    return;
                }
                
                // Limit to last 50 entries
                const recentLogs = logs.slice(-50);
                
                logContainer.innerHTML = recentLogs.map(log => `
                    <div class="log-entry">
                        <span class="log-time">${log.time || new Date().toLocaleTimeString()}</span>
                        <span class="log-message"> - ${this.escapeHtml(log.message || log.topics || 'No message')}</span>
                    </div>
                `).join('');
                
                // Auto-scroll to bottom
                logContainer.scrollTop = logContainer.scrollHeight;
            }
            
            updateConnectionStatus(isConnected) {
                const alert = document.getElementById('connection-alert');
                const indicator = alert.querySelector('.status-indicator');
                
                if (isConnected !== this.connectionStatus) {
                    this.connectionStatus = isConnected;
                    
                    if (isConnected) {
                        alert.className = 'notification is-success admin-notification admin-status-notification';
                        indicator.className = 'status-indicator status-online';
                        alert.innerHTML = '<span class="status-indicator status-online"></span><span>Connected to MikroTik</span>';
                    } else {
                        alert.className = 'notification is-danger admin-notification admin-status-notification';
                        indicator.className = 'status-indicator status-offline';
                        alert.innerHTML = '<span class="status-indicator status-offline"></span><span>Connection Lost</span>';
                    }
                }
            }
            
            updateLastUpdateTime() {
                const now = new Date();
                const timeString = now.toLocaleTimeString();
                document.getElementById('last-update').textContent = `Last updated: ${timeString}`;
            }
            
            formatBytes(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }
        }
        
        // Initialize dashboard when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            const topNavbarBurger = document.getElementById('topNavbarBurger');
            const topNavbarMenu = document.getElementById('topNavbarMenu');
            if (topNavbarBurger && topNavbarMenu) {
                topNavbarBurger.addEventListener('click', () => {
                    const isActive = topNavbarMenu.classList.toggle('is-active');
                    topNavbarBurger.classList.toggle('is-active', isActive);
                    topNavbarBurger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                });
            }

            new Dashboard();
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
