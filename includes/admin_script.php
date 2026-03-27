<?php

function renderAdminPageScript(string $csrf_token): void
{
    ?>
    <script src="../assets/js/admin.js"></script>

    <script>
        window.APP_CONFIG = {
            CSRF_TOKEN: <?php echo sanitizeOutput($csrf_token, 'js'); ?>,
            MAX_RETRIES: 3,
            TIMEOUT: 30000
        };

        document.addEventListener('click', (event) => {
            if (event.target.classList.contains('delete')) {
                event.target.parentElement?.remove();
            }
        });

        function showAdminErrorAlert(title, text) {
            if (window.AppSwal) {
                window.AppSwal.alert({ title, text, icon: 'error' });
                return;
            }

            alert(`${title}: ${text}`);
        }

        async function handleConnectClick() {
            if (!window.adminPanelInstance && typeof window.initializeAdminPanel === 'function') {
                window.initializeAdminPanel();
            }

            if (window.adminPanelInstance) {
                window.adminPanelInstance.connectMikrotik();
            } else {
                setTimeout(() => {
                    if (window.adminPanelInstance) {
                        window.adminPanelInstance.connectMikrotik();
                    } else {
                        showAdminErrorAlert('Admin Panel Error', 'AdminPanel not initialized. Please refresh the page (Ctrl+Shift+R).');
                    }
                }, 200);
            }
        }

        async function togglePasswordVisibility() {
            const passwordInput = document.getElementById('auth_password');
            const usernameInput = document.getElementById('auth_username');
            const toggleBtn = document.getElementById('toggleAuthPassword');
            const eyeIcon = toggleBtn.querySelector('i');

            if (!passwordInput || !toggleBtn || !eyeIcon) {
                console.error('Missing elements');
                return;
            }

            if (passwordInput.value === '••••••••') {
                const originalBtnContent = toggleBtn.innerHTML;
                toggleBtn.disabled = true;
                toggleBtn.innerHTML = '<span class="icon"><i class="bi bi-arrow-repeat spin"></i></span>';

                try {
                    const response = await fetch('../api/config.php?action=get_auth_credentials', {
                        headers: {
                            'X-CSRF-Token': window.APP_CONFIG?.CSRF_TOKEN || ''
                        }
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const result = await response.json();

                    if (result.success && result.credentials) {
                        if (usernameInput && !usernameInput.value) {
                            usernameInput.value = result.credentials.username;
                        }
                        passwordInput.value = result.credentials.password;

                        if (window.adminPanel && window.adminPanel.userPasswords) {
                            window.adminPanel.userPasswords.auth = result.credentials.password;
                        }

                        passwordInput.type = 'text';
                        eyeIcon.className = 'bi bi-eye-slash';
                    } else {
                        showAdminErrorAlert('Failed to Load Credentials', result.message || 'Unknown error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAdminErrorAlert('Credential Error', error.message);
                } finally {
                    toggleBtn.disabled = false;
                    toggleBtn.innerHTML = originalBtnContent;
                }
            } else {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.className = 'bi bi-eye-slash';
                } else {
                    passwordInput.value = '';
                    passwordInput.type = 'password';
                    setTimeout(() => {
                        passwordInput.value = '••••••••';
                    }, 10);
                    eyeIcon.className = 'bi bi-eye';
                }
            }
        }

        async function toggleMikrotikPasswordVisibility() {
            const passwordInput = document.getElementById('mt_password');
            const toggleBtn = document.getElementById('toggleMtPassword');
            const eyeIcon = toggleBtn.querySelector('i');

            if (!passwordInput || !toggleBtn || !eyeIcon) {
                console.error('Missing elements');
                return;
            }

            if (!passwordInput.value || passwordInput.value === '••••••••') {
                const originalBtnContent = toggleBtn.innerHTML;
                toggleBtn.disabled = true;
                toggleBtn.innerHTML = '<span class="icon"><i class="bi bi-arrow-repeat spin"></i></span>';

                try {
                    const response = await fetch('../api/config.php?action=get_mikrotik_credentials', {
                        headers: {
                            'X-CSRF-Token': window.APP_CONFIG?.CSRF_TOKEN || ''
                        }
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const result = await response.json();

                    if (result.success && result.credentials) {
                        if (result.credentials.password && !result.credentials.password_masked) {
                            passwordInput.value = result.credentials.password;
                            passwordInput.type = 'text';
                            eyeIcon.className = 'bi bi-eye-slash';
                        } else {
                            passwordInput.value = '';
                            passwordInput.type = 'text';
                            passwordInput.placeholder = 'Enter your MikroTik router password';
                            passwordInput.focus();
                            eyeIcon.className = 'bi bi-eye-slash';
                        }
                    } else {
                        passwordInput.value = '';
                        passwordInput.type = 'text';
                        passwordInput.placeholder = 'Enter your MikroTik router password';
                        passwordInput.focus();
                        eyeIcon.className = 'bi bi-eye-slash';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showAdminErrorAlert('Credential Error', error.message);
                } finally {
                    toggleBtn.disabled = false;
                    toggleBtn.innerHTML = originalBtnContent;
                }
            } else {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.className = 'bi bi-eye-slash';
                } else {
                    passwordInput.value = '••••••••';
                    passwordInput.type = 'password';
                    eyeIcon.className = 'bi bi-eye';
                }
            }
        }
    </script>
    <?php
}
