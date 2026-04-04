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
$streams = is_array($overview['streams'] ?? null) ? $overview['streams'] : [];
$youtube_restreams = is_array($overview['youtube_restreams'] ?? null) ? $overview['youtube_restreams'] : [];
$youtube_aliases = array_values(array_filter(array_map(static function ($restream) {
    return trim((string)($restream['alias'] ?? ''));
}, $youtube_restreams)));
$source_streams = array_values(array_filter($streams, static function ($stream) use ($youtube_aliases) {
    $name = trim((string)($stream['name'] ?? ''));
    return $name !== '' && !in_array($name, $youtube_aliases, true);
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
                                                    <th scope="col" class="has-text-centered">State</th>
                                                    <th scope="col" width="200">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody id="cctvStreamsTableBody">
                                            <?php if (empty($source_streams)): ?>
                                                <tr>
                                                    <td colspan="6" class="has-text-centered">
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
                                                        <td class="has-text-centered"><?php echo !empty($stream['online']) ? 'Live source connected' : 'Alias saved only'; ?></td>
                                                        <td>
                                                            <div class="ppp-table-actions">
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
                                                        <div><strong><?php echo sanitizeOutput($restream['alias'] ?? ''); ?></strong></div>
                                                        <div><small class="has-text-grey">Source Alias: <?php echo sanitizeOutput($restream['source_name'] ?? '-'); ?></small></div>
                                                        <div><small class="has-text-grey">Publish Target: <?php echo sanitizeOutput(maskSecretTail((string)($restream['destination'] ?? '-'))); ?></small></div>
                                                        <div class="buttons mt-3">
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
                        </div>
                        <div class="card-body admin-card-body">
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
