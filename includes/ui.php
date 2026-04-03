<?php

function escapeMetaContent(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function getPublicRequestScheme(): string
{
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '') {
        return strtolower(strtok($forwardedProto, ',')) === 'https' ? 'https' : 'http';
    }

    $forwardedSsl = trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    if ($forwardedSsl !== '') {
        return strtolower($forwardedSsl) === 'on' ? 'https' : 'http';
    }

    $cfVisitor = trim((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''));
    if ($cfVisitor !== '') {
        $decodedVisitor = json_decode($cfVisitor, true);
        if (is_array($decodedVisitor) && strtolower((string)($decodedVisitor['scheme'] ?? '')) === 'https') {
            return 'https';
        }
    }

    $https = $_SERVER['HTTPS'] ?? '';
    if ($https && strtolower((string)$https) !== 'off') {
        return 'https';
    }

    $requestScheme = trim((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($requestScheme !== '') {
        return strtolower($requestScheme) === 'https' ? 'https' : 'http';
    }

    $forwardedPort = trim((string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''));
    if ($forwardedPort === '443') {
        return 'https';
    }

    $serverPort = (string)($_SERVER['SERVER_PORT'] ?? '');
    if ($serverPort === '443') {
        return 'https';
    }

    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return 'https';
    }

    return 'http';
}

function getPublicRequestHost(): string
{
    $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($forwardedHost !== '') {
        return trim(strtok($forwardedHost, ','));
    }

    $httpHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($httpHost !== '') {
        return $httpHost;
    }

    return trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
}

function buildAbsolutePublicUrl(string $path = ''): string
{
    if ($path !== '' && preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $base = getPublicRequestScheme() . '://' . getPublicRequestHost();
    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function renderPublicSeoMeta(array $options = []): void
{
    $title = trim((string)($options['title'] ?? ''));
    $description = trim((string)($options['description'] ?? ''));
    $type = trim((string)($options['type'] ?? 'website'));
    $path = trim((string)($options['path'] ?? ''));
    $canonical = trim((string)($options['canonical'] ?? buildAbsolutePublicUrl($path)));
    $image = trim((string)($options['image'] ?? ''));
    $imageUrl = $image !== '' ? buildAbsolutePublicUrl($image) : '';
    $siteName = trim((string)($options['site_name'] ?? 'MikReMan'));
    $robots = trim((string)($options['robots'] ?? 'index,follow'));
    $twitterCard = trim((string)($options['twitter_card'] ?? 'summary_large_image'));
    $imageAlt = trim((string)($options['image_alt'] ?? $title));
    $themeColor = trim((string)($options['theme_color'] ?? '#0f172a'));
    $imageWidth = trim((string)($options['image_width'] ?? '1200'));
    $imageHeight = trim((string)($options['image_height'] ?? '630'));

    if ($description !== '') {
        echo '<meta name="description" content="' . escapeMetaContent($description) . '">' . PHP_EOL;
    }

    echo '<meta name="robots" content="' . escapeMetaContent($robots) . '">' . PHP_EOL;
    echo '<link rel="canonical" href="' . escapeMetaContent($canonical) . '">' . PHP_EOL;
    echo '<meta name="theme-color" content="' . escapeMetaContent($themeColor) . '">' . PHP_EOL;

    if ($title !== '') {
        echo '<meta property="og:title" content="' . escapeMetaContent($title) . '">' . PHP_EOL;
        echo '<meta name="twitter:title" content="' . escapeMetaContent($title) . '">' . PHP_EOL;
    }

    if ($description !== '') {
        echo '<meta property="og:description" content="' . escapeMetaContent($description) . '">' . PHP_EOL;
        echo '<meta name="twitter:description" content="' . escapeMetaContent($description) . '">' . PHP_EOL;
    }

    echo '<meta property="og:type" content="' . escapeMetaContent($type) . '">' . PHP_EOL;
    echo '<meta property="og:url" content="' . escapeMetaContent($canonical) . '">' . PHP_EOL;
    echo '<meta property="og:site_name" content="' . escapeMetaContent($siteName) . '">' . PHP_EOL;
    echo '<meta name="twitter:card" content="' . escapeMetaContent($twitterCard) . '">' . PHP_EOL;

    if ($imageUrl !== '') {
        echo '<meta property="og:image" content="' . escapeMetaContent($imageUrl) . '">' . PHP_EOL;
        echo '<meta property="og:image:secure_url" content="' . escapeMetaContent($imageUrl) . '">' . PHP_EOL;
        echo '<meta property="og:image:width" content="' . escapeMetaContent($imageWidth) . '">' . PHP_EOL;
        echo '<meta property="og:image:height" content="' . escapeMetaContent($imageHeight) . '">' . PHP_EOL;
        echo '<meta property="og:image:alt" content="' . escapeMetaContent($imageAlt) . '">' . PHP_EOL;
        echo '<meta name="twitter:image" content="' . escapeMetaContent($imageUrl) . '">' . PHP_EOL;
        echo '<meta name="twitter:image:alt" content="' . escapeMetaContent($imageAlt) . '">' . PHP_EOL;
    }
}

function renderThemeBootScript(): void
{
    ?>
    <script>
        (() => {
            try {
                const theme = localStorage.getItem('mikreman-theme') === 'dark' ? 'dark' : 'light';
                document.documentElement.dataset.theme = theme;
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>
    <?php
}

function renderThemeScript(string $assetPath): void
{
    ?>
    <script src="<?php echo htmlspecialchars($assetPath, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <?php
}

function renderSweetAlertAssets(string $assetBase = '..'): void
{
    $normalizedBase = rtrim($assetBase, '/');
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="<?php echo htmlspecialchars($normalizedBase . '/assets/js/swal.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php
}

function renderAppNavbar(string $currentPage, string $brandHref = 'admin.php'): void
{
    $items = [
        'admin' => [
            'href' => 'admin.php',
            'icon' => 'bi bi-gear-fill',
            'label' => 'Configuration',
        ],
        'dashboard' => [
            'href' => 'dashboard.php',
            'icon' => 'bi bi-speedometer2',
            'label' => 'Dashboard',
        ],
        'ppp' => [
            'href' => 'ppp.php',
            'icon' => 'bi bi-people-fill',
            'label' => 'PPP Users',
        ],
        'wireguard' => [
            'href' => 'wireguard.php',
            'icon' => 'bi bi-hurricane',
            'label' => 'WireGuard',
        ],
        'monitoring' => [
            'href' => 'monitoring.php',
            'icon' => 'bi bi-activity',
            'label' => 'Monitoring',
        ],
        'trials' => [
            'href' => 'trials.php',
            'icon' => 'bi bi-graph-up-arrow',
            'label' => 'Trial Stats',
        ],
    ];
    ?>
    <nav class="navbar app-topbar" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <a href="<?php echo htmlspecialchars($brandHref, ENT_QUOTES, 'UTF-8'); ?>" class="navbar-item topbar-brand">
                <span class="icon brand-icon">
                    <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
                </span>
                <span class="is-flex is-flex-direction-column">
                    <span class="brand-text">VPN</span>
                    <small class="brand-subtitle is-hidden-mobile">Remote</small>
                </span>
            </a>
            <a role="button" class="navbar-burger topbar-burger" id="topNavbarBurger" aria-label="menu" aria-expanded="false" aria-controls="topNavbarMenu">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>

        <div id="topNavbarMenu" class="navbar-menu topbar-menu">
            <div class="navbar-start">
                <?php foreach ($items as $key => $item): ?>
                    <a class="navbar-item topbar-link <?php echo $currentPage === $key ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="icon is-small">
                            <i class="<?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                        </span>
                        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="navbar-end">
                <div class="navbar-item">
                    <button class="button is-light theme-toggle-button" type="button" data-theme-toggle aria-label="Switch to dark theme">
                        <span class="icon"><i class="bi bi-moon-stars-fill theme-toggle-icon"></i></span>
                        <span class="theme-toggle-label">Dark</span>
                    </button>
                </div>
                <div class="navbar-item">
                    <a class="button is-danger is-light admin-action-button" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>
    <?php
}

function renderPageHeader(string $iconClass, string $title, string $subtitle, string $actionsHtml = ''): void
{
    ?>
    <div class="page-header">
        <div class="header-content is-flex is-justify-content-space-between is-align-items-center is-flex-direction-column-mobile is-align-items-flex-start-mobile">
            <div class="header-main is-flex is-align-items-center is-flex-direction-column-mobile is-align-items-flex-start-mobile">
                <div class="header-icon">
                    <span class="icon is-medium">
                        <i class="<?php echo htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                    </span>
                </div>
                <div class="header-text">
                    <h1 class="page-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="page-subtitle is-hidden-mobile"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <?php if ($actionsHtml !== ''): ?>
                <div class="header-actions is-flex is-flex-wrap-wrap is-justify-content-flex-end is-justify-content-flex-start-mobile">
                    <?php echo $actionsHtml; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
