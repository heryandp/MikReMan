<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/go2rtc.php';
require_once '../includes/ui.php';

define('SESSION_TIMEOUT', 3600);

checkSession();

$current_page = 'cctv';
$page_title = 'CCTV Streams';
$page_subtitle = 'Manage source aliases, upstream camera URLs, and YouTube publish targets';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);
$details = $overview['details'] ?? [];
$usage_summary = $overview['usage_summary'] ?? [];
$streams = is_array($overview['streams'] ?? null) ? $overview['streams'] : [];
$youtube_restreams = is_array($overview['youtube_restreams'] ?? null) ? $overview['youtube_restreams'] : [];
$mosaic_restreams = is_array($overview['mosaic_restreams'] ?? null) ? $overview['mosaic_restreams'] : [];
$youtube_aliases = array_values(array_filter(array_map(static function ($restream) {
    return trim((string)($restream['alias'] ?? ''));
}, $youtube_restreams)));
$mosaic_aliases = array_values(array_filter(array_map(static function ($restream) {
    return trim((string)($restream['alias'] ?? ''));
}, $mosaic_restreams)));
$excluded_stream_aliases = array_values(array_unique(array_merge($youtube_aliases, $mosaic_aliases)));
$source_streams = array_values(array_filter($streams, static function ($stream) use ($excluded_stream_aliases) {
    $name = trim((string)($stream['name'] ?? ''));
    return $name !== '' && !in_array($name, $excluded_stream_aliases, true);
}));

function sanitizeOutput($data, $context = 'html')
{
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

function maskSecretTail(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }

    $parts = explode('/', $value);
    $tail = array_pop($parts);
    if ($tail === null || $tail === '') {
        return $value;
    }

    $maskedTail = strlen($tail) > 6
        ? substr($tail, 0, 3) . '...' . substr($tail, -3)
        : '***';

    $parts[] = $maskedTail;
    return implode('/', $parts);
}

function formatUsageBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    $precision = $unitIndex === 0 ? 0 : 1;
    return number_format($size, $precision) . ' ' . $units[$unitIndex];
}

function renderUsageBreakdown(array $usage, string $period): string
{
    $entry = is_array($usage[$period] ?? null) ? $usage[$period] : [];
    $rx = formatUsageBytes((int)($entry['rx_bytes'] ?? 0));
    $tx = formatUsageBytes((int)($entry['tx_bytes'] ?? 0));
    $total = formatUsageBytes((int)($entry['rx_bytes'] ?? 0) + (int)($entry['tx_bytes'] ?? 0));

    return $total . ' (IN ' . $rx . ' / OUT ' . $tx . ')';
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderSweetAlertAssets('..'); ?>
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php renderThemeScript('../assets/js/theme.js'); ?>
</head>
<body class="admin-body">
    <div class="app-shell">
        <?php renderAppNavbar($current_page); ?>

        <main class="main-content topbar-main-content">
            <?php
            renderPageHeader(
                'bi bi-camera-video-fill',
                $page_title,
                $page_subtitle,
                '<div class="buttons"><button class="button is-primary admin-action-button" type="button" data-open-modal="addCctvStreamModal"><i class="bi bi-plus-circle"></i><span>Add Stream</span></button><a class="button is-link is-light admin-action-button" href="' . sanitizeOutput($details['web_url'] ?? '#') . '" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i><span>Open go2rtc</span></a></div>'
            );
            ?>

            <div id="alerts-container"></div>

            <div class="columns is-multiline is-variable is-4 page-card-grid">
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value <?php echo !empty($overview['online']) ? 'has-text-success' : 'has-text-danger'; ?>" id="cctv-service-status"><?php echo !empty($overview['online']) ? 'Online' : 'Offline'; ?></div>
                                <div class="stat-label">go2rtc Status</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value" id="cctv-stream-count"><?php echo sanitizeOutput((string)($overview['stream_count'] ?? 0)); ?></div>
                                <div class="stat-label">Source Aliases</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value is-size-6" id="cctv-rtsp-port"><?php echo sanitizeOutput($details['rtsp_port'] ?? '-'); ?></div>
                                <div class="stat-label">Relay RTSP Port</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value is-size-6" id="cctv-endpoint-mode"><?php echo sanitizeOutput($details['endpoint_mode'] ?? 'Auto'); ?></div>
                                <div class="stat-label">Endpoint Mode</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value is-size-6" id="cctv-usage-today"><?php echo sanitizeOutput(formatUsageBytes((int)($usage_summary['today']['rx_bytes'] ?? 0) + (int)($usage_summary['today']['tx_bytes'] ?? 0))); ?></div>
                                <div class="stat-label">Traffic Today</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                    <div class="card ppp-card page-card page-card-compact">
                        <div class="card-body page-card-body">
                            <div class="stat-card">
                                <div class="stat-value is-size-6" id="cctv-usage-month"><?php echo sanitizeOutput(formatUsageBytes((int)($usage_summary['month_30d']['rx_bytes'] ?? 0) + (int)($usage_summary['month_30d']['tx_bytes'] ?? 0))); ?></div>
                                <div class="stat-label">Traffic 30d</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="notification is-light order-notice order-notice-inline">
                <div class="order-simple-list">
                    <div><strong>Web UI:</strong> <a href="<?php echo sanitizeOutput($details['web_url'] ?? '#'); ?>" target="_blank" rel="noopener noreferrer" id="cctv-web-url"><?php echo sanitizeOutput($details['web_url'] ?? '-'); ?></a></div>
                    <div><strong>Relay URL Pattern:</strong> <code id="cctv-rtsp-template"><?php echo sanitizeOutput($details['rtsp_url_template'] ?? '-'); ?></code></div>
                    <div><strong>WebRTC UI:</strong> <a href="<?php echo sanitizeOutput($details['webrtc_url'] ?? '#'); ?>" target="_blank" rel="noopener noreferrer" id="cctv-webrtc-url"><?php echo sanitizeOutput($details['webrtc_url'] ?? '-'); ?></a></div>
                    <div><strong>API Target:</strong> <code id="cctv-api-target"><?php echo sanitizeOutput($details['api_target'] ?? '-'); ?></code></div>
                    <div><strong>API Probe:</strong> <code id="cctv-api-probe"><?php echo sanitizeOutput($details['api_probe'] ?? '-'); ?></code></div>
                    <div><strong>Config Path:</strong> <code id="cctv-config-path"><?php echo sanitizeOutput($details['config_path'] ?? '-'); ?></code></div>
                    <div><strong>Version:</strong> <span id="cctv-version"><?php echo sanitizeOutput($details['version'] ?? '-'); ?></span></div>
                </div>
            </div>

            <div class="columns is-multiline is-variable is-4 page-card-grid mt-5">
                <div class="column is-12">
                    <div class="card enhanced-card admin-card">
                        <div class="card-header admin-card-header">
                            <div class="card-header-content">
                                <div class="card-icon">
                                    <span class="icon"><i class="bi bi-camera-reels" aria-hidden="true"></i></span>
                                </div>
                                <div class="card-title-group">
                                    <h5 class="card-title">CCTV Manager</h5>
                                    <small class="card-subtitle">Manage source aliases and YouTube publish targets from one tabbed workspace</small>
                                </div>
                            </div>
                        </div>
                        <div class="card-body admin-card-body">
                            <div class="tabs is-toggle is-fullwidth admin-tabs" role="tablist" aria-label="CCTV Sections">
                                <ul>
                                    <li class="is-active" data-cctv-tab="sources" role="presentation">
                                        <a href="#cctv-tab-sources" id="cctv-tab-sources-link" role="tab" aria-selected="true">
                                            <span class="icon"><i class="bi bi-camera-reels"></i></span>
                                            <span>Source Streams</span>
                                        </a>
                                    </li>
                                    <li data-cctv-tab="youtube" role="presentation">
                                        <a href="#cctv-tab-youtube" id="cctv-tab-youtube-link" role="tab" aria-selected="false">
                                            <span class="icon"><i class="bi bi-youtube"></i></span>
                                            <span>YouTube Restream</span>
                                        </a>
                                    </li>
                                    <li data-cctv-tab="mosaic" role="presentation">
                                        <a href="#cctv-tab-mosaic" id="cctv-tab-mosaic-link" role="tab" aria-selected="false">
                                            <span class="icon"><i class="bi bi-layout-split"></i></span>
                                            <span>YouTube Mosaic</span>
                                        </a>
                                    </li>
                                    <li data-cctv-tab="monitor" role="presentation">
                                        <a href="#cctv-tab-monitor" id="cctv-tab-monitor-link" role="tab" aria-selected="false">
                                            <span class="icon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                                            <span>Multi Monitor</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>

                            <div class="admin-tab-panels">
                                <section class="admin-tab-panel is-active" id="cctv-tab-sources" data-cctv-panel="sources" role="tabpanel" aria-labelledby="cctv-tab-sources-link">
                                    <div class="is-flex is-justify-content-space-between is-align-items-center mb-4">
                                        <div>
                                            <h6 class="title is-6 mb-1">Source Streams</h6>
                                            <p class="is-size-7 has-text-grey-light mb-0">Each row maps one source alias to one upstream camera URL in go2rtc.</p>
                                        </div>
                                        <button class="button is-primary admin-action-button" type="button" data-open-modal="addCctvStreamModal">
                                            <i class="bi bi-plus-circle"></i>
                                            <span class="is-hidden-mobile">Add Stream</span>
                                            <span class="is-hidden-tablet">Add</span>
                                        </button>
                                    </div>
                                    <div class="table-container app-table-wrapper">
                                        <table class="table is-fullwidth is-hoverable app-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Source Alias</th>
                                                    <th scope="col">Upstream Camera URL</th>
                                                    <th scope="col">Relay URL</th>
                                                    <th scope="col" class="has-text-centered">Viewers</th>
                                                    <th scope="col">Today</th>
                                                    <th scope="col">30d</th>
                                                    <th scope="col" class="has-text-centered">State</th>
                                                    <th scope="col" width="200">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="cctvStreamsTableBody">
                                            <?php if (empty($source_streams)): ?>
                                                <tr>
                                                    <td colspan="8" class="has-text-centered">
                                                        <div class="app-empty-state">
                                                            <span class="icon"><i class="bi bi-camera-video-off has-text-grey-light"></i></span>
                                                            <p>No source aliases configured yet in go2rtc.</p>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($source_streams as $stream): ?>
                                                    <?php $watchUrl = ($details['web_url'] ?? '') . 'stream.html?src=' . rawurlencode((string)($stream['name'] ?? '')); ?>
                                                    <tr>
                                                        <td>
                                                            <div class="is-flex is-flex-direction-column">
                                                                <strong><?php echo sanitizeOutput($stream['name'] ?? ''); ?></strong>
                                                                <small class="has-text-grey">
                                                                    <span class="tag <?php echo !empty($stream['online']) ? 'is-success is-light' : 'is-warning is-light'; ?> ppp-state-badge">
                                                                        <?php echo !empty($stream['online']) ? 'Online' : 'Idle'; ?>
                                                                    </span>
                                                                </small>
                                                            </div>
                                                        </td>
                                                        <td><code><?php echo sanitizeOutput($stream['source_url'] ?? '-'); ?></code></td>
                                                        <td><code><?php echo sanitizeOutput($stream['relay_url'] ?? '-'); ?></code></td>
                                                        <td class="has-text-centered"><?php echo sanitizeOutput((string)($stream['consumer_count'] ?? 0)); ?></td>
                                                        <td><small><?php echo sanitizeOutput(renderUsageBreakdown($stream['usage'] ?? [], 'today')); ?></small></td>
                                                        <td><small><?php echo sanitizeOutput(renderUsageBreakdown($stream['usage'] ?? [], 'month_30d')); ?></small></td>
                                                        <td class="has-text-centered"><?php echo !empty($stream['online']) ? 'Live source connected' : 'Alias saved only'; ?></td>
                                                        <td>
                                                            <div class="ppp-table-actions">
                                                                <button class="button is-primary is-light is-small" type="button" data-cctv-action="preview-stream" data-stream-name="<?php echo sanitizeOutput((string)($stream['name'] ?? '')); ?>">
                                                                    <i class="bi bi-play-circle"></i>
                                                                </button>
                                                                <a class="button is-info is-light is-small" href="<?php echo sanitizeOutput($watchUrl); ?>" target="_blank" rel="noopener noreferrer">
                                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                                </a>
                                                                <button class="button is-warning is-light is-small" type="button" data-cctv-action="edit-stream" data-stream-name="<?php echo sanitizeOutput((string)($stream['name'] ?? '')); ?>">
                                                                    <i class="bi bi-pencil"></i>
                                                                </button>
                                                                <button class="button is-danger is-light is-small" type="button" data-cctv-action="delete-stream" data-stream-name="<?php echo sanitizeOutput((string)($stream['name'] ?? '')); ?>">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>

                                <section class="admin-tab-panel" id="cctv-tab-youtube" data-cctv-panel="youtube" role="tabpanel" aria-labelledby="cctv-tab-youtube-link" hidden>
                                    <div class="mb-4">
                                        <h6 class="title is-6 mb-1">YouTube Restream</h6>
                                        <p class="is-size-7 has-text-grey-light mb-0">Create a separate publish alias that pulls from one source alias and pushes to YouTube Live.</p>
                                    </div>
                                    <form id="youtubeRestreamForm">
                                <div class="field">
                                    <label for="cctvYoutubeSource" class="label admin-label">Source Alias</label>
                                    <div class="control">
                                        <div class="select is-fullwidth">
                                            <select id="cctvYoutubeSource" name="source_name" required>
                                                <option value="">Select a source alias</option>
                                                <?php foreach ($source_streams as $stream): ?>
                                                    <option value="<?php echo sanitizeOutput($stream['name'] ?? ''); ?>"><?php echo sanitizeOutput($stream['name'] ?? ''); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="cctvYoutubeAlias" class="label admin-label">YouTube Publish Alias</label>
                                    <div class="control">
                                        <input type="text" class="input admin-input" id="cctvYoutubeAlias" name="alias" required maxlength="100" placeholder="ezviz-h6c-youtube">
                                    </div>
                                    <p class="help has-text-grey-light">Use a different alias from the source. This alias becomes the dedicated transcoded pipeline for YouTube output.</p>
                                </div>
                                <div class="field">
                                    <label for="cctvYoutubeIngestUrl" class="label admin-label">YouTube Ingest URL</label>
                                    <div class="control">
                                        <input type="text" class="input admin-input" id="cctvYoutubeIngestUrl" name="ingest_url" value="rtmp://a.rtmp.youtube.com/live2" required>
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="cctvYoutubeStreamKey" class="label admin-label">YouTube Stream Key</label>
                                    <div class="control">
                                        <input type="password" class="input admin-input" id="cctvYoutubeStreamKey" name="stream_key" required autocomplete="off" placeholder="xxxx-xxxx-xxxx-xxxx-xxxx">
                                    </div>
                                    <p class="help has-text-grey-light">MikReMan will generate a default <code>ffmpeg</code> profile for YouTube. Use the advanced field below when a camera needs a custom expression.</p>
                                </div>
                                <div class="field">
                                    <label for="cctvYoutubeProfile" class="label admin-label">YouTube Source Profile</label>
                                    <div class="control">
                                        <div class="select is-fullwidth">
                                            <select id="cctvYoutubeProfile" name="source_profile">
                                                <option value="cloudsave-medium">Cloudsave Sedang</option>
                                                <option value="cloudsave-low">Cloudsave Hemat</option>
                                                <option value="relay-copy">Relay alias copy video + AAC audio</option>
                                                <option value="relay-transcode">Relay alias transcode to H264 + AAC</option>
                                                <option value="default">Default tuned profile</option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="help has-text-grey-light"><code>Cloudsave Hemat</code> is the best starting point for 24/7 archive uploads. For unstable cameras like EZVIZ, try <code>Relay alias copy video + AAC audio</code> first when the RTSP relay already plays fine in VLC.</p>
                                </div>
                                <div class="field">
                                    <label for="cctvYoutubeSourceExpression" class="label admin-label">Advanced YouTube Source Expression</label>
                                    <div class="control">
                                        <textarea class="textarea admin-input" id="cctvYoutubeSourceExpression" name="source_expression" rows="4" placeholder="Optional. Example: ffmpeg:bocor-ezviz#video=copy#audio=aac"></textarea>
                                    </div>
                                    <p class="help has-text-grey-light">Optional. Leave empty to use the default YouTube profile. Fill this when a camera works better with a direct RTSP or custom <code>ffmpeg:</code> expression.</p>
                                </div>
                                <div class="buttons admin-button-group">
                                    <button type="submit" class="button is-danger admin-action-button">
                                        <i class="bi bi-broadcast-pin"></i>
                                        <span>Save YouTube Restream</span>
                                    </button>
                                </div>
                                    </form>

                                    <hr class="admin-divider">

                                    <div>
                                        <h6 class="title is-6 mb-3">Active YouTube Publish Targets</h6>
                                        <div id="cctvYoutubeRestreamList">
                                            <?php if (empty($youtube_restreams)): ?>
                                                <div class="app-empty-state">
                                                    <span class="icon"><i class="bi bi-youtube has-text-grey-light"></i></span>
                                                    <p>No YouTube publish targets configured yet.</p>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($youtube_restreams as $restream): ?>
                                                    <div class="notification is-light mb-3">
                                                        <div class="is-flex is-justify-content-space-between is-align-items-center">
                                                            <strong><?php echo sanitizeOutput($restream['alias'] ?? ''); ?></strong>
                                                            <span class="tag <?php echo !empty($restream['enabled']) ? 'is-success is-light' : 'is-warning is-light'; ?>">
                                                                <?php echo !empty($restream['enabled']) ? 'Active' : 'Paused'; ?>
                                                            </span>
                                                        </div>
                                                        <div><small class="has-text-grey">Source Alias: <?php echo sanitizeOutput($restream['source_name'] ?? '-'); ?></small></div>
                                                        <div><small class="has-text-grey">Publish Target: <?php echo sanitizeOutput(maskSecretTail((string)($restream['destination'] ?? '-'))); ?></small></div>
                                                        <div class="buttons mt-3">
                                                            <?php if (!empty($restream['enabled'])): ?>
                                                                <button class="button is-warning is-light is-small" type="button" data-cctv-action="pause-youtube" data-youtube-alias="<?php echo sanitizeOutput((string)($restream['alias'] ?? '')); ?>">
                                                                    <i class="bi bi-pause-circle"></i>
                                                                    <span>Pause</span>
                                                                </button>
                                                            <?php else: ?>
                                                                <button class="button is-success is-light is-small" type="button" data-cctv-action="resume-youtube" data-youtube-alias="<?php echo sanitizeOutput((string)($restream['alias'] ?? '')); ?>">
                                                                    <i class="bi bi-play-circle"></i>
                                                                    <span>Resume</span>
                                                                </button>
                                                            <?php endif; ?>
                                                            <button class="button is-danger is-light is-small" type="button" data-cctv-action="delete-youtube" data-youtube-alias="<?php echo sanitizeOutput((string)($restream['alias'] ?? '')); ?>">
                                                                <i class="bi bi-trash"></i>
                                                                <span>Remove</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>

                                <section class="admin-tab-panel" id="cctv-tab-mosaic" data-cctv-panel="mosaic" role="tabpanel" aria-labelledby="cctv-tab-mosaic-link" hidden>
                                    <div class="mb-4">
                                        <h6 class="title is-6 mb-1">YouTube Mosaic</h6>
                                        <p class="is-size-7 has-text-grey-light mb-0">Gabungkan 2 atau 4 source alias menjadi satu output YouTube untuk mode cloudsave yang lebih hemat.</p>
                                    </div>

                                    <form id="mosaicRestreamForm">
                                        <div class="columns is-multiline is-variable is-4">
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="cctvMosaicAlias" class="label admin-label">Mosaic Alias</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="cctvMosaicAlias" name="alias" required maxlength="100" placeholder="cloudsave-wall">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="cctvMosaicLayout" class="label admin-label">Layout</label>
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select id="cctvMosaicLayout" name="layout">
                                                                <option value="2">2 Panel</option>
                                                                <option value="4" selected>4 Panel</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-12-tablet is-4-desktop">
                                                <div class="field">
                                                    <label for="cctvMosaicAudioMode" class="label admin-label">Audio</label>
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select id="cctvMosaicAudioMode" name="audio_mode">
                                                                <option value="silent" selected>Silent AAC</option>
                                                                <option value="panel1">Ambil dari Panel 1</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="columns is-multiline is-variable is-4">
                                            <div class="column is-12-mobile is-6-tablet">
                                                <div class="field">
                                                    <label for="cctvMosaicSource1" class="label admin-label">Panel 1 Source</label>
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select id="cctvMosaicSource1" name="source_1" required>
                                                                <option value="">Select source alias</option>
                                                                <?php foreach ($source_streams as $stream): ?>
                                                                    <option value="<?php echo sanitizeOutput($stream['name'] ?? ''); ?>"><?php echo sanitizeOutput($stream['name'] ?? ''); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet">
                                                <div class="field">
                                                    <label for="cctvMosaicSource2" class="label admin-label">Panel 2 Source</label>
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select id="cctvMosaicSource2" name="source_2" required>
                                                                <option value="">Select source alias</option>
                                                                <?php foreach ($source_streams as $stream): ?>
                                                                    <option value="<?php echo sanitizeOutput($stream['name'] ?? ''); ?>"><?php echo sanitizeOutput($stream['name'] ?? ''); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet cctv-mosaic-extra-source" data-mosaic-source-index="3">
                                                <div class="field">
                                                    <label for="cctvMosaicSource3" class="label admin-label">Panel 3 Source</label>
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select id="cctvMosaicSource3" name="source_3">
                                                                <option value="">Select source alias</option>
                                                                <?php foreach ($source_streams as $stream): ?>
                                                                    <option value="<?php echo sanitizeOutput($stream['name'] ?? ''); ?>"><?php echo sanitizeOutput($stream['name'] ?? ''); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet cctv-mosaic-extra-source" data-mosaic-source-index="4">
                                                <div class="field">
                                                    <label for="cctvMosaicSource4" class="label admin-label">Panel 4 Source</label>
                                                    <div class="control">
                                                        <div class="select is-fullwidth">
                                                            <select id="cctvMosaicSource4" name="source_4">
                                                                <option value="">Select source alias</option>
                                                                <?php foreach ($source_streams as $stream): ?>
                                                                    <option value="<?php echo sanitizeOutput($stream['name'] ?? ''); ?>"><?php echo sanitizeOutput($stream['name'] ?? ''); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="columns is-multiline is-variable is-4">
                                            <div class="column is-12-mobile is-6-tablet">
                                                <div class="field">
                                                    <label for="cctvMosaicIngestUrl" class="label admin-label">YouTube Ingest URL</label>
                                                    <div class="control">
                                                        <input type="text" class="input admin-input" id="cctvMosaicIngestUrl" name="ingest_url" value="rtmp://a.rtmp.youtube.com/live2" required>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="column is-12-mobile is-6-tablet">
                                                <div class="field">
                                                    <label for="cctvMosaicStreamKey" class="label admin-label">YouTube Stream Key</label>
                                                    <div class="control">
                                                        <input type="password" class="input admin-input" id="cctvMosaicStreamKey" name="stream_key" required autocomplete="off" placeholder="xxxx-xxxx-xxxx-xxxx-xxxx">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="notification is-info is-light mb-4">
                                            Layout mosaic ini sekarang dioptimalkan untuk cloudsave 360p: canvas <code>640x360</code>, bitrate sekitar <code>750-800 kbps</code>, pacing realtime, dan default audio <code>Silent AAC</code>. Untuk VPS kecil, tetap mulai dari substream dan layout 2 panel dulu.
                                        </div>

                                        <div class="buttons admin-button-group">
                                            <button type="submit" class="button is-link admin-action-button">
                                                <i class="bi bi-broadcast"></i>
                                                <span>Save Mosaic Output</span>
                                            </button>
                                        </div>
                                    </form>

                                    <hr class="admin-divider">

                                    <div>
                                        <h6 class="title is-6 mb-3">Active Mosaic Outputs</h6>
                                        <div id="cctvMosaicRestreamList">
                                            <?php if (empty($mosaic_restreams)): ?>
                                                <div class="app-empty-state">
                                                    <span class="icon"><i class="bi bi-layout-split has-text-grey-light"></i></span>
                                                    <p>No mosaic YouTube outputs configured yet.</p>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($mosaic_restreams as $restream): ?>
                                                    <div class="notification is-light mb-3">
                                                        <div class="is-flex is-justify-content-space-between is-align-items-center">
                                                            <strong><?php echo sanitizeOutput($restream['alias'] ?? ''); ?></strong>
                                                            <span class="tag <?php echo !empty($restream['enabled']) ? 'is-success is-light' : 'is-warning is-light'; ?>">
                                                                <?php echo !empty($restream['enabled']) ? 'Active' : 'Paused'; ?>
                                                            </span>
                                                        </div>
                                                        <div><small class="has-text-grey">Layout: <?php echo sanitizeOutput((string)($restream['layout'] ?? 0)); ?> Panel</small></div>
                                                        <div><small class="has-text-grey">Sources: <?php echo sanitizeOutput(implode(', ', $restream['sources'] ?? [])); ?></small></div>
                                                        <div><small class="has-text-grey">Audio: <?php echo sanitizeOutput(($restream['audio_mode'] ?? 'panel1') === 'silent' ? 'Silent AAC' : 'Panel 1'); ?></small></div>
                                                        <div><small class="has-text-grey">Publish Target: <?php echo sanitizeOutput(maskSecretTail((string)($restream['destination'] ?? '-'))); ?></small></div>
                                                        <div class="buttons mt-3">
                                                            <?php if (!empty($restream['enabled'])): ?>
                                                                <button class="button is-warning is-light is-small" type="button" data-cctv-action="pause-mosaic" data-mosaic-alias="<?php echo sanitizeOutput((string)($restream['alias'] ?? '')); ?>">
                                                                    <i class="bi bi-pause-circle"></i>
                                                                    <span>Pause</span>
                                                                </button>
                                                            <?php else: ?>
                                                                <button class="button is-success is-light is-small" type="button" data-cctv-action="resume-mosaic" data-mosaic-alias="<?php echo sanitizeOutput((string)($restream['alias'] ?? '')); ?>">
                                                                    <i class="bi bi-play-circle"></i>
                                                                    <span>Resume</span>
                                                                </button>
                                                            <?php endif; ?>
                                                            <button class="button is-danger is-light is-small" type="button" data-cctv-action="delete-mosaic" data-mosaic-alias="<?php echo sanitizeOutput((string)($restream['alias'] ?? '')); ?>">
                                                                <i class="bi bi-trash"></i>
                                                                <span>Remove</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>

                                <section class="admin-tab-panel" id="cctv-tab-monitor" data-cctv-panel="monitor" role="tabpanel" aria-labelledby="cctv-tab-monitor-link" hidden>
                                    <div class="mb-4">
                                        <h6 class="title is-6 mb-1">Multi Monitor</h6>
                                        <p class="is-size-7 has-text-grey-light mb-0">Pantulkan satu source alias ke beberapa panel sekaligus untuk mode monitor wall CCTV.</p>
                                    </div>

                                    <div class="columns is-multiline is-variable is-4 mb-2">
                                        <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                            <div class="field mb-0">
                                                <label for="cctvMonitorSource" class="label admin-label">Source Alias</label>
                                                <div class="control">
                                                    <div class="select is-fullwidth">
                                                        <select id="cctvMonitorSource" name="monitor_source">
                                                            <option value="">Select a source alias</option>
                                                            <?php foreach ($source_streams as $stream): ?>
                                                                <option value="<?php echo sanitizeOutput($stream['name'] ?? ''); ?>"><?php echo sanitizeOutput($stream['name'] ?? ''); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                            <div class="field mb-0">
                                                <label for="cctvMonitorLayout" class="label admin-label">Layout</label>
                                                <div class="control">
                                                    <div class="select is-fullwidth">
                                                        <select id="cctvMonitorLayout" name="monitor_layout">
                                                            <option value="1">1 Monitor</option>
                                                            <option value="2">2 Monitor</option>
                                                            <option value="4" selected>4 Monitor</option>
                                                            <option value="6">6 Monitor</option>
                                                            <option value="9">9 Monitor</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12-mobile is-12-tablet is-4-desktop">
                                            <div class="field mb-0">
                                                <label class="label admin-label">Actions</label>
                                                <div class="buttons admin-button-group mb-0">
                                                    <button class="button is-primary admin-action-button" type="button" id="cctvMonitorRefreshButton">
                                                        <i class="bi bi-arrow-clockwise"></i>
                                                        <span>Refresh Wall</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="notification is-warning is-light mb-4">
                                        Satu stream yang dipantulkan ke banyak panel akan membuka beberapa koneksi preview sekaligus. Untuk monitor wall panjang, mulai dari 4 panel dulu agar beban browser dan relay tetap ringan.
                                    </div>

                                    <div class="is-flex is-justify-content-space-between is-align-items-center mb-4">
                                        <div>
                                            <h6 class="title is-6 mb-1">Monitor Wall</h6>
                                            <p class="is-size-7 has-text-grey-light mb-0" id="cctvMonitorSummary">Select one source alias to start the monitor wall.</p>
                                        </div>
                                    </div>

                                    <div id="cctvMultiMonitorGrid" class="cctv-monitor-grid" data-layout="4"></div>
                                </section>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="column is-12">
                    <div class="card enhanced-card admin-card">
                        <div class="card-header admin-card-header">
                            <div class="card-header-content">
                                <div class="card-icon">
                                    <span class="icon"><i class="bi bi-filetype-yml" aria-hidden="true"></i></span>
                                </div>
                                <div class="card-title-group">
                                    <h5 class="card-title">go2rtc Config</h5>
                                    <small class="card-subtitle">Current live YAML returned by the service</small>
                                </div>
                            </div>
                            <div class="card-header-icon">
                                <button type="button" class="button is-small is-light" id="cctvToggleConfigButton" data-cctv-toggle-config="false" aria-expanded="false" aria-controls="cctvConfigPanel">
                                    <span class="icon"><i class="bi bi-eye"></i></span>
                                    <span>Show</span>
                                </button>
                            </div>
                        </div>
                        <div class="card-body admin-card-body" id="cctvConfigPanel" hidden>
                            <div class="order-summary-box">
                                <pre id="cctv-config-text"><?php echo sanitizeOutput($overview['config_text'] ?? 'Configuration is unavailable.'); ?></pre>
                            </div>
                            <div class="notification is-warning is-light mt-4 <?php echo !empty($overview['online']) ? 'is-hidden' : ''; ?>" id="cctv-warning">
                                <span class="icon"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></span>
                                <span>go2rtc did not respond to the probe. Check the configured API endpoint, Docker container, and relay ports.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="modal" id="addCctvStreamModal" role="dialog" aria-modal="true" aria-labelledby="addCctvStreamModalTitle">
        <div class="modal-background" data-close-modal="addCctvStreamModal"></div>
        <form class="modal-card app-modal-card is-modal-medium" id="addCctvStreamForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="addCctvStreamModalTitle">
                    <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                    <span>Add Source Alias</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="addCctvStreamModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <div class="field">
                    <label for="addCctvStreamName" class="label admin-label">Source Alias</label>
                    <div class="control">
                        <input type="text" class="input admin-input" id="addCctvStreamName" name="name" required maxlength="100" placeholder="ezviz-h6c-main">
                    </div>
                    <p class="help has-text-grey-light">Use a simple alias with letters, numbers, or dashes. This becomes the relay path after <code>rtsp://host:8554/</code>.</p>
                </div>
                <div class="field">
                    <label for="addCctvStreamSource" class="label admin-label">Upstream Camera URL</label>
                    <div class="control">
                        <textarea class="textarea admin-input" id="addCctvStreamSource" name="src" rows="4" required placeholder="rtsp://user:pass@43.129.33.160:18538/Streaming/Channels/101"></textarea>
                    </div>
                    <p class="help has-text-grey-light">Paste the original RTSP or other supported source URL that go2rtc should pull from.</p>
                </div>
                <div class="message is-dark is-small">
                    <div class="message-header">
                        <p>Advanced</p>
                    </div>
                    <div class="message-body">
                        <div class="field">
                            <label for="addCctvStreamSourceAdvanced" class="label admin-label">Advanced go2rtc Source Expression</label>
                            <div class="control">
                                <textarea class="textarea admin-input" id="addCctvStreamSourceAdvanced" name="src_advanced" rows="4" placeholder="ffmpeg:cam-main#video=h264#audio=aac"></textarea>
                            </div>
                            <p class="help has-text-grey-light">Optional. If this field is filled, it overrides the basic URL above. Use it for full go2rtc expressions such as <code>ffmpeg:</code>, <code>onvif:</code>, or RTSP URLs with <code>#...</code> parameters.</p>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="addCctvStreamModal">Cancel</button>
                <button type="submit" class="button is-primary admin-action-button">
                    <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                    <span>Save Source Alias</span>
                </button>
            </footer>
        </form>
    </div>

    <div class="modal" id="editCctvStreamModal" role="dialog" aria-modal="true" aria-labelledby="editCctvStreamModalTitle">
        <div class="modal-background" data-close-modal="editCctvStreamModal"></div>
        <form class="modal-card app-modal-card is-modal-medium" id="editCctvStreamForm">
            <input type="hidden" id="editCctvOldName" name="old_name">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="editCctvStreamModalTitle">
                    <span class="icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
                    <span>Edit Source Alias</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="editCctvStreamModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <div class="field">
                    <label for="editCctvStreamName" class="label admin-label">Source Alias</label>
                    <div class="control">
                        <input type="text" class="input admin-input" id="editCctvStreamName" name="name" required maxlength="100">
                    </div>
                </div>
                <div class="field">
                    <label for="editCctvStreamSource" class="label admin-label">Upstream Camera URL</label>
                    <div class="control">
                        <textarea class="textarea admin-input" id="editCctvStreamSource" name="src" rows="4" required></textarea>
                    </div>
                </div>
                <div class="message is-dark is-small">
                    <div class="message-header">
                        <p>Advanced</p>
                    </div>
                    <div class="message-body">
                        <div class="field">
                            <label for="editCctvStreamSourceAdvanced" class="label admin-label">Advanced go2rtc Source Expression</label>
                            <div class="control">
                                <textarea class="textarea admin-input" id="editCctvStreamSourceAdvanced" name="src_advanced" rows="4" placeholder="ffmpeg:cam-main#video=h264#audio=aac"></textarea>
                            </div>
                            <p class="help has-text-grey-light">Optional. If this field is filled, it overrides the basic URL above. Use it when the source needs custom go2rtc parameters.</p>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="editCctvStreamModal">Cancel</button>
                <button type="submit" class="button is-warning admin-action-button">
                    <span class="icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                    <span>Update Source Alias</span>
                </button>
            </footer>
        </form>
    </div>

    <div class="modal" id="previewCctvStreamModal" role="dialog" aria-modal="true" aria-labelledby="previewCctvStreamModalTitle">
        <div class="modal-background" data-close-modal="previewCctvStreamModal"></div>
        <div class="modal-card app-modal-card is-modal-large">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="previewCctvStreamModalTitle">
                    <span class="icon"><i class="bi bi-play-circle" aria-hidden="true"></i></span>
                    <span>Preview Stream</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="previewCctvStreamModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <div class="notification is-info is-light mb-4">
                    Preview ini memakai proxy MJPEG dari MikReMan, jadi stream bisa dilihat tanpa membuka admin/API <code>go2rtc</code> ke publik.
                </div>
                <div class="field mb-4">
                    <label class="label admin-label">Relay URL</label>
                    <div class="control">
                        <code id="previewCctvStreamRelayUrl">-</code>
                    </div>
                </div>
                <div class="has-text-centered">
                    <img
                        id="previewCctvStreamImage"
                        alt="CCTV preview stream"
                        style="max-width: 100%; width: 100%; border-radius: 1rem; background: rgba(10, 10, 10, 0.65); min-height: 320px; object-fit: contain;"
                    >
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="previewCctvStreamModal">Close</button>
            </footer>
        </div>
    </div>

    <script>
        window.CCTV_APP_CONFIG = {
            csrfToken: <?php echo sanitizeOutput($csrf_token, 'js'); ?>,
            sessionTimeoutMs: <?php echo SESSION_TIMEOUT * 1000; ?>,
            overview: <?php echo json_encode($overview, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        };
    </script>
    <script src="../assets/js/cctv.js"></script>
</body>
</html>
