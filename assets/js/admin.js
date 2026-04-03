// VPN Remote - Admin Panel JavaScript

class AdminPanel {
    constructor() {
        // Store user-entered passwords to prevent overwriting
        this.userPasswords = {
            mikrotik: '',
            auth: '',
            bot_token: '',
            turnstile_secret_key: ''
        };
        // Connection state
        this.isConnected = false;
        this.connectionInterval = null;
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.bindTabs();
        this.loadConfigurations();
        this.initPasswordToggles();
        this.bindNotificationDismiss();
    }

    getCsrfHeaders(additionalHeaders = {}) {
        return {
            'X-CSRF-Token': window.APP_CONFIG?.CSRF_TOKEN || '',
            ...additionalHeaders
        };
    }
    
    bindEvents() {
        // Form submissions
        document.getElementById('mikrotik-form')?.addEventListener('submit', (e) => this.handleMikrotikForm(e));
        document.getElementById('wireguard-form')?.addEventListener('submit', (e) => this.handleWireGuardForm(e));
        document.getElementById('auth-form')?.addEventListener('submit', (e) => this.handleAuthForm(e));
        document.getElementById('cloudflare-form')?.addEventListener('submit', (e) => this.handleCloudflareForm(e));
        document.getElementById('telegram-form')?.addEventListener('submit', (e) => this.handleTelegramForm(e));
        
        // Test buttons
        document.getElementById('test-connection')?.addEventListener('click', () => this.testMikrotikConnection());
        document.getElementById('test-qemu-hostfwd')?.addEventListener('click', () => this.testQemuHostfwdAccess());
        document.getElementById('test-telegram')?.addEventListener('click', () => this.testTelegramBot());

        // Connect button
        const connectBtn = document.getElementById('connect-mikrotik');
        console.log('Connect button found:', connectBtn);
        if (connectBtn) {
            connectBtn.addEventListener('click', () => {
                console.log('Connect button clicked!');
                this.connectMikrotik();
            });
        } else {
            console.error('Connect button NOT found in DOM');
        }
        
        // SSL Toggle button
        document.getElementById('ssl-toggle')?.addEventListener('click', () => this.toggleSSL());
        document.getElementById('topNavbarBurger')?.addEventListener('click', () => this.toggleSidebarMenu());
        document.getElementById('qemu_hostfwd_mode')?.addEventListener('change', (event) => {
            this.updateQemuHostfwdModeVisibility(event.target.value);
        });
        
        // Show current password button - removed, now handled by onclick in HTML
        
        // Service toggles - with more specific binding
        const l2tpBtn = document.getElementById('toggle-l2tp');
        const pptpBtn = document.getElementById('toggle-pptp');
        const sstpBtn = document.getElementById('toggle-sstp');
        const wireguardBtn = document.getElementById('toggle-wireguard');
        const l2tpTestBtn = document.getElementById('test-l2tp');
        const pptpTestBtn = document.getElementById('test-pptp');
        const sstpTestBtn = document.getElementById('test-sstp');
        const wireguardTestBtn = document.getElementById('test-wireguard');
        
        if (l2tpBtn) {
            l2tpBtn.addEventListener('click', (e) => {
                this.toggleService(e);
            });
        }
        
        if (pptpBtn) {
            pptpBtn.addEventListener('click', (e) => {
                this.toggleService(e);
            });
        }
        
        if (sstpBtn) {
            sstpBtn.addEventListener('click', (e) => {
                this.toggleService(e);
            });
        }

        if (wireguardBtn) {
            wireguardBtn.addEventListener('click', (e) => {
                this.toggleService(e);
            });
        }

        if (l2tpTestBtn) {
            l2tpTestBtn.addEventListener('click', (e) => {
                this.testVPNService(e);
            });
        }

        if (pptpTestBtn) {
            pptpTestBtn.addEventListener('click', (e) => {
                this.testVPNService(e);
            });
        }

        if (sstpTestBtn) {
            sstpTestBtn.addEventListener('click', (e) => {
                this.testVPNService(e);
            });
        }

        if (wireguardTestBtn) {
            wireguardTestBtn.addEventListener('click', (e) => {
                this.testVPNService(e);
            });
        }
        
        // Backup button
        const backupBtn = document.getElementById('backup-config');
        if (backupBtn) {
            backupBtn.addEventListener('click', (e) => {
                this.sendBackupToTelegram();
            });
        }
        
        // Profile service buttons
        const l2tpProfileBtn = document.getElementById('create-l2tp-profile');
        const pptpProfileBtn = document.getElementById('create-pptp-profile');
        const sstpProfileBtn = document.getElementById('create-sstp-profile');
        const wireguardInterfaceBtn = document.getElementById('create-wireguard-interface');
        
        if (l2tpProfileBtn) {
            l2tpProfileBtn.addEventListener('click', (e) => {
                this.createServiceProfile(e);
            });
        }
        
        if (pptpProfileBtn) {
            pptpProfileBtn.addEventListener('click', (e) => {
                this.createServiceProfile(e);
            });
        }
        
        if (sstpProfileBtn) {
            sstpProfileBtn.addEventListener('click', (e) => {
                this.createServiceProfile(e);
            });
        }

        if (wireguardInterfaceBtn) {
            wireguardInterfaceBtn.addEventListener('click', (e) => {
                this.createWireGuardInterface(e);
            });
        }
        
        // NAT Masquerade button
        const natMasqueradeBtn = document.getElementById('create-nat-masquerade');
        if (natMasqueradeBtn) {
            natMasqueradeBtn.addEventListener('click', (e) => {
                this.createNATMasquerade(e);
            });
        }
        
    }

    bindTabs() {
        const tabs = document.querySelectorAll('[data-admin-tab]');
        const panels = document.querySelectorAll('[data-admin-panel]');

        if (!tabs.length || !panels.length) {
            return;
        }

        tabs.forEach((tab) => {
            const link = tab.querySelector('a');

            link?.addEventListener('click', (event) => {
                event.preventDefault();
                this.activateTab(tab.dataset.adminTab);
            });
        });

        const hashMatch = window.location.hash.match(/^#admin-tab-(.+)$/);
        const requestedTab = hashMatch?.[1];
        const initialTab = Array.from(tabs).some((tab) => tab.dataset.adminTab === requestedTab)
            ? requestedTab
            : tabs[0].dataset.adminTab;

        this.activateTab(initialTab, false);
    }

    activateTab(tabName, updateHash = true) {
        const tabs = document.querySelectorAll('[data-admin-tab]');
        const panels = document.querySelectorAll('[data-admin-panel]');

        if (!tabName || !tabs.length || !panels.length) {
            return;
        }

        let hasMatch = false;

        tabs.forEach((tab) => {
            const isActive = tab.dataset.adminTab === tabName;
            const link = tab.querySelector('a');

            tab.classList.toggle('is-active', isActive);
            link?.setAttribute('aria-selected', isActive ? 'true' : 'false');

            if (isActive) {
                hasMatch = true;
            }
        });

        panels.forEach((panel) => {
            const isActive = panel.dataset.adminPanel === tabName;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        if (hasMatch && updateHash) {
            window.history.replaceState(null, '', `#admin-tab-${tabName}`);
        }
    }

    bindNotificationDismiss() {
        document.addEventListener('click', (event) => {
            if (event.target.classList.contains('delete')) {
                event.target.parentElement?.remove();
            }
        });
    }

    toggleSidebarMenu() {
        const nav = document.getElementById('topNavbarMenu');
        const toggle = document.getElementById('topNavbarBurger');

        if (!nav || !toggle) {
            return;
        }

        const isActive = nav.classList.toggle('is-active');
        toggle.classList.toggle('is-active', isActive);
        toggle.setAttribute('aria-expanded', isActive ? 'true' : 'false');
    }

    spinnerIcon(label = 'Processing...') {
        return `<span class="icon"><i class="bi bi-arrow-repeat spin"></i></span><span>${label}</span>`;
    }

    setButtonClasses(button, classes) {
        if (button) {
            button.className = classes;
        }
    }

    setActionButtonState(button, variant, light = false) {
        const variants = {
            primary: 'is-primary',
            success: 'is-success',
            danger: 'is-danger',
            warning: 'is-warning',
            info: 'is-info',
            dark: 'is-dark',
            link: 'is-link'
        };

        const variantClass = variants[variant] || variants.primary;
        const lightClass = light ? ' is-light' : '';
        this.setButtonClasses(button, `button ${variantClass}${lightClass} admin-action-button`);
    }

    setServiceButtonState(button, enabled) {
        if (!button) {
            return;
        }

        if (enabled === true || enabled === 'true') {
            this.setButtonClasses(button, 'button is-danger is-outlined service-btn admin-service-toggle');
            button.innerHTML = '<i class="bi bi-power"></i><span>Disable</span>';
            button.disabled = true;
            button.style.cursor = 'not-allowed';
            button.title = 'Service is currently active';
        } else {
            this.setButtonClasses(button, 'button is-success is-outlined service-btn admin-service-toggle');
            button.innerHTML = '<i class="bi bi-power"></i><span>Enable</span>';
            button.disabled = false;
            button.style.cursor = 'pointer';
            button.title = 'Click to enable service';
        }
    }

    setSmallStatusButton(button, variant, label, icon, light = false) {
        if (!button) {
            return;
        }

        const variants = {
            success: 'is-success',
            warning: 'is-warning',
            link: 'is-link',
            info: 'is-info'
        };

        const variantClass = variants[variant] || variants.link;
        const lightClass = light ? ' is-light' : '';
        this.setButtonClasses(button, `button ${variantClass}${lightClass} is-small is-fullwidth profile-btn`);
        button.innerHTML = `<i class="bi bi-${icon}"></i><span>${label}</span>`;
    }
    
    initPasswordToggles() {
        // MikroTik password input handling (for storing typed password)
        const mtPassword = document.getElementById('mt_password');
        
        if (mtPassword) {
            // Store password when user types (save to class property)
            mtPassword.addEventListener('input', () => {
                // If user starts typing while field shows bullets, clear it first
                if (mtPassword.value.startsWith('••••••••') || mtPassword.value.startsWith('\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022')) {
                    mtPassword.value = '';
                    return; // Let user continue typing
                }
                this.userPasswords.mikrotik = mtPassword.value;
            });
            
            // Restore password on focus if it was masked
            mtPassword.addEventListener('focus', () => {
                if ((mtPassword.value === '••••••••' || mtPassword.value === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022') && this.userPasswords.mikrotik) {
                    mtPassword.value = this.userPasswords.mikrotik;
                }
            });
        }
        
        // Auth password toggle
        const toggleAuthPassword = document.getElementById('toggleAuthPassword');
        const authPassword = document.getElementById('auth_password');
        
        if (toggleAuthPassword && authPassword) {
            // Store password when user types (save to class property)
            authPassword.addEventListener('input', () => {
                // If user starts typing while field shows bullets, clear it first
                if (authPassword.value.startsWith('••••••••') || authPassword.value.startsWith('\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022')) {
                    authPassword.value = '';
                    return; // Let user continue typing
                }
                this.userPasswords.auth = authPassword.value;
            });
            
            // Restore password on focus if it was masked
            authPassword.addEventListener('focus', () => {
                if ((authPassword.value === '••••••••' || authPassword.value === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022') && this.userPasswords.auth) {
                    authPassword.value = this.userPasswords.auth;
                }
            });
            
            toggleAuthPassword.addEventListener('click', async () => {
                if (authPassword.type === 'password') {
                    // Show password - need to get actual password if field shows bullets
                    if (authPassword.value === '••••••••' || authPassword.value === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022') {
                        if (this.userPasswords.auth) {
                            // Use stored password
                            authPassword.value = this.userPasswords.auth;
                        } else {
                            // Fetch from server
                            try {
                                const response = await fetch('../api/config.php?action=get_password&section=auth&key=password', {
                                    headers: this.getCsrfHeaders()
                                });
                                const result = await response.json();
                                if (result.success) {
                                    authPassword.value = result.password;
                                    this.userPasswords.auth = result.password;
                                }
                            } catch (error) {
                                console.error('Failed to get auth password:', error);
                            }
                        }
                    }
                    
                    authPassword.type = 'text';
                    toggleAuthPassword.innerHTML = '<i class="bi bi-eye-slash"></i>';
                } else {
                    // Hide password
                    authPassword.type = 'password';
                    toggleAuthPassword.innerHTML = '<i class="bi bi-eye"></i>';
                }
            });
        }
        
        // Bot Token toggle
        const toggleBotToken = document.getElementById('toggleBotToken');
        const botToken = document.getElementById('bot_token');
        
        if (toggleBotToken && botToken) {
            // Store bot token when user types (save to class property)
            botToken.addEventListener('input', () => {
                // If user starts typing while field shows bullets, clear it first
                if (botToken.value.startsWith('••••••••') || botToken.value.includes('••••••••')) {
                    botToken.value = '';
                    return; // Let user continue typing
                }
                this.userPasswords.bot_token = botToken.value;
            });
            
            // Restore bot token on focus if it was masked
            botToken.addEventListener('focus', () => {
                if ((botToken.value === '••••••••' || botToken.value.includes('••••••••')) && this.userPasswords.bot_token) {
                    botToken.value = this.userPasswords.bot_token;
                }
            });
            
            toggleBotToken.addEventListener('click', async () => {
                if (botToken.type === 'password') {
                    // Show bot token - need to get actual token if field shows bullets
                    if (botToken.value === '••••••••' || botToken.value.includes('••••••••')) {
                        if (this.userPasswords.bot_token) {
                            // Use stored token
                            botToken.value = this.userPasswords.bot_token;
                        } else {
                            // Fetch from server
                            try {
                                const response = await fetch('../api/config.php?action=get_password&section=telegram&key=bot_token', {
                                    headers: this.getCsrfHeaders()
                                });
                                const result = await response.json();
                                if (result.success) {
                                    botToken.value = result.password;
                                    this.userPasswords.bot_token = result.password;
                                }
                            } catch (error) {
                                console.error('Failed to get bot token:', error);
                            }
                        }
                    }
                    
                    botToken.type = 'text';
                    toggleBotToken.innerHTML = '<i class="bi bi-eye-slash"></i>';
                } else {
                    // Hide bot token
                    botToken.type = 'password';
                    toggleBotToken.innerHTML = '<i class="bi bi-eye"></i>';
                }
            });
        }

        const turnstileSecret = document.getElementById('turnstile_secret_key');
        if (turnstileSecret) {
            turnstileSecret.addEventListener('input', () => {
                if (turnstileSecret.value.startsWith('••••••••') || turnstileSecret.value.startsWith('\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022')) {
                    turnstileSecret.value = '';
                    return;
                }
                this.userPasswords.turnstile_secret_key = turnstileSecret.value;
            });

            turnstileSecret.addEventListener('focus', () => {
                if ((turnstileSecret.value === '••••••••' || turnstileSecret.value === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022') && this.userPasswords.turnstile_secret_key) {
                    turnstileSecret.value = this.userPasswords.turnstile_secret_key;
                } else if (turnstileSecret.value === '••••••••' || turnstileSecret.value === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022') {
                    turnstileSecret.value = '';
                }
            });
        }
        
        // Password confirmation validation
        const authConfirm = document.getElementById('auth_confirm');
        if (authPassword && authConfirm) {
            authConfirm.addEventListener('input', () => {
                if (authPassword.value !== authConfirm.value) {
                    authConfirm.setCustomValidity('Passwords do not match');
                } else {
                    authConfirm.setCustomValidity('');
                }
            });
        }
    }
    
    async loadConfigurations() {
        try {
            const response = await fetch('../api/config.php?action=get_all', {
                headers: this.getCsrfHeaders()
            });
            
            // Check if response is OK
            if (!response.ok) {
                console.error('Response not OK:', response.status, response.statusText);
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server did not return JSON response');
            }
            
            const data = await response.json();
            
            
            if (data.success) {
                // Safely populate forms with default values if data is missing
                this.populateForm('mikrotik-form', data.config.mikrotik || {});
                this.populateForm('wireguard-form', data.config.mikrotik || {});
                this.populateForm('auth-form', data.config.auth || {});
                this.populateForm('cloudflare-form', data.config.cloudflare || {});
                this.populateForm('telegram-form', data.config.telegram || {});
                
                // Update service status
                this.updateServiceStatuses(data.config.services || {});
                
                // Check profile status
                this.checkProfilesStatus();
                
                // Check NAT status
                this.checkNATStatus();
                
                // Refresh service status from MikroTik in background
                this.refreshServiceStatusFromMikroTik();
            } else {
                this.showAlert('Failed to load configuration: ' + (data.message || 'Unknown error'), 'warning');
            }

            this.updateQemuHostfwdModeVisibility(document.getElementById('qemu_hostfwd_mode')?.value);
        } catch (error) {
            
            // Show user-friendly error message
            let errorMessage = 'Unable to load configuration. ';
            if (error.message.includes('JSON')) {
                errorMessage += 'Configuration file may be corrupted or missing.';
            } else if (error.message.includes('HTTP')) {
                errorMessage += 'Server connection failed.';
            } else {
                errorMessage += error.message;
            }
            
            this.showAlert(errorMessage, 'warning');
            
            // Initialize forms with empty values
            this.initializeEmptyForms();
        }
    }
    
    initializeEmptyForms() {
        // Initialize forms with default/empty values
        this.populateForm('mikrotik-form', {
            host: '',
            username: '',
            password: '',
            port: '443',
            use_ssl: true,
            qemu_hostfwd_enabled: false,
            qemu_hostfwd_mode: 'local',
            qemu_hmp_socket: '/opt/ros7-monitor/hmp.sock',
            qemu_hostfwd_binary: '/usr/bin/socat',
            qemu_ssh_host: '',
            qemu_ssh_port: '22',
            qemu_ssh_user: '',
            qemu_ssh_private_key: '',
            qemu_ssh_known_hosts_path: '',
            qemu_ssh_binary: '/usr/bin/ssh',
            rest_http_port: '7004',
            rest_https_port: '7005',
            winbox_port: '7000',
            api_port: '7001',
            api_ssl_port: '7002',
            ssh_port: '7003',
            l2tp_port: '1701',
            l2tp_host: '',
            pptp_port: '1723',
            pptp_host: '',
            sstp_port: '443',
            sstp_host: '',
            ipsec_port: '500',
            ipsec_nat_t_port: '4500'
        });

        this.populateForm('wireguard-form', {
            wireguard_port: '13231',
            wireguard_host: '',
            wireguard_interface: 'wireguard1',
            wireguard_mtu: '1420',
            wireguard_server_address: '10.66.66.1/24',
            wireguard_client_dns: '8.8.8.8, 8.8.4.4',
            wireguard_allowed_ips: '0.0.0.0/0, ::/0',
            wireguard_keepalive: '25',
            wireguard_client_name_suffix: ''
        });
        
        this.populateForm('auth-form', {
            username: '',
            password: ''
        });

        this.populateForm('cloudflare-form', {
            turnstile_enabled: false,
            turnstile_login_enabled: true,
            turnstile_order_enabled: true,
            turnstile_site_key: '',
            turnstile_secret_key: ''
        });
        
        this.populateForm('telegram-form', {
            bot_token: '',
            chat_id: '',
            enabled: false
        });

        this.updateQemuHostfwdModeVisibility(document.getElementById('qemu_hostfwd_mode')?.value);
    }
    
    populateForm(formId, data) {
        const form = document.getElementById(formId);
        if (!form || !data) return;
        
        
        Object.keys(data).forEach(key => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input) {
                if (input.type === 'checkbox' || (key === 'use_ssl' && input.type === 'hidden')) {
                    // Handle SSL toggle button
                    if (key === 'use_ssl') {
                        this.updateSSLButton(data[key]);
                        input.value = data[key] ? 'true' : 'false';
                    } else {
                        input.checked = data[key];
                    }
                } else if (input.type === 'password') {
                    
                    // Handle password fields specially - check for both unicode and string bullets
                    const isPasswordMasked = data[key] === '••••••••' || 
                                           data[key] === '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' ||
                                           (key === 'bot_token' && data[key] && (data[key].includes('••••••••') || data[key].includes('\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022')));
                    const hasUserPassword = (formId === 'mikrotik-form' && this.userPasswords.mikrotik) || 
                                          (formId === 'auth-form' && this.userPasswords.auth) ||
                                          (formId === 'telegram-form' && key === 'bot_token' && this.userPasswords.bot_token) ||
                                          (formId === 'cloudflare-form' && key === 'turnstile_secret_key' && this.userPasswords.turnstile_secret_key);
                    
                    if (isPasswordMasked && hasUserPassword) {
                        // User has typed a password, keep it
                        // Restore the actual password value
                        if (formId === 'mikrotik-form') {
                            input.value = this.userPasswords.mikrotik;
                        } else if (formId === 'auth-form') {
                            input.value = this.userPasswords.auth;
                        } else if (formId === 'telegram-form' && key === 'bot_token') {
                            input.value = this.userPasswords.bot_token;
                        } else if (formId === 'cloudflare-form' && key === 'turnstile_secret_key') {
                            input.value = this.userPasswords.turnstile_secret_key;
                        }
                    } else if (isPasswordMasked) {
                        // Password exists on server - show bullets in field VALUE
                        if (key === 'bot_token' && (data[key].includes('••••••••') || data[key].includes('\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022'))) {
                            // For bot token, show partial masked value
                            input.value = '••••••••';
                        } else {
                            // For passwords, show full bullets as VALUE
                            input.value = '••••••••';
                        }
                        // Clear placeholder for auth password field specifically
                        if (formId === 'auth-form' && key === 'password') {
                            input.placeholder = '';
                        } else {
                            input.placeholder = key === 'bot_token' ? 'Bot Token' : 'Password';
                        }
                    } else if (data[key] && data[key] !== '') {
                        // Valid password data from server
                        input.value = data[key];
                        // Also store it as user password
                        if (formId === 'mikrotik-form' && key === 'password') {
                            this.userPasswords.mikrotik = data[key];
                        } else if (formId === 'auth-form' && key === 'password') {
                            this.userPasswords.auth = data[key];
                        } else if (formId === 'telegram-form' && key === 'bot_token') {
                            this.userPasswords.bot_token = data[key];
                        } else if (formId === 'cloudflare-form' && key === 'turnstile_secret_key') {
                            this.userPasswords.turnstile_secret_key = data[key];
                        }
                    } else {
                        // No password exists - set placeholder for empty fields
                        if (formId === 'auth-form' && key === 'password') {
                            input.placeholder = '';
                            input.value = '';
                        } else {
                            input.placeholder = 'Enter password';
                        }
                    }
                } else {
                    input.value = data[key];
                }
            }
        });

        if (formId === 'mikrotik-form') {
            this.updateQemuHostfwdModeVisibility(form.querySelector('[name="qemu_hostfwd_mode"]')?.value);
        }
    }

    updateQemuHostfwdModeVisibility(mode = 'local') {
        const groups = document.querySelectorAll('[data-qemu-mode-group]');

        groups.forEach((group) => {
            const isActive = group.dataset.qemuModeGroup === mode;
            group.classList.toggle('is-hidden', !isActive);
            group.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });
    }
    
    async handleMikrotikForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);
        
        // Convert SSL value from string to boolean
        data.use_ssl = data.use_ssl === 'true';
        data.qemu_hostfwd_enabled = formData.has('qemu_hostfwd_enabled');
        data.qemu_hostfwd_mode = data.qemu_hostfwd_mode || 'local';

        if (data.qemu_ssh_private_key === '••••••••') {
            delete data.qemu_ssh_private_key;
        }
        
        // Fix password handling - don't save bullets, use actual password
        if (data.password === '••••••••') {
            // If password field shows bullets but user has typed a password, use that
            if (this.userPasswords.mikrotik) {
                data.password = this.userPasswords.mikrotik;
            } else {
                // Remove password from data so it won't overwrite existing
                delete data.password;
            }
        }
        
        await this.saveConfiguration('mikrotik', data, 'MikroTik configuration saved successfully!');
    }
    
    async handleAuthForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);
        
        // Only include non-empty fields (selective update)
        const updateData = {};
        
        if (data.username && data.username.trim() !== '') {
            updateData.username = data.username.trim();
        }
        
        if (data.password && data.password.trim() !== '') {
            updateData.password = data.password.trim();
        }
        
        // Check if there's anything to update
        if (Object.keys(updateData).length === 0) {
            this.showAlert('No changes to update. Please enter a new username or password.', 'warning');
            return;
        }
        
        await this.saveConfiguration('auth', updateData, 'Login credentials updated successfully!');
        
        // Clear form after successful update
        form.reset();
    }

    async handleWireGuardForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);

        await this.saveConfiguration('mikrotik', data, 'WireGuard settings saved successfully!');
    }
    
    async handleTelegramForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);
        
        // Convert checkbox value
        data.enabled = formData.has('enabled');
        
        await this.saveConfiguration('telegram', data, 'Telegram settings saved successfully!');
    }

    async handleCloudflareForm(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);

        data.turnstile_enabled = formData.has('turnstile_enabled');
        data.turnstile_login_enabled = formData.has('turnstile_login_enabled');
        data.turnstile_order_enabled = formData.has('turnstile_order_enabled');

        if (data.turnstile_secret_key === '••••••••') {
            if (this.userPasswords.turnstile_secret_key) {
                data.turnstile_secret_key = this.userPasswords.turnstile_secret_key;
            } else {
                delete data.turnstile_secret_key;
            }
        }

        await this.saveConfiguration('cloudflare', data, 'Cloudflare Turnstile settings saved successfully!');
    }
    
    async saveConfiguration(section, data, successMessage) {
        try {
            const response = await fetch('../api/config.php', {
                method: 'POST',
                headers: this.getCsrfHeaders({
                    'Content-Type': 'application/json',
                }),
                body: JSON.stringify({
                    action: 'update_section',
                    section: section,
                    data: data
                })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server did not return JSON response');
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.showAlert(successMessage, 'success');
            } else {
                this.showAlert('Error: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('Configuration save error:', error);
            this.showAlert('Error saving configuration: ' + error.message, 'danger');
        }
    }
    
    async testMikrotikConnection() {
        const btn = document.getElementById('test-connection');
        const originalText = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Testing...');

        try {
            const response = await fetch('../api/mikrotik.php?action=test_connection');

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                let message = 'Connection successful!';
                if (result.data && result.data.board) {
                    message += `<br><strong>Board:</strong> ${result.data.board}`;
                    message += `<br><strong>Version:</strong> ${result.data.version}`;
                    message += `<br><strong>Uptime:</strong> ${result.data.uptime}`;
                }
                this.showAlert(message, 'success');

                // Reload configurations to get updated service statuses
                setTimeout(() => {
                    this.loadConfigurations();
                }, 1000);
            } else {
                this.showAlert('Connection failed: ' + result.message, 'danger');
            }
        } catch (error) {
            console.error('Connection test error:', error);
            this.showAlert('Error testing connection: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    async testQemuHostfwdAccess() {
        const form = document.getElementById('mikrotik-form');
        const btn = document.getElementById('test-qemu-hostfwd');

        if (!form || !btn) {
            return;
        }

        const formData = new FormData(form);
        const data = Object.fromEntries(formData);
        const originalText = btn.innerHTML;

        data.qemu_hostfwd_enabled = formData.has('qemu_hostfwd_enabled');
        data.qemu_hostfwd_mode = data.qemu_hostfwd_mode || 'local';

        if (data.qemu_ssh_private_key === '••••••••') {
            delete data.qemu_ssh_private_key;
        }

        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Testing...');

        try {
            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: this.getCsrfHeaders({
                    'Content-Type': 'application/json',
                }),
                body: JSON.stringify({
                    action: 'test_qemu_hostfwd',
                    ...data
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                this.showAlert(result.message || 'Remote SSH key and QEMU HMP access are working', 'success');
            } else {
                this.showAlert(`QEMU hostfwd test failed: ${result.message}`, 'danger');
            }
        } catch (error) {
            console.error('QEMU hostfwd test error:', error);
            this.showAlert('Error testing QEMU hostfwd access: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    async connectMikrotik() {
        console.log('connectMikrotik called');
        const btn = document.getElementById('connect-mikrotik');
        const btnText = document.getElementById('connect-text');
        const btnTextMobile = document.getElementById('connect-text-mobile');
        const statusDiv = document.getElementById('connection-status');
        const statusInfo = document.getElementById('connection-info');

        console.log('Button:', btn);
        console.log('Button text:', btnText);
        console.log('Status div:', statusDiv);

        if (this.isConnected) {
            // Disconnect
            this.disconnectMikrotik();
            return;
        }

        const originalText = btnText.textContent;
        btn.disabled = true;
        btnText.textContent = 'Connecting...';
        if (btnTextMobile) {
            btnTextMobile.textContent = 'Link';
        }

        try {
            const response = await fetch('../api/mikrotik.php?action=test_connection');

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                // Connection successful
                this.isConnected = true;

                // Update button state
                this.setActionButtonState(btn, 'danger');
                btnText.textContent = 'Disconnect';
                if (btnTextMobile) {
                    btnTextMobile.textContent = 'Off';
                }
                btn.disabled = false;

                // Show connection status
                if (result.data && result.data.board) {
                    statusInfo.textContent = `Router: ${result.data.board} | Version: ${result.data.version}`;
                } else {
                    statusInfo.textContent = 'Router: Connected';
                }
                statusDiv.classList.remove('is-hidden');

                this.showAlert('Connected to MikroTik router successfully! Auto-refresh every 2 seconds.', 'success');

                // Start periodic connection check (every 2 seconds)
                this.connectionInterval = setInterval(() => {
                    this.checkConnection();
                }, 2000);

                // Load configurations after connection
                setTimeout(() => {
                    this.loadConfigurations();
                }, 500);
            } else {
                throw new Error(result.message || 'Connection failed');
            }
        } catch (error) {
            console.error('Connection error:', error);
            this.showAlert('Failed to connect: ' + error.message, 'danger');
            btn.disabled = false;
            btnText.textContent = originalText;
            if (btnTextMobile) {
                btnTextMobile.textContent = 'Link';
            }
        }
    }

    disconnectMikrotik() {
        const btn = document.getElementById('connect-mikrotik');
        const btnText = document.getElementById('connect-text');
        const btnTextMobile = document.getElementById('connect-text-mobile');
        const statusDiv = document.getElementById('connection-status');

        // Stop periodic check
        if (this.connectionInterval) {
            clearInterval(this.connectionInterval);
            this.connectionInterval = null;
        }

        // Update UI
        this.isConnected = false;
        this.setActionButtonState(btn, 'success');
        btnText.textContent = 'Connect';
        if (btnTextMobile) {
            btnTextMobile.textContent = 'Link';
        }
        statusDiv.classList.add('is-hidden');

        this.showAlert('Disconnected from MikroTik router', 'info');
    }

    async checkConnection() {
        if (!this.isConnected) {
            return;
        }

        try {
            const response = await fetch('../api/mikrotik.php?action=test_connection');
            const result = await response.json();

            if (!result.success) {
                // Connection lost
                this.showAlert('Connection to MikroTik lost. Please reconnect.', 'warning');
                this.disconnectMikrotik();
            } else {
                // Connection OK - refresh service statuses silently
                this.refreshServiceStatusFromMikroTik();

                // Update connection info if available
                const statusInfo = document.getElementById('connection-info');
                if (statusInfo && result.data && result.data.board) {
                    statusInfo.textContent = `Router: ${result.data.board} | Version: ${result.data.version} | Uptime: ${result.data.uptime}`;
                }
            }
        } catch (error) {
            console.error('Connection check failed:', error);
            // Connection lost
            this.showAlert('Connection to MikroTik lost. Please reconnect.', 'warning');
            this.disconnectMikrotik();
        }
    }
    
    async testTelegramBot() {
        const btn = document.getElementById('test-telegram');
        const originalText = btn.innerHTML;
        
        // Check if bot token and chat ID are filled
        const botToken = document.getElementById('bot_token').value;
        const chatId = document.getElementById('chat_id').value;
        
        // Allow testing if values are masked (means they're saved) or filled
        const botTokenValid = botToken && (botToken !== '' && botToken.length > 0);
        const chatIdValid = chatId && (chatId !== '' && chatId.length > 0);
        
        if (!botTokenValid || !chatIdValid) {
            this.showAlert('Please enter both Bot Token and Chat ID and save the configuration before testing', 'warning');
            return;
        }
        
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Testing...');
        
        try {
            const response = await fetch('../api/telegram.php?action=test_bot', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });
            
            const result = await response.json();
            
            if (!response.ok) {
                // Handle server errors with more detail
                let errorMessage = `Server error (${response.status})`;
                if (result && result.message) {
                    errorMessage = result.message;
                }
                throw new Error(errorMessage);
            }
            
            if (result.success) {
                // Format the success message nicely
                let message = result.message;
                if (result.bot_info) {
                    message = `<strong>✅ Telegram Bot Test Successful!</strong><br><br>`;
                    message += `🤖 <strong>Bot Name:</strong> ${result.bot_info.name}<br>`;
                    message += `📝 <strong>Username:</strong> @${result.bot_info.username}<br>`;
                    message += `💬 <strong>Chat ID:</strong> ${result.bot_info.chat_id}<br><br>`;
                    message += `📤 A test message has been sent to your Telegram chat!`;
                }
                this.showAlert(message, 'success');
            } else {
                this.showAlert('❌ Telegram bot test failed: ' + result.message, 'danger');
            }
        } catch (error) {
            console.error('Telegram bot test error:', error);
            this.showAlert('❌ Error testing Telegram bot: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    async toggleService(e) {
        e.preventDefault();
        
        const btn = e.target.closest('button');
        if (!btn) {
            console.error('Button not found');
            return;
        }
        
        // Check if button is disabled
        if (btn.disabled) {
            return;
        }
        
        const service = btn.dataset.service;
        if (!service) {
            console.error('Service not defined in button dataset');
            return;
        }
        
        
        // Determine current state from button classes
        const isCurrentlyEnabled = btn.classList.contains('is-danger');
        const newState = !isCurrentlyEnabled;
        
        
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Processing...');
        
        try {
            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'toggle_service',
                    service: service,
                    enable: newState
                })
            });
            
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.updateServiceButton(btn, newState);
                this.showAlert(`${service.toUpperCase()} server ${newState ? 'enabled' : 'disabled'} successfully!`, 'success');
                
                // Refresh all service statuses to ensure consistency
                setTimeout(() => {
                    this.refreshServiceStatusFromMikroTik();
                }, 1000);
            } else {
                // Restore original button state
                btn.innerHTML = originalHtml;
                this.showAlert('Error toggling service: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('Service toggle error:', error);
            // Restore original button state
            btn.innerHTML = originalHtml;
            this.showAlert('Error toggling service: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
        }
    }

    async testVPNService(e) {
        e.preventDefault();

        const btn = e.target.closest('button');
        if (!btn) {
            return;
        }

        const service = btn.dataset.service;
        if (!service) {
            return;
        }

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Testing...');

        try {
            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'test_vpn_service',
                    service
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Failed to test VPN service');
            }

            const data = result.data || {};
            const probe = data.probe || {};
            const notes = Array.isArray(data.notes) ? data.notes : [];
            const probeStatus = probe.supported
                ? (probe.reachable ? 'Reachable' : 'Not reachable')
                : 'Skipped';

            const notesHtml = notes.length
                ? `<ul style="text-align:left;margin:0;padding-left:1.25rem;">${notes.map((note) => `<li>${this.escapeHtml(note)}</li>`).join('')}</ul>`
                : '<p>No additional notes.</p>';
            const detailsHtml = data.details
                ? `
                    ${data.details.name ? `<p><strong>Interface:</strong> ${this.escapeHtml(data.details.name)}</p>` : ''}
                    ${data.details.listen_port ? `<p><strong>Listen Port:</strong> ${this.escapeHtml(data.details.listen_port)}</p>` : ''}
                    ${data.details.public_key ? `<p><strong>Public Key:</strong> <code>${this.escapeHtml(data.details.public_key)}</code></p>` : ''}
                `
                : '';

            if (window.AppSwal) {
                window.AppSwal.alert({
                    title: `${service.toUpperCase()} Service Test`,
                    html: `
                        <div style="text-align:left">
                            <p><strong>Router Service:</strong> ${data.enabled ? 'Enabled' : 'Disabled'}</p>
                            <p><strong>Published Endpoint:</strong> ${this.escapeHtml(data.endpoint || '-')}</p>
                            <p><strong>Published Port Probe:</strong> ${this.escapeHtml(probeStatus)}</p>
                            ${probe.message ? `<p><strong>Probe Detail:</strong> ${this.escapeHtml(probe.message)}</p>` : ''}
                            ${detailsHtml}
                            <div><strong>Notes:</strong>${notesHtml}</div>
                        </div>
                    `,
                    icon: data.enabled && (probe.supported ? probe.reachable : true) ? 'success' : 'warning'
                });
            } else {
                this.showAlert(`${service.toUpperCase()} test: ${data.endpoint || '-'} (${probeStatus})`, data.enabled ? 'success' : 'warning');
            }
        } catch (error) {
            console.error('VPN service test error:', error);
            this.showAlert('Error testing VPN service: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
    
    updateServiceButton(btn, enabled) {
        
        this.setServiceButtonState(btn, enabled);
    }
    
    updateServiceStatuses(services) {
        
        Object.keys(services).forEach(service => {
            const btn = document.getElementById(`toggle-${service}`);
            if (btn) {
                this.updateServiceButton(btn, services[service]);
            }
        });
    }
    
    async checkProfilesStatus() {
        try {
            const response = await fetch('../api/mikrotik.php?action=check_profiles_status');
            
            if (!response.ok) {
                console.error('Failed to check profiles status:', response.status);
                return;
            }
            
            const result = await response.json();
            
            if (result.success && result.profiles) {
                this.updateProfileButtons(result.profiles);
            }
        } catch (error) {
            console.error('Error checking profiles status:', error);
        }
    }
    
    async checkNATStatus() {
        try {
            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'check_nat_status'
                })
            });
            
            if (!response.ok) {
                console.error('Failed to check NAT status:', response.status);
                return;
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.updateNATButton(result.nat_exists);
            }
        } catch (error) {
            console.error('Error checking NAT status:', error);
        }
    }
    
    updateProfileButtons(profilesStatus) {
        
        // Update L2TP profile button
        const l2tpBtn = document.getElementById('create-l2tp-profile');
        if (l2tpBtn) {
            if (profilesStatus.l2tp) {
                this.setSmallStatusButton(l2tpBtn, 'success', 'Created', 'check-circle');
                l2tpBtn.disabled = true;
                l2tpBtn.style.cursor = 'not-allowed';
            } else {
                this.setSmallStatusButton(l2tpBtn, 'link', 'L2TP Profile', 'plus-circle', true);
                l2tpBtn.disabled = false;
                l2tpBtn.style.cursor = 'pointer';
            }
        }
        
        // Update PPTP profile button
        const pptpBtn = document.getElementById('create-pptp-profile');
        if (pptpBtn) {
            if (profilesStatus.pptp) {
                this.setSmallStatusButton(pptpBtn, 'success', 'Created', 'check-circle');
                pptpBtn.disabled = true;
                pptpBtn.style.cursor = 'not-allowed';
            } else {
                this.setSmallStatusButton(pptpBtn, 'link', 'PPTP Profile', 'plus-circle', true);
                pptpBtn.disabled = false;
                pptpBtn.style.cursor = 'pointer';
            }
        }
        
        // Update SSTP profile button
        const sstpBtn = document.getElementById('create-sstp-profile');
        if (sstpBtn) {
            if (profilesStatus.sstp) {
                this.setSmallStatusButton(sstpBtn, 'success', 'Created', 'check-circle');
                sstpBtn.disabled = true;
                sstpBtn.style.cursor = 'not-allowed';
            } else {
                this.setSmallStatusButton(sstpBtn, 'link', 'SSTP Profile', 'plus-circle', true);
                sstpBtn.disabled = false;
                sstpBtn.style.cursor = 'pointer';
            }
        }
    }
    
    updateNATButton(natExists) {
        // Update NAT Masquerade button
        const natBtn = document.getElementById('create-nat-masquerade');
        if (natBtn) {
            if (natExists) {
                this.setSmallStatusButton(natBtn, 'success', 'Created', 'check-circle');
                natBtn.disabled = true;
                natBtn.style.cursor = 'not-allowed';
            } else {
                this.setSmallStatusButton(natBtn, 'warning', 'NAT Masquerade', 'router', true);
                natBtn.disabled = false;
                natBtn.style.cursor = 'pointer';
            }
        }
    }
    
    toggleSSL() {
        const sslButton = document.getElementById('ssl-toggle');
        const portInput = document.getElementById('mt_port');
        const hiddenInput = document.getElementById('mt_use_ssl');
        
        if (!sslButton || !portInput || !hiddenInput) {
            console.error('SSL toggle elements not found');
            return;
        }
        
        const currentSSL = sslButton.dataset.ssl === 'true';
        const newSSL = !currentSSL;
        
        
        // Update button appearance and data
        sslButton.dataset.ssl = newSSL.toString();
        hiddenInput.value = newSSL.toString();
        
        if (newSSL) {
            // Enable HTTPS/SSL
            this.setActionButtonState(sslButton, 'success');
            sslButton.innerHTML = '<i class="bi bi-shield-lock"></i><span>HTTPS/SSL</span>';
            
            // Auto-fill port 443 if current port is 80 or default
            if (portInput.value === '80' || portInput.value === '' || !this.isCustomPort(portInput.value)) {
                portInput.value = '443';
            }
        } else {
            // Disable HTTPS/SSL
            this.setActionButtonState(sslButton, 'primary');
            sslButton.innerHTML = '<i class="bi bi-shield"></i><span>HTTP</span>';
            
            // Auto-fill port 80 if current port is 443 or default
            if (portInput.value === '443' || portInput.value === '' || !this.isCustomPort(portInput.value)) {
                portInput.value = '80';
            }
        }
        
    }
    
    isCustomPort(port) {
        // Consider port as custom if it's not standard HTTP/HTTPS ports
        const standardPorts = ['80', '443', '8080', '8443'];
        return !standardPorts.includes(port);
    }
    
    async refreshServiceStatusFromMikroTik() {
        try {
            
            // Test connection silently to update service statuses
            const response = await fetch('../api/mikrotik.php?action=test_connection');
            
            if (response.ok) {
                const result = await response.json();
                
                if (result.success) {
                    
                    // Reload configurations to get updated service statuses
                    setTimeout(async () => {
                        const configResponse = await fetch('../api/config.php?action=get_all', {
                            headers: this.getCsrfHeaders()
                        });
                        if (configResponse.ok) {
                            const configData = await configResponse.json();
                            if (configData.success) {
                                // Update only service statuses without affecting other form data
                                this.updateServiceStatuses(configData.config.services || {});
                            }
                        }
                    }, 500);
                } else {
                }
            } else {
            }
        } catch (error) {
            console.error('Could not refresh service status from MikroTik:', error);
            // This is not critical, continue with cached status
        }
    }
    
    updateSSLButton(useSSL) {
        const sslButton = document.getElementById('ssl-toggle');
        
        if (!sslButton) {
            console.error('SSL toggle button not found');
            return;
        }
        
        // Convert to boolean if string
        const sslEnabled = useSSL === true || useSSL === 'true';
        
        sslButton.dataset.ssl = sslEnabled.toString();
        
        if (sslEnabled) {
            // HTTPS/SSL enabled
            this.setActionButtonState(sslButton, 'success');
            sslButton.innerHTML = '<i class="bi bi-shield-lock"></i><span>HTTPS/SSL</span>';
        } else {
            // HTTPS/SSL disabled
            this.setActionButtonState(sslButton, 'primary');
            sslButton.innerHTML = '<i class="bi bi-shield"></i><span>HTTP</span>';
        }
        
    }
    
    async showCurrentPassword() {
        
        const btn = document.getElementById('showCurrentPassword');
        const passwordInput = document.getElementById('auth_password');
        const usernameInput = document.getElementById('auth_username');
        
        
        if (!btn || !passwordInput || !usernameInput) {
            console.error('Auth elements not found');
            this.showAlert('Interface elements not found', 'danger');
            return;
        }
        
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Loading...');
        
        try {
            const apiUrl = '../api/config.php?action=get_auth_credentials';
            
            const response = await fetch(apiUrl, {
                method: 'GET',
                headers: this.getCsrfHeaders({
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                })
            });
            
            
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP error! status: ${response.status} - ${errorText}`);
            }
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                throw new Error('Invalid JSON response from server');
            }
            
            
            if (result.success && result.credentials) {
                // Show current credentials in placeholder
                usernameInput.placeholder = `Current: ${result.credentials.username}`;
                
                // Handle password display
                if (result.credentials.password.startsWith('[Password is')) {
                    // Password is hashed/encrypted
                    passwordInput.placeholder = `Current: ${result.credentials.password}`;
                    this.showAlert('Username shown. Password is encrypted and cannot be displayed.', 'info');
                } else {
                    // Password can be shown (plain text)
                    const hiddenPassword = `Current: ${'•'.repeat(result.credentials.password.length)}`;
                    passwordInput.placeholder = hiddenPassword;
                    
                    // Temporarily show actual password
                    setTimeout(() => {
                        passwordInput.placeholder = `Current: ${result.credentials.password}`;
                    }, 100);
                    
                    // Hide password again after 3 seconds
                    setTimeout(() => {
                        passwordInput.placeholder = hiddenPassword;
                    }, 3000);
                    
                    this.showAlert('Current credentials displayed. Password will hide after 3 seconds.', 'info');
                }
            } else {
                this.showAlert('Unable to load current credentials', 'danger');
            }
        } catch (error) {
            console.error('Show current password error:', error);
            this.showAlert('Error loading current credentials: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    // Test method to verify API works
    async testShowCredentials() {
        try {
            const response = await fetch('../api/config.php?action=get_auth_credentials', {
                headers: this.getCsrfHeaders()
            });
            const result = await response.json();
            return result;
        } catch (error) {
            console.error('Direct test error:', error);
        }
    }
    
    async sendBackupToTelegram() {
        const btn = document.getElementById('backup-config');
        if (!btn) {
            console.error('Backup button not found');
            return;
        }
        
        const originalText = btn.innerHTML;
        
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Creating backup...');
        
        try {
            
            const response = await fetch('../api/mikrotik.php?action=send_backup', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });
            
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error('Server returned invalid response');
            }
            
            
            if (result.success) {
                this.showAlert(result.message || 'Backup created successfully!', 'success');
            } else {
                this.showAlert('Error creating backup: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('Backup error:', error);
            this.showAlert('Error creating backup: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
    
    async createServiceProfile(e) {
        e.preventDefault();
        
        const btn = e.target.closest('button');
        if (!btn) {
            console.error('Profile service button not found');
            return;
        }
        
        // Prevent action if button is already disabled (profile already created)
        if (btn.disabled) {
            return;
        }
        
        const service = btn.dataset.service;
        if (!service) {
            console.error('Service not defined in button dataset');
            return;
        }
        
        
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Creating...');
        
        try {
            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'create_service_profile',
                    service: service
                })
            });
            
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error('Server returned invalid response');
            }
            
            
            if (result.success) {
                this.showAlert(result.message, 'success');
                
                // Update button to indicate profile was created and disable it
                setTimeout(() => {
                    this.setSmallStatusButton(btn, 'success', 'Created', 'check-circle');
                    btn.disabled = true;
                    btn.style.cursor = 'not-allowed';
                }, 500);
                
            } else {
                // Restore original button state
                btn.innerHTML = originalHtml;
                this.showAlert('Error creating profile: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('Profile creation error:', error);
            // Restore original button state
            btn.innerHTML = originalHtml;
            this.showAlert('Error creating profile: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
        }
    }

    async createWireGuardInterface(e) {
        e.preventDefault();

        const btn = e.target.closest('button');
        const form = document.getElementById('mikrotik-form');
        if (!btn || !form) {
            return;
        }

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Provisioning...');

        try {
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);

            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'create_wireguard_interface',
                    wireguard_interface: data.wireguard_interface || 'wireguard1',
                    wireguard_port: data.wireguard_port || '13231',
                    wireguard_mtu: data.wireguard_mtu || '1420',
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success) {
                const details = result.data || {};
                const parts = [
                    result.message || 'WireGuard interface provisioned successfully'
                ];

                if (details.name) {
                    parts.push(`Interface: ${details.name}`);
                }
                if (details.listen_port) {
                    parts.push(`Listen Port: ${details.listen_port}`);
                }

                this.showAlert(parts.join('<br>'), 'success');

                setTimeout(() => {
                    this.refreshServiceStatusFromMikroTik();
                }, 500);
            } else {
                this.showAlert('Error provisioning WireGuard: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            console.error('WireGuard provisioning error:', error);
            this.showAlert('Error provisioning WireGuard: ' + error.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
    
    showAlert(message, type = 'info') {
        if (window.AppSwal) {
            window.AppSwal.toast(message, type);
            return;
        }

        const alertsContainer = document.getElementById('alerts-container');
        const alertId = 'alert-' + Date.now();
        
        const alertHtml = `
            <div class="notification ${this.getAlertClass(type)} admin-notification fade-in" role="alert" id="${alertId}">
                <button type="button" class="delete" aria-label="Close"></button>
                <span class="admin-alert-icon"><i class="bi bi-${this.getAlertIcon(type)}"></i></span>
                ${message}
            </div>
        `;
        
        alertsContainer.insertAdjacentHTML('beforeend', alertHtml);
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.remove();
            }
        }, 5000);
    }
    
    getAlertIcon(type) {
        const icons = {
            'success': 'check-circle',
            'danger': 'exclamation-triangle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }

    getAlertClass(type) {
        const classes = {
            success: 'is-success',
            danger: 'is-danger',
            warning: 'is-warning',
            info: 'is-info is-light'
        };
        return classes[type] || 'is-info is-light';
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return String(text ?? '').replace(/[&<>"']/g, (char) => map[char]);
    }
    
    async createNATMasquerade(e) {
        e.preventDefault();
        
        const btn = e.target.closest('button');
        if (!btn) {
            return;
        }
        
        // Prevent action if button is already disabled (NAT already created)
        if (btn.disabled) {
            return;
        }
        
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = this.spinnerIcon('Creating...');
        
        try {
            const response = await fetch('../api/mikrotik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'create_nat_masquerade'
                })
            });
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error('Server returned invalid response');
            }
            
            if (result.success) {
                this.showAlert(result.message || 'NAT masquerade rule created successfully!', 'success');
                
                // Update button immediately to show created state
                this.updateNATButton(true);
                
                // Also refresh NAT status to ensure consistency
                setTimeout(() => {
                    this.checkNATStatus();
                }, 1000);
            } else {
                throw new Error(result.message || 'Failed to create NAT masquerade rule');
            }
        } catch (error) {
            this.showAlert('Error creating NAT masquerade: ' + error.message, 'danger');
        } finally {
            // Reset button state only if creation failed
            if (!btn.classList.contains('is-success')) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
    }
}

// Initialize admin panel
window.adminPanelInstance = null;

window.initializeAdminPanel = function() {
    try {
        if (!window.adminPanelInstance) {
            window.adminPanelInstance = new AdminPanel();
        }
    } catch (error) {
        console.error('Failed to initialize AdminPanel:', error.message, error);
    }
};

// Try immediate initialization if DOM is already ready
if (document.readyState === 'loading') {
    // DOM still loading, wait for it
    document.addEventListener('DOMContentLoaded', window.initializeAdminPanel);
} else {
    // DOM already loaded, initialize immediately
    window.initializeAdminPanel();
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (window.adminPanelInstance && window.adminPanelInstance.connectionInterval) {
        clearInterval(window.adminPanelInstance.connectionInterval);
    }
});
