<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/trial_stats.php';
require_once '../includes/trial_stats_ui.php';
require_once '../includes/ui.php';

define('SESSION_TIMEOUT', 3600);

checkSession();

$current_page = 'trials';
$page_title = 'Trial Statistics';
$page_subtitle = 'Public trial activity from SQLite mirror storage';

$trial_stats_summary = getTrialStatsSummary();
$trial_stats_records = getRecentTrialStatsRecords(100);

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

        <main class="main-content topbar-main-content">
            <?php renderPageHeader('bi bi-graph-up-arrow', $page_title, $page_subtitle); ?>

            <div class="card enhanced-card admin-card">
                <div class="card-header admin-card-header">
                    <div class="card-header-content">
                        <div class="card-icon">
                            <span class="icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">Trial Statistics</h5>
                            <small class="card-subtitle">Public trial activity mirrored from local runtime storage</small>
                        </div>
                    </div>
                </div>
                <div class="card-body admin-card-body">
                    <?php renderTrialStatsSummaryCards($trial_stats_summary); ?>

                    <div class="profile-section">
                        <div class="section-header">
                            <h6 class="section-title">Recent Trial Users</h6>
                            <p class="help has-text-grey-light">Latest mirrored trial records. This page is read-only and does not perform router actions.</p>
                        </div>
                        <?php renderTrialStatsTable($trial_stats_records); ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
