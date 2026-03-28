<?php
require_once 'includes/session.php';
startSecureSession();

require_once 'includes/config.php';
require_once 'includes/ui.php';
require_once 'includes/turnstile.php';

if (!isset($_SESSION['order_csrf_token'])) {
    $_SESSION['order_csrf_token'] = bin2hex(random_bytes(32));
}

$order_csrf_token = $_SESSION['order_csrf_token'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex, nofollow">
    <title>Free PPP Trial Order</title>
    <?php renderThemeBootScript(); ?>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderTurnstileAssets(); ?>
    <?php renderSweetAlertAssets('.'); ?>
    <link href="assets/css/style.css" rel="stylesheet">
    <?php renderThemeScript('assets/js/theme.js'); ?>
</head>
<body class="order-body">
    <div class="order-shell">
        <nav class="navbar order-navbar" role="navigation" aria-label="public order navigation">
            <div class="navbar-brand">
                <a href="order.php" class="navbar-item order-brand">
                    <span class="icon brand-icon">
                        <i class="bi bi-stars" aria-hidden="true"></i>
                    </span>
                    <span class="is-flex is-flex-direction-column">
                        <span class="brand-text">MikReMan</span>
                        <small class="brand-subtitle">Free PPP Trial</small>
                    </span>
                </a>
                <a role="button" class="navbar-burger topbar-burger" id="orderNavbarBurger" aria-label="menu" aria-expanded="false" aria-controls="orderNavbarMenu">
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                    <span aria-hidden="true"></span>
                </a>
            </div>
            <div id="orderNavbarMenu" class="navbar-menu">
                <div class="navbar-end">
                    <div class="navbar-item">
                        <button class="button is-light theme-toggle-button" type="button" data-theme-toggle aria-label="Switch to dark theme">
                            <span class="icon"><i class="bi bi-moon-stars-fill theme-toggle-icon"></i></span>
                            <span class="theme-toggle-label">Dark</span>
                        </button>
                    </div>
                    <div class="navbar-item">
                        <a class="button is-light" href="index.php">
                            <span class="icon"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></span>
                            <span>Admin Login</span>
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <main class="order-main">
            <section class="order-hero">
                <div class="columns is-variable is-6 is-vcentered">
                    <div class="column is-12-tablet is-7-desktop">
                        <div class="order-hero-copy">
                            <p class="order-kicker">Free PPP Trial</p>
                            <h1 class="order-title">Create a 7-day PPP trial account.</h1>
                            <p class="order-subtitle">
                                This page creates a public trial account automatically. The account expires after 7 days and only includes fixed mappings for Winbox, API, and HTTP.
                            </p>

                            <div class="notification is-light order-notice order-notice-inline">
                                <div class="order-simple-list">
                                    <div><strong>Duration:</strong> 7 days</div>
                                    <div><strong>Ports:</strong> Winbox 8291, API 8728, HTTP 80</div>
                                    <div><strong>Custom ports:</strong> not available on this page</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="column is-12-tablet is-5-desktop">
                        <div class="order-summary-card">
                            <div class="order-summary-head">
                                <div>
                                    <p class="order-kicker">Result</p>
                                    <h2 class="title is-4">Trial Account</h2>
                                </div>
                                <span class="tag is-link is-light" id="orderRequestCode">REQ-PENDING</span>
                            </div>
                            <div class="content">
                                <p class="order-summary-copy">After submit, this panel will show the generated username, password, expiry time, and public endpoints.</p>
                                <div class="order-summary-metrics">
                                    <div class="order-metric">
                                        <span class="order-metric-label">Service</span>
                                        <strong id="trialService">Not created</strong>
                                    </div>
                                    <div class="order-metric">
                                        <span class="order-metric-label">Validity</span>
                                        <strong id="trialValidity">7 days</strong>
                                    </div>
                                    <div class="order-metric">
                                        <span class="order-metric-label">Mappings</span>
                                        <strong id="trialMappings">3 fixed ports</strong>
                                    </div>
                                </div>
                                <div class="order-summary-box">
                                    <pre id="orderSummaryText">No trial account has been generated yet.</pre>
                                </div>
                                <div class="buttons">
                                    <button type="button" class="button is-link" id="copyOrderSummaryButton">
                                        <span class="icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                                        <span>Copy Trial Details</span>
                                    </button>
                                    <button type="button" class="button is-light" id="downloadOrderSummaryButton">
                                        <span class="icon"><i class="bi bi-download" aria-hidden="true"></i></span>
                                        <span>Download TXT</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="order-form-section">
                <div class="columns is-variable is-6">
                    <div class="column is-12-desktop">
                        <div class="card order-form-card">
                            <div class="card-content">
                                <div class="order-section-head">
                                    <div>
                                        <p class="order-kicker">Request Form</p>
                                        <h2 class="title is-4">Generate Trial</h2>
                                    </div>
                                    <p class="order-section-copy">Fill the form and submit once. The system will generate one trial account with fixed mappings only.</p>
                                </div>

                                <div class="notification is-warning is-light order-notice">
                                    <span class="icon"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></span>
                                    <span>Only fixed internal targets are available here: `8291`, `8728`, and `80`.</span>
                                </div>

                                <form id="orderForm" class="order-form" novalidate>
                                    <input type="hidden" id="orderCsrfToken" value="<?php echo htmlspecialchars($order_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                                    <div class="columns is-multiline is-variable is-4">
                                        <div class="column is-12-mobile is-6-tablet">
                                            <div class="field">
                                                <label class="label" for="orderFullName">Full Name</label>
                                                <div class="control has-icons-left">
                                                    <input class="input" id="orderFullName" name="full_name" type="text" placeholder="Jane Doe" required>
                                                    <span class="icon is-left"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12-mobile is-6-tablet">
                                            <div class="field">
                                                <label class="label" for="orderEmail">Email</label>
                                                <div class="control has-icons-left">
                                                    <input class="input" id="orderEmail" name="email" type="email" placeholder="trial@example.com" required>
                                                    <span class="icon is-left"><i class="bi bi-envelope-fill" aria-hidden="true"></i></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12-mobile is-6-tablet">
                                            <div class="field">
                                                <label class="label" for="orderService">VPN Service</label>
                                                <div class="control">
                                                    <div class="select is-fullwidth">
                                                        <select id="orderService" name="service" required>
                                                            <option value="l2tp">L2TP</option>
                                                            <option value="pptp">PPTP</option>
                                                            <option value="sstp">SSTP</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12-mobile is-6-tablet">
                                            <div class="field">
                                                <label class="label">Included Trial Mappings</label>
                                                <div class="order-fixed-port-list">
                                                    <span class="tag is-light order-fixed-port"><strong>Winbox</strong><span>8291</span></span>
                                                    <span class="tag is-light order-fixed-port"><strong>API</strong><span>8728</span></span>
                                                    <span class="tag is-light order-fixed-port"><strong>HTTP</strong><span>80</span></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12">
                                            <div class="field">
                                                <label class="label" for="orderNotes">Notes</label>
                                                <div class="control">
                                                    <textarea class="textarea" id="orderNotes" name="notes" rows="4" placeholder="Optional notes about your device, branch, test topology, or expected downstream forwarding."></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="column is-12">
                                            <label class="checkbox order-feature-option order-terms-option">
                                                <input type="checkbox" id="orderTerms" name="terms_accepted" required>
                                                <span>I understand this trial lasts 7 days and only includes Winbox, API, and HTTP mappings.</span>
                                            </label>
                                        </div>
                                        <div class="column is-12">
                                            <?php renderTurnstileWidget('order'); ?>
                                            <?php if (isTurnstileEnabledFor('order')): ?>
                                            <p class="help has-text-grey-light">If the security check stalls or expires, reload the widget or refresh the page once.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="buttons order-form-actions">
                                        <button type="submit" class="button is-link is-medium" id="generateTrialButton">
                                            <span class="icon"><i class="bi bi-stars" aria-hidden="true"></i></span>
                                            <span>Generate Free Trial</span>
                                        </button>
                                        <button type="button" class="button is-light is-medium" id="resetOrderButton">
                                            <span class="icon"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span>
                                            <span>Reset Form</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        window.ORDER_PAGE_CONFIG = {
            csrfToken: <?php echo json_encode($order_csrf_token); ?>,
            endpoint: <?php echo json_encode('api/order.php?action=create_trial'); ?>,
            downloadFilenamePrefix: <?php echo json_encode('mikreman-trial'); ?>,
            turnstileEnabled: <?php echo isTurnstileEnabledFor('order') ? 'true' : 'false'; ?>
        };
    </script>
    <script src="assets/js/order.js"></script>
</body>
</html>
