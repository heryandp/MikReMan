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
                
                <!-- Card 4: CHR Interface Traffic -->
                <div class="columns is-multiline is-variable is-4 page-card-grid">
                    <div class="column is-12">
                        <div class="card dashboard-card page-card">
                            <div class="card-header admin-card-header">
                                <div class="card-header-content">
                                    <div class="card-icon">
                                        <i class="bi bi-activity"></i>
                                    </div>
                                    <div class="card-title-group">
                                        <h5 class="card-title">CHR Interface Traffic</h5>
                                        <small class="card-subtitle">Live throughput by RouterOS interface</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body page-card-body">
                                <div class="dashboard-section-meta">
                                    <div>
                                        <p class="dashboard-kicker">Traffic Overview</p>
                                        <p class="dashboard-muted" id="interface-traffic-summary">Collecting live counters from CHR interfaces...</p>
                                    </div>
                                    <div class="tags are-medium dashboard-tags">
                                        <span class="tag is-dark is-light" id="interface-count-badge">0 interfaces</span>
                                        <span class="tag is-info is-light" id="interface-refresh-badge">Awaiting sample</span>
                                    </div>
                                </div>
                                <div class="interface-traffic-grid" id="interface-traffic-grid">
                                    <div class="interface-traffic-empty">
                                        <i class="bi bi-hourglass-split"></i>
                                        <p>Preparing interface charts...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 5: PPP Logs -->
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
                this.connectionStatus = null;
                this.interfaceHistory = new Map();
                this.maxInterfaceCards = 6;
                this.maxChartSamples = 24;
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
                    const requests = [
                        { key: 'system', url: '../api/mikrotik.php?action=system_resource' },
                        { key: 'ppp', url: '../api/mikrotik.php?action=ppp_stats' },
                        { key: 'logs', url: '../api/mikrotik.php?action=ppp_logs' },
                        { key: 'interfaces', url: '../api/mikrotik.php?action=interface_stats' }
                    ];
                    const results = await Promise.allSettled(
                        requests.map((request) => this.fetchData(request.url))
                    );

                    let hasSuccessfulUpdate = false;

                    results.forEach((result, index) => {
                        if (result.status !== 'fulfilled') {
                            return;
                        }

                        const payload = result.value;
                        if (!payload || !payload.success) {
                            return;
                        }

                        hasSuccessfulUpdate = true;

                        switch (requests[index].key) {
                            case 'system':
                                this.updateSystemResources(payload.data);
                                break;
                            case 'ppp':
                                this.updatePPPStats(payload.data);
                                break;
                            case 'logs':
                                this.updatePPPLogs(payload.data);
                                break;
                            case 'interfaces':
                                this.updateInterfaceTraffic(payload.data);
                                break;
                        }
                    });

                    if (!hasSuccessfulUpdate) {
                        throw new Error('Unable to refresh dashboard data');
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

            updateInterfaceTraffic(payload) {
                const interfaces = Array.isArray(payload?.interfaces) ? payload.interfaces : [];
                const sampledAt = payload?.sampled_at || new Date().toISOString();
                const rankedInterfaces = this.captureInterfaceSamples(interfaces);
                this.renderInterfaceTraffic(rankedInterfaces, sampledAt);
            }

            captureInterfaceSamples(interfaces) {
                const now = Date.now();
                const seen = new Set();
                const rankedInterfaces = [];

                interfaces.forEach((interfaceData) => {
                    const name = String(interfaceData.name || '').trim();
                    if (!name) {
                        return;
                    }

                    seen.add(name);

                    const rxBytes = Number(interfaceData.rx_byte || 0);
                    const txBytes = Number(interfaceData.tx_byte || 0);
                    const previous = this.interfaceHistory.get(name);

                    let rxRate = 0;
                    let txRate = 0;

                    if (previous && previous.lastTimestamp < now) {
                        const elapsedSeconds = (now - previous.lastTimestamp) / 1000;

                        if (elapsedSeconds > 0) {
                            rxRate = Math.max(0, (rxBytes - previous.rxBytes) / elapsedSeconds);
                            txRate = Math.max(0, (txBytes - previous.txBytes) / elapsedSeconds);
                        }
                    }

                    const samples = previous && Array.isArray(previous.samples)
                        ? previous.samples.slice(-(this.maxChartSamples - 1))
                        : [];

                    samples.push({
                        rxRate,
                        txRate
                    });

                    const snapshot = {
                        ...interfaceData,
                        rxRate,
                        txRate,
                        totalRate: rxRate + txRate,
                        totalByte: Number(interfaceData.total_byte || (rxBytes + txBytes)),
                        totalPacket: Number(interfaceData.rx_packet || 0) + Number(interfaceData.tx_packet || 0),
                        samples
                    };

                    this.interfaceHistory.set(name, {
                        lastTimestamp: now,
                        rxBytes,
                        txBytes,
                        samples
                    });

                    rankedInterfaces.push(snapshot);
                });

                Array.from(this.interfaceHistory.keys()).forEach((name) => {
                    if (!seen.has(name)) {
                        this.interfaceHistory.delete(name);
                    }
                });

                rankedInterfaces.sort((left, right) => {
                    if (left.running !== right.running) {
                        return left.running ? -1 : 1;
                    }

                    if (left.disabled !== right.disabled) {
                        return left.disabled ? 1 : -1;
                    }

                    if (left.totalRate !== right.totalRate) {
                        return right.totalRate - left.totalRate;
                    }

                    if (left.totalByte !== right.totalByte) {
                        return right.totalByte - left.totalByte;
                    }

                    return left.name.localeCompare(right.name);
                });

                return rankedInterfaces;
            }

            renderInterfaceTraffic(interfaces, sampledAt) {
                const grid = document.getElementById('interface-traffic-grid');
                const summary = document.getElementById('interface-traffic-summary');
                const countBadge = document.getElementById('interface-count-badge');
                const refreshBadge = document.getElementById('interface-refresh-badge');

                if (!grid || !summary || !countBadge || !refreshBadge) {
                    return;
                }

                const totalInterfaces = interfaces.length;
                let visibleInterfaces = interfaces.filter((interfaceData) => {
                    return interfaceData.running || !interfaceData.disabled || interfaceData.totalByte > 0;
                });

                if (visibleInterfaces.length === 0) {
                    visibleInterfaces = interfaces.slice();
                }

                visibleInterfaces = visibleInterfaces.slice(0, this.maxInterfaceCards);

                countBadge.textContent = `${totalInterfaces} interface${totalInterfaces === 1 ? '' : 's'}`;
                refreshBadge.textContent = `Sample ${new Date(sampledAt).toLocaleTimeString()}`;

                if (visibleInterfaces.length === 0) {
                    summary.textContent = 'No RouterOS interfaces were returned by the CHR.';
                    grid.innerHTML = `
                        <div class="interface-traffic-empty">
                            <i class="bi bi-hdd-network"></i>
                            <p>No interface counters are available right now.</p>
                        </div>
                    `;
                    return;
                }

                summary.textContent = `Showing ${visibleInterfaces.length} most active interfaces from ${totalInterfaces} RouterOS interfaces.`;
                grid.innerHTML = visibleInterfaces.map((interfaceData, index) => this.buildInterfaceCardMarkup(interfaceData, index)).join('');

                visibleInterfaces.forEach((interfaceData, index) => {
                    const canvas = document.getElementById(`interface-chart-${index}`);
                    if (canvas) {
                        this.drawInterfaceChart(canvas, interfaceData.samples || []);
                    }
                });
            }

            buildInterfaceCardMarkup(interfaceData, index) {
                const status = this.getInterfaceStatusMeta(interfaceData);
                const comment = String(interfaceData.comment || '').trim();
                const metaParts = [
                    this.escapeHtml(interfaceData.type || 'unknown'),
                    status.label
                ];

                if (comment) {
                    metaParts.push(this.escapeHtml(comment));
                }

                return `
                    <article class="interface-traffic-card">
                        <div class="interface-traffic-head">
                            <div class="interface-traffic-title">
                                <h6 class="interface-name">${this.escapeHtml(interfaceData.name || '-')}</h6>
                                <p class="interface-meta">${metaParts.join(' · ')}</p>
                            </div>
                            <span class="tag ${status.className}">${status.label}</span>
                        </div>
                        <div class="interface-traffic-metrics">
                            <div class="interface-metric">
                                <span class="interface-metric-label">Download</span>
                                <strong class="interface-metric-value">${this.formatBitrate(interfaceData.rxRate || 0)}</strong>
                            </div>
                            <div class="interface-metric">
                                <span class="interface-metric-label">Upload</span>
                                <strong class="interface-metric-value">${this.formatBitrate(interfaceData.txRate || 0)}</strong>
                            </div>
                            <div class="interface-metric">
                                <span class="interface-metric-label">Total Traffic</span>
                                <strong class="interface-metric-value">${this.formatBytes(interfaceData.totalByte || 0)}</strong>
                            </div>
                        </div>
                        <div class="interface-chart-shell">
                            <canvas class="interface-chart" id="interface-chart-${index}" aria-label="Traffic chart for ${this.escapeHtml(interfaceData.name || ('interface-' + index))}"></canvas>
                        </div>
                        <div class="interface-chart-legend">
                            <span class="legend-item"><span class="legend-swatch legend-download"></span>Download</span>
                            <span class="legend-item"><span class="legend-swatch legend-upload"></span>Upload</span>
                        </div>
                        <div class="interface-traffic-foot">
                            <span>Packets ${this.formatCount(interfaceData.totalPacket || 0)}</span>
                            <span>${this.escapeHtml(interfaceData.actual_mtu || interfaceData.mtu || '-')} MTU</span>
                        </div>
                    </article>
                `;
            }

            drawInterfaceChart(canvas, samples) {
                const context = canvas.getContext('2d');
                if (!context) {
                    return;
                }

                const bounds = canvas.getBoundingClientRect();
                const width = Math.max(120, Math.round(bounds.width || 0));
                const height = Math.max(120, Math.round(bounds.height || 0));
                const devicePixelRatio = window.devicePixelRatio || 1;

                canvas.width = width * devicePixelRatio;
                canvas.height = height * devicePixelRatio;
                context.setTransform(devicePixelRatio, 0, 0, devicePixelRatio, 0, 0);
                context.clearRect(0, 0, width, height);

                const style = getComputedStyle(document.documentElement);
                const gridColor = style.getPropertyValue('--dark-border').trim() || 'rgba(127, 127, 127, 0.25)';
                const rxColor = style.getPropertyValue('--info-color').trim() || '#209cee';
                const txColor = style.getPropertyValue('--warning-color').trim() || '#ffdd57';

                const padding = { top: 10, right: 8, bottom: 12, left: 8 };
                const chartWidth = Math.max(1, width - padding.left - padding.right);
                const chartHeight = Math.max(1, height - padding.top - padding.bottom);
                const safeSamples = samples.length > 0 ? samples : [{ rxRate: 0, txRate: 0 }];
                const maxValue = Math.max(1, ...safeSamples.map((sample) => Math.max(sample.rxRate || 0, sample.txRate || 0)));

                context.lineWidth = 1;
                context.strokeStyle = gridColor;

                for (let index = 0; index < 4; index++) {
                    const y = padding.top + (chartHeight / 3) * index;
                    context.beginPath();
                    context.moveTo(padding.left, y);
                    context.lineTo(width - padding.right, y);
                    context.stroke();
                }

                const drawLine = (key, color) => {
                    context.beginPath();
                    context.lineWidth = 2;
                    context.strokeStyle = color;

                    safeSamples.forEach((sample, sampleIndex) => {
                        const x = safeSamples.length === 1
                            ? padding.left + chartWidth / 2
                            : padding.left + (chartWidth * sampleIndex) / (safeSamples.length - 1);
                        const value = Number(sample[key] || 0);
                        const ratio = Math.min(1, value / maxValue);
                        const y = padding.top + chartHeight - (ratio * chartHeight);

                        if (sampleIndex === 0) {
                            context.moveTo(x, y);
                        } else {
                            context.lineTo(x, y);
                        }
                    });

                    context.stroke();

                    const lastSample = safeSamples[safeSamples.length - 1];
                    const lastX = safeSamples.length === 1
                        ? padding.left + chartWidth / 2
                        : padding.left + chartWidth;
                    const lastRatio = Math.min(1, Number(lastSample[key] || 0) / maxValue);
                    const lastY = padding.top + chartHeight - (lastRatio * chartHeight);

                    context.beginPath();
                    context.fillStyle = color;
                    context.arc(lastX, lastY, 3, 0, Math.PI * 2);
                    context.fill();
                };

                drawLine('rxRate', rxColor);
                drawLine('txRate', txColor);
            }

            getInterfaceStatusMeta(interfaceData) {
                if (interfaceData.disabled) {
                    return {
                        label: 'Disabled',
                        className: 'is-danger is-light'
                    };
                }

                if (interfaceData.running) {
                    return {
                        label: 'Running',
                        className: 'is-success is-light'
                    };
                }

                return {
                    label: 'Idle',
                    className: 'is-warning is-light'
                };
            }
            
            updateConnectionStatus(isConnected) {
                if (isConnected === this.connectionStatus) {
                    return;
                }

                this.connectionStatus = isConnected;

                if (!window.AppSwal) {
                    return;
                }

                if (isConnected) {
                    window.AppSwal.toast('Connected to MikroTik', 'success', {
                        timer: 2200
                    });
                    return;
                }

                window.AppSwal.toast('Connection to MikroTik lost', 'danger', {
                    timer: 3500
                });
            }
            
            updateLastUpdateTime() {
                const now = new Date();
                const timeString = now.toLocaleTimeString();
                document.getElementById('last-update').textContent = `Last updated: ${timeString}`;
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

                const bitsPerSecond = bytesPerSecond * 8;
                const k = 1000;
                const sizes = ['bps', 'Kbps', 'Mbps', 'Gbps'];
                const i = Math.min(sizes.length - 1, Math.floor(Math.log(bitsPerSecond) / Math.log(k)));
                const value = parseFloat((bitsPerSecond / Math.pow(k, i)).toFixed(2));

                if (i === 0) {
                    return (value / 1000).toFixed(1) + ' Kbps';
                }

                if (i === 1) {
                    return value.toFixed(1) + ' ' + sizes[i];
                }

                return value.toFixed(2) + ' ' + sizes[i];
            }

            formatCount(value) {
                return Number(value || 0).toLocaleString();
            }
            
            escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text ?? '').replace(/[&<>"']/g, function(m) { return map[m]; });
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
