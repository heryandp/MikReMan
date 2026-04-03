<?php

function renderAdminTabsAndPanels(string $csrf_token): void
{
    ?>
    <div class="tabs is-toggle is-fullwidth admin-tabs" role="tablist" aria-label="Admin Sections">
        <ul>
            <li class="is-active" data-admin-tab="mikrotik" role="presentation">
                <a id="admin-tab-mikrotik-link" href="#admin-tab-mikrotik" role="tab" aria-controls="admin-tab-mikrotik" aria-selected="true">
                    <span class="icon is-small"><i class="bi bi-router" aria-hidden="true"></i></span>
                    <span>MikroTik</span>
                </a>
            </li>
            <li data-admin-tab="auth" role="presentation">
                <a id="admin-tab-auth-link" href="#admin-tab-auth" role="tab" aria-controls="admin-tab-auth" aria-selected="false">
                    <span class="icon is-small"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span>
                    <span>Login</span>
                </a>
            </li>
            <li data-admin-tab="telegram" role="presentation">
                <a id="admin-tab-telegram-link" href="#admin-tab-telegram" role="tab" aria-controls="admin-tab-telegram" aria-selected="false">
                    <span class="icon is-small"><i class="bi bi-telegram" aria-hidden="true"></i></span>
                    <span>Telegram</span>
                </a>
            </li>
            <li data-admin-tab="vpn" role="presentation">
                <a id="admin-tab-vpn-link" href="#admin-tab-vpn" role="tab" aria-controls="admin-tab-vpn" aria-selected="false">
                    <span class="icon is-small"><i class="bi bi-hdd-network-fill" aria-hidden="true"></i></span>
                    <span>VPN Services</span>
                </a>
            </li>
            <li data-admin-tab="wireguard" role="presentation">
                <a id="admin-tab-wireguard-link" href="#admin-tab-wireguard" role="tab" aria-controls="admin-tab-wireguard" aria-selected="false">
                    <span class="icon is-small"><i class="bi bi-hurricane" aria-hidden="true"></i></span>
                    <span>WireGuard</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="admin-tab-panels">
        <section class="admin-tab-panel is-active" id="admin-tab-mikrotik" data-admin-panel="mikrotik" role="tabpanel" aria-labelledby="admin-tab-mikrotik-link">
            <div class="card enhanced-card admin-card">
                <div class="card-header admin-card-header">
                    <div class="card-header-content">
                        <div class="card-icon">
                            <span class="icon"><i class="bi bi-router" aria-hidden="true"></i></span>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">MikroTik Configuration</h5>
                            <small class="card-subtitle">Router connection settings</small>
                        </div>
                    </div>
                </div>
                <div class="card-body admin-card-body">
                    <form id="mikrotik-form">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                        <div class="field">
                            <label for="mt_host" class="label admin-label">IP MikroTik</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="mt_host" name="host" placeholder="192.168.1.1" required autocomplete="off" maxlength="253">
                            </div>
                        </div>

                        <div class="columns is-variable is-4">
                            <div class="column">
                                <div class="field">
                                    <label for="mt_username" class="label admin-label">Username</label>
                                    <div class="control">
                                        <input type="text" class="input admin-input" id="mt_username" name="username" placeholder="admin" required autocomplete="username" maxlength="50">
                                    </div>
                                </div>
                            </div>
                            <div class="column">
                                <div class="field">
                                    <label for="mt_password" class="label admin-label">Password</label>
                                    <div class="field has-addons admin-field-addons">
                                        <div class="control is-expanded">
                                            <input type="password" class="input admin-input" id="mt_password" name="password" placeholder="Password" autocomplete="current-password" maxlength="255">
                                        </div>
                                        <div class="control">
                                            <button class="button is-dark is-outlined admin-addon-button" type="button" id="toggleMtPassword" onclick="toggleMikrotikPasswordVisibility()">
                                                <span class="icon"><i class="bi bi-eye"></i></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label for="mt_port" class="label admin-label">Port</label>
                            <div class="control">
                                <input type="number" class="input admin-input" id="mt_port" name="port" value="443" min="1" max="65535" autocomplete="off">
                            </div>
                        </div>

                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">Docker Published Ports</h6>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                    <div class="field">
                                        <label for="rest_https_port" class="label admin-label">REST HTTPS</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="rest_https_port" name="rest_https_port" min="1" max="65535" placeholder="7005">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                    <div class="field">
                                        <label for="rest_http_port" class="label admin-label">REST HTTP</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="rest_http_port" name="rest_http_port" min="1" max="65535" placeholder="7004">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                    <div class="field">
                                        <label for="winbox_port" class="label admin-label">Winbox</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="winbox_port" name="winbox_port" min="1" max="65535" placeholder="7000">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                    <div class="field">
                                        <label for="api_port" class="label admin-label">API</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="api_port" name="api_port" min="1" max="65535" placeholder="7001">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                    <div class="field">
                                        <label for="api_ssl_port" class="label admin-label">API SSL</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="api_ssl_port" name="api_ssl_port" min="1" max="65535" placeholder="7002">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-4-desktop">
                                    <div class="field">
                                        <label for="ssh_port" class="label admin-label">SSH</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="ssh_port" name="ssh_port" min="1" max="65535" placeholder="7003">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="l2tp_port" class="label admin-label">L2TP</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="l2tp_port" name="l2tp_port" min="1" max="65535" placeholder="1701">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="pptp_port" class="label admin-label">PPTP</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="pptp_port" name="pptp_port" min="1" max="65535" placeholder="1723">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="sstp_port" class="label admin-label">SSTP</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="sstp_port" name="sstp_port" min="1" max="65535" placeholder="443">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="ipsec_port" class="label admin-label">IPsec IKE</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="ipsec_port" name="ipsec_port" min="1" max="65535" placeholder="500">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="ipsec_nat_t_port" class="label admin-label">IPsec NAT-T</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="ipsec_nat_t_port" name="ipsec_nat_t_port" min="1" max="65535" placeholder="4500">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">Use the Docker host's published external ports, not the internal RouterOS service ports. These values are used for endpoint documentation and client configuration generation.</p>
                        </div>

                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">Public Hostname Per Service</h6>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="l2tp_host" class="label admin-label">L2TP Hostname</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="l2tp_host" name="l2tp_host" placeholder="l2tp.example.com">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="pptp_host" class="label admin-label">PPTP Hostname</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="pptp_host" name="pptp_host" placeholder="pptp.example.com">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="sstp_host" class="label admin-label">SSTP Hostname</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="sstp_host" name="sstp_host" placeholder="sstp.example.com">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">If provided, these hostnames are used by the service tester and client configuration generator. If left empty, the app falls back to the main MikroTik host.</p>
                        </div>

                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">QEMU Dynamic Host Forward</h6>
                            </div>
                            <div class="notification is-link is-light">
                                <span class="icon is-small"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></span>
                                <span>Use <strong>Local Socket</strong> when MikReMan runs on the same host as the QEMU CHR. Use <strong>Remote SSH Key</strong> when the app runs elsewhere, and use a restricted SSH account dedicated to hostfwd operations.</span>
                            </div>
                            <div class="field">
                                <div class="control">
                                    <label class="checkbox admin-checkbox" for="qemu_hostfwd_enabled">
                                        <input type="checkbox" id="qemu_hostfwd_enabled" name="qemu_hostfwd_enabled">
                                        Enable runtime `hostfwd_add/remove` for random NAT ports
                                    </label>
                                </div>
                            </div>
                            <div class="field">
                                <label for="qemu_hostfwd_mode" class="label admin-label">Host Forward Mode</label>
                                <div class="control">
                                    <div class="select is-fullwidth">
                                        <select id="qemu_hostfwd_mode" name="qemu_hostfwd_mode">
                                            <option value="local">Local Socket</option>
                                            <option value="ssh">Remote SSH Key</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="columns is-multiline is-variable is-4 qemu-mode-group" data-qemu-mode-group="local">
                                <div class="column is-12-mobile is-7-tablet">
                                    <div class="field">
                                        <label for="qemu_hmp_socket" class="label admin-label">QEMU HMP Socket</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="qemu_hmp_socket" name="qemu_hmp_socket" placeholder="/opt/ros7-monitor/hmp.sock">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-5-tablet">
                                    <div class="field">
                                        <label for="qemu_hostfwd_binary" class="label admin-label">socat Binary</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="qemu_hostfwd_binary" name="qemu_hostfwd_binary" placeholder="/usr/bin/socat">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="columns is-multiline is-variable is-4 qemu-mode-group" data-qemu-mode-group="ssh">
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="qemu_ssh_host" class="label admin-label">SSH Host</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="qemu_ssh_host" name="qemu_ssh_host" placeholder="43.129.33.160">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="qemu_ssh_port" class="label admin-label">SSH Port</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="qemu_ssh_port" name="qemu_ssh_port" min="1" max="65535" placeholder="22">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet is-3-desktop">
                                    <div class="field">
                                        <label for="qemu_ssh_user" class="label admin-label">SSH User</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="qemu_ssh_user" name="qemu_ssh_user" placeholder="mikreman-fwd">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="qemu_ssh_binary" class="label admin-label">SSH Binary</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="qemu_ssh_binary" name="qemu_ssh_binary" placeholder="/usr/bin/ssh">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="qemu_ssh_known_hosts_path" class="label admin-label">Known Hosts Path</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="qemu_ssh_known_hosts_path" name="qemu_ssh_known_hosts_path" placeholder="/var/www/.ssh/known_hosts">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12">
                                    <div class="field">
                                        <label for="qemu_ssh_private_key" class="label admin-label">SSH Private Key</label>
                                        <div class="control">
                                            <textarea class="textarea admin-input qemu-private-key-input" id="qemu_ssh_private_key" name="qemu_ssh_private_key" rows="10" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"></textarea>
                                        </div>
                                        <p class="help has-text-grey-light">Paste the full private key here. Once it has been saved, this field will show <code>••••••••</code> after the page is reloaded.</p>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">Use this only for CHR deployments based on QEMU <code>user,hostfwd</code>. The app adds an <code>external-port -&gt; guest external-port</code> forward when NAT is created, and removes it again when NAT is deleted. In remote mode, store a restricted SSH user's private key here, not a root password. See <code>docs/qemu-hostfwd-deployment.md</code> for deployment details.</p>
                            <div class="buttons">
                                <button type="button" class="button is-link is-light admin-action-button" id="test-qemu-hostfwd">
                                    <span class="icon"><i class="bi bi-key-fill" aria-hidden="true"></i></span>
                                    <span>Test SSH Key</span>
                                </button>
                            </div>
                        </div>

                        <div class="buttons admin-button-group">
                            <button type="submit" class="button is-primary admin-action-button">
                                <i class="bi bi-check-lg"></i>
                                <span>Save</span>
                            </button>
                            <button type="button" class="button is-info is-light admin-action-button" id="test-connection">
                                <i class="bi bi-plug"></i>
                                <span class="is-hidden-mobile">Test</span>
                                <span class="is-hidden-tablet">Test</span>
                            </button>
                            <button type="button" class="button is-success admin-action-button" id="connect-mikrotik" onclick="handleConnectClick()">
                                <i class="bi bi-link-45deg"></i>
                                <span class="is-hidden-mobile" id="connect-text">Connect</span>
                                <span class="is-hidden-tablet" id="connect-text-mobile">Link</span>
                            </button>
                            <button type="button" class="button is-primary is-light admin-action-button" id="ssl-toggle" data-ssl="true">
                                <i class="bi bi-shield-lock"></i>
                                <span class="is-hidden-mobile">HTTPS/SSL</span>
                                <span class="is-hidden-tablet">SSL</span>
                            </button>
                        </div>

                        <div class="admin-connection-status is-hidden" id="connection-status">
                            <div class="notification is-success admin-notification admin-status-notification" role="alert">
                                <span class="admin-alert-icon"><i class="bi bi-check-circle-fill"></i></span>
                                <div>
                                    <strong>Connected to MikroTik</strong>
                                    <br>
                                    <small id="connection-info">Router: Loading...</small>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" id="mt_use_ssl" name="use_ssl" value="true">
                    </form>
                </div>
            </div>
        </section>

        <section class="admin-tab-panel" id="admin-tab-auth" data-admin-panel="auth" role="tabpanel" aria-labelledby="admin-tab-auth-link">
            <div class="card enhanced-card admin-card">
                <div class="card-header admin-card-header">
                    <div class="card-header-content">
                        <div class="card-icon">
                            <span class="icon"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">Login Settings</h5>
                            <small class="card-subtitle">Web interface credentials</small>
                        </div>
                    </div>
                </div>
                <div class="card-body admin-card-body">
                    <form id="auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                        <div class="field">
                            <label for="auth_username" class="label admin-label">Username</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="auth_username" name="username" placeholder="Enter new username or leave empty to keep current" autocomplete="username" maxlength="50">
                            </div>
                        </div>

                        <div class="field">
                            <label for="auth_password" class="label admin-label">Password</label>
                            <div class="field has-addons admin-field-addons">
                                <div class="control is-expanded">
                                    <input type="password" class="input admin-input" id="auth_password" name="password" placeholder="" autocomplete="new-password" maxlength="255">
                                </div>
                                <div class="control">
                                    <button class="button is-dark is-outlined admin-addon-button" type="button" id="toggleAuthPassword" onclick="togglePasswordVisibility()">
                                        <span class="icon"><i class="bi bi-eye"></i></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="button is-warning admin-action-button">
                            <i class="bi bi-shield-check"></i>
                            Update Login
                        </button>
                    </form>

                    <hr class="admin-divider">

                    <form id="cloudflare-form">
                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">Cloudflare Turnstile</h6>
                            </div>
                            <div class="field">
                                <div class="control">
                                    <label class="checkbox admin-checkbox" for="turnstile_enabled">
                                        <input type="checkbox" id="turnstile_enabled" name="turnstile_enabled">
                                        Enable Turnstile protection
                                    </label>
                                </div>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-tablet is-6-desktop">
                                    <div class="field">
                                        <label for="turnstile_site_key" class="label admin-label">Site Key</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="turnstile_site_key" name="turnstile_site_key" placeholder="0x4AAAAA..." autocomplete="off">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-tablet is-6-desktop">
                                    <div class="field">
                                        <label for="turnstile_secret_key" class="label admin-label">Secret Key</label>
                                        <div class="control">
                                            <input type="password" class="input admin-input" id="turnstile_secret_key" name="turnstile_secret_key" placeholder="0x4AAAAA..." autocomplete="off">
                                        </div>
                                        <p class="help has-text-grey-light">Leave the masked value unchanged to keep the current secret key.</p>
                                    </div>
                                </div>
                                <div class="column is-12-tablet is-6-desktop">
                                    <div class="field">
                                        <div class="control">
                                            <label class="checkbox admin-checkbox" for="turnstile_login_enabled">
                                                <input type="checkbox" id="turnstile_login_enabled" name="turnstile_login_enabled">
                                                Protect admin login
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-tablet is-6-desktop">
                                    <div class="field">
                                        <div class="control">
                                            <label class="checkbox admin-checkbox" for="turnstile_order_enabled">
                                                <input type="checkbox" id="turnstile_order_enabled" name="turnstile_order_enabled">
                                                Protect public order page
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="button is-link admin-action-button">
                                <i class="bi bi-cloud-check"></i>
                                Save Turnstile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section class="admin-tab-panel" id="admin-tab-telegram" data-admin-panel="telegram" role="tabpanel" aria-labelledby="admin-tab-telegram-link">
            <div class="card enhanced-card admin-card">
                <div class="card-header admin-card-header">
                    <div class="card-header-content">
                        <div class="card-icon telegram-icon">
                            <span class="icon"><i class="bi bi-telegram" aria-hidden="true"></i></span>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">Telegram Bot</h5>
                            <small class="card-subtitle">Backup notification settings</small>
                        </div>
                    </div>
                </div>
                <div class="card-body admin-card-body">
                    <form id="telegram-form">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                        <div class="field">
                            <label for="bot_token" class="label admin-label">Bot Token</label>
                            <div class="field has-addons admin-field-addons">
                                <div class="control is-expanded">
                                    <input type="password" class="input admin-input" id="bot_token" name="bot_token" placeholder="123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11" autocomplete="off" maxlength="500">
                                </div>
                                <div class="control">
                                    <button class="button is-dark is-outlined admin-addon-button" type="button" id="toggleBotToken">
                                        <span class="icon"><i class="bi bi-eye"></i></span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="field">
                            <label for="chat_id" class="label admin-label">Chat ID</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="chat_id" name="chat_id" placeholder="-123456789" autocomplete="off" maxlength="20">
                            </div>
                        </div>

                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="telegram_enabled">
                                    <input type="checkbox" id="telegram_enabled" name="enabled">
                                    <span>Enable</span>
                                </label>
                            </div>
                        </div>

                        <div class="buttons admin-button-group">
                            <button type="submit" class="button is-success admin-action-button">
                                <i class="bi bi-check-lg"></i>
                                <span>Save</span>
                            </button>
                            <button type="button" class="button is-info is-light admin-action-button" id="test-telegram">
                                <i class="bi bi-send"></i>
                                <span class="is-hidden-mobile">Test Bot</span>
                                <span class="is-hidden-tablet">Test</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section class="admin-tab-panel" id="admin-tab-vpn" data-admin-panel="vpn" role="tabpanel" aria-labelledby="admin-tab-vpn-link">
            <div class="card enhanced-card admin-card">
                <div class="card-header admin-card-header">
                    <div class="card-header-content">
                        <div class="card-icon">
                            <span class="icon"><i class="bi bi-hdd-network-fill" aria-hidden="true"></i></span>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">VPN Services</h5>
                            <small class="card-subtitle">Server management & control</small>
                        </div>
                    </div>
                </div>
                <div class="card-body admin-card-body">
                    <div class="columns is-multiline admin-service-grid">
                        <div class="column is-12-mobile is-6-tablet is-3-desktop">
                            <div class="service-card">
                                <div class="service-header">
                                    <i class="bi bi-shield-fill-check service-icon l2tp"></i>
                                    <h6 class="service-name">L2TP Server</h6>
                                </div>
                                <div class="buttons service-actions">
                                    <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-l2tp" data-service="l2tp">
                                        <i class="bi bi-power"></i>
                                        <span>Enable</span>
                                    </button>
                                    <button class="button is-info is-light service-btn admin-service-test" id="test-l2tp" data-service="l2tp">
                                        <i class="bi bi-search"></i>
                                        <span>Test</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="column is-12-mobile is-6-tablet is-3-desktop">
                            <div class="service-card">
                                <div class="service-header">
                                    <i class="bi bi-shield-fill-plus service-icon pptp"></i>
                                    <h6 class="service-name">PPTP Server</h6>
                                </div>
                                <div class="buttons service-actions">
                                    <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-pptp" data-service="pptp">
                                        <i class="bi bi-power"></i>
                                        <span>Enable</span>
                                    </button>
                                    <button class="button is-info is-light service-btn admin-service-test" id="test-pptp" data-service="pptp">
                                        <i class="bi bi-search"></i>
                                        <span>Test</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="column is-12-mobile is-6-tablet is-3-desktop">
                            <div class="service-card">
                                <div class="service-header">
                                    <i class="bi bi-shield-fill-exclamation service-icon sstp"></i>
                                    <h6 class="service-name">SSTP Server</h6>
                                </div>
                                <div class="buttons service-actions">
                                    <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-sstp" data-service="sstp">
                                        <i class="bi bi-power"></i>
                                        <span>Enable</span>
                                    </button>
                                    <button class="button is-info is-light service-btn admin-service-test" id="test-sstp" data-service="sstp">
                                        <i class="bi bi-search"></i>
                                        <span>Test</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                    <hr class="admin-divider">

                    <div class="profile-section">
                        <div class="section-header">
                            <h6 class="section-title">Service Profiles</h6>
                        </div>
                        <div class="columns is-mobile is-multiline admin-profile-grid">
                            <div class="column is-12-mobile is-4-tablet">
                                <button class="button is-link is-light is-small is-fullwidth profile-btn" id="create-l2tp-profile" data-service="l2tp">
                                    <i class="bi bi-plus-circle"></i>
                                    L2TP Profile
                                </button>
                            </div>
                            <div class="column is-12-mobile is-4-tablet">
                                <button class="button is-link is-light is-small is-fullwidth profile-btn" id="create-pptp-profile" data-service="pptp">
                                    <i class="bi bi-plus-circle"></i>
                                    PPTP Profile
                                </button>
                            </div>
                            <div class="column is-12-mobile is-4-tablet">
                                <button class="button is-link is-light is-small is-fullwidth profile-btn" id="create-sstp-profile" data-service="sstp">
                                    <i class="bi bi-plus-circle"></i>
                                    SSTP Profile
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="nat-section">
                        <div class="section-header">
                            <h6 class="section-title">NAT Configuration</h6>
                        </div>
                        <div class="columns is-mobile is-multiline admin-profile-grid">
                            <div class="column is-12-mobile is-6-tablet">
                                <button class="button is-warning is-light is-small is-fullwidth" id="create-nat-masquerade">
                                    <i class="bi bi-router"></i>
                                    NAT Masquerade
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="quick-actions">
                        <div class="section-header">
                            <h6 class="section-title">Quick Actions</h6>
                        </div>
                        <div class="buttons">
                            <button class="button is-info admin-action-button admin-wide-button" id="backup-config">
                                <i class="bi bi-cloud-download"></i>
                                <span class="is-hidden-mobile">Backup Config</span>
                                <span class="is-hidden-tablet">Backup</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="admin-tab-panel" id="admin-tab-wireguard" data-admin-panel="wireguard" role="tabpanel" aria-labelledby="admin-tab-wireguard-link" hidden>
            <div class="card enhanced-card admin-card">
                <div class="card-header admin-card-header">
                    <div class="card-header-content">
                        <div class="card-icon">
                            <span class="icon"><i class="bi bi-hurricane" aria-hidden="true"></i></span>
                        </div>
                        <div class="card-title-group">
                            <h5 class="card-title">WireGuard</h5>
                            <small class="card-subtitle">WireGuard interface and published endpoint settings</small>
                        </div>
                    </div>
                </div>
                <div class="card-body admin-card-body">
                    <form id="wireguard-form">
                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">Provisioning Backend</h6>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wireguard_backend" class="label admin-label">WireGuard Backend</label>
                                        <div class="control">
                                            <div class="select is-fullwidth">
                                                <select id="wireguard_backend" name="wireguard_backend">
                                                    <option value="mikrotik">RouterOS / CHR</option>
                                                    <option value="wg-easy">wg-easy</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wg_easy_url" class="label admin-label">wg-easy API URL</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wg_easy_url" name="wg_easy_url" placeholder="http://43.129.33.160:51821">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wg_easy_username" class="label admin-label">wg-easy Username</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wg_easy_username" name="wg_easy_username" placeholder="mikreman">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wg_easy_endpoint_host" class="label admin-label">wg-easy VPN Hostname</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wg_easy_endpoint_host" name="wg_easy_endpoint_host" placeholder="43.129.33.160">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wg_easy_endpoint_port" class="label admin-label">wg-easy VPN UDP Port</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="wg_easy_endpoint_port" name="wg_easy_endpoint_port" min="1" max="65535" placeholder="51820">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wg_easy_password" class="label admin-label">wg-easy Password</label>
                                        <div class="control">
                                            <input type="password" class="input admin-input" id="wg_easy_password" name="wg_easy_password" placeholder="Leave unchanged to keep existing password">
                                        </div>
                                        <p class="help has-text-grey-light">Only required when backend is set to <code>wg-easy</code>. Leave it untouched to keep the saved password.</p>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">Public WireGuard trials from <code>order.php</code> can be provisioned either on RouterOS or on a separate <code>wg-easy</code> server. The <code>wg-easy VPN Hostname</code> and UDP port are used only for trial exports, so RouterOS WireGuard peers can keep their own endpoint settings.</p>
                        </div>

                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">Published Endpoint</h6>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wireguard_host" class="label admin-label">WireGuard Hostname</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wireguard_host" name="wireguard_host" placeholder="wg.example.com">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wireguard_port" class="label admin-label">Published UDP Port</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="wireguard_port" name="wireguard_port" min="1" max="65535" placeholder="13231">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">Use the public UDP port and optional hostname that WireGuard clients should connect to.</p>
                        </div>

                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">RouterOS Interface</h6>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-mobile is-7-tablet">
                                    <div class="field">
                                        <label for="wireguard_interface" class="label admin-label">Interface Name</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wireguard_interface" name="wireguard_interface" placeholder="wireguard1">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-5-tablet">
                                    <div class="field">
                                        <label for="wireguard_mtu" class="label admin-label">MTU</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="wireguard_mtu" name="wireguard_mtu" min="1280" max="65535" placeholder="1420">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">WireGuard in RouterOS is managed as an interface and peers, not a PPP server. This phase manages the server interface only.</p>
                        </div>

                        <div class="profile-section">
                            <div class="section-header">
                                <h6 class="section-title">Peer Defaults</h6>
                            </div>
                            <div class="columns is-multiline is-variable is-4">
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wireguard_server_address" class="label admin-label">Server Address</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wireguard_server_address" name="wireguard_server_address" placeholder="10.66.66.1/24">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-6-tablet">
                                    <div class="field">
                                        <label for="wireguard_client_dns" class="label admin-label">Default Client DNS</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wireguard_client_dns" name="wireguard_client_dns" placeholder="8.8.8.8, 8.8.4.4">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-8-tablet">
                                    <div class="field">
                                        <label for="wireguard_allowed_ips" class="label admin-label">Default Allowed IPs</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wireguard_allowed_ips" name="wireguard_allowed_ips" placeholder="0.0.0.0/0, ::/0">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-4-tablet">
                                    <div class="field">
                                        <label for="wireguard_keepalive" class="label admin-label">Default Keepalive</label>
                                        <div class="control">
                                            <input type="number" class="input admin-input" id="wireguard_keepalive" name="wireguard_keepalive" min="0" max="65535" placeholder="25">
                                        </div>
                                    </div>
                                </div>
                                <div class="column is-12-mobile is-12-tablet">
                                    <div class="field">
                                        <label for="wireguard_client_name_suffix" class="label admin-label">Config Name Suffix</label>
                                        <div class="control">
                                            <input type="text" class="input admin-input" id="wireguard_client_name_suffix" name="wireguard_client_name_suffix" maxlength="64" placeholder="mikrotik-heryaan">
                                        </div>
                                        <p class="help has-text-grey-light">Optional suffix for downloaded client configs. Example: `peername-mikrotik-heryaan.conf`.</p>
                                    </div>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">These defaults are used for manual peers and for public WireGuard trials from `order.php`.</p>
                        </div>

                        <div class="buttons admin-button-group">
                            <button type="submit" class="button is-primary admin-action-button">
                                <i class="bi bi-check-lg"></i>
                                <span>Save WireGuard</span>
                            </button>
                            <button type="button" class="button is-link is-light admin-action-button" id="create-wireguard-interface">
                                <i class="bi bi-hurricane"></i>
                                <span>Provision Interface</span>
                            </button>
                        </div>
                    </form>

                    <hr class="admin-divider">

                    <div class="columns is-multiline admin-service-grid">
                        <div class="column is-12-mobile is-8-tablet is-6-desktop">
                            <div class="service-card">
                                <div class="service-header">
                                    <i class="bi bi-hurricane service-icon wireguard"></i>
                                    <h6 class="service-name">WireGuard Interface</h6>
                                </div>
                                <p class="help has-text-grey-light">Toggle the configured WireGuard interface and run a published endpoint sanity check.</p>
                                <div class="buttons service-actions">
                                    <button class="button is-success is-outlined service-btn admin-service-toggle" id="toggle-wireguard" data-service="wireguard">
                                        <i class="bi bi-power"></i>
                                        <span>Enable</span>
                                    </button>
                                    <button class="button is-info is-light service-btn admin-service-test" id="test-wireguard" data-service="wireguard">
                                        <i class="bi bi-search"></i>
                                        <span>Test</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>
    <?php
}
