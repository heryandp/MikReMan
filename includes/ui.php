<?php

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
        'monitoring' => [
            'href' => 'monitoring.php',
            'icon' => 'bi bi-activity',
            'label' => 'Monitoring',
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
