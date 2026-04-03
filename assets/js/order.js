(function () {
    function $(selector) {
        return document.querySelector(selector);
    }

    function getTrialPlaceholder() {
        return [
            'No trial account has been generated yet.',
            '',
            'Submit the form to get:',
            '- service-specific trial details',
            '- expiry time',
            '- public endpoints or WireGuard config'
        ].join('\n');
    }

    function getResultText(trial) {
        if ((trial.service || '').toUpperCase() === 'WIREGUARD') {
            const lines = [
                `Request Code: ${trial.request_code}`,
                `Peer Name: ${trial.username}`,
                `Service: ${trial.service}`,
                `Endpoint: ${trial.endpoint || '-'}`,
                `Client Address: ${trial.remote_address || '-'}`,
                `Interface: ${trial.interface || '-'}`,
                `Expires At: ${trial.expires_label}`,
                ''
            ];

            if (trial.server_public_key) {
                lines.push(`Server Public Key: ${trial.server_public_key}`);
                lines.push('');
            }

            lines.push('Client Config:');
            lines.push(trial.client_config || 'No WireGuard config was returned.');
            lines.push('');
            lines.push('How To Connect:');
            lines.push('1. Copy the full client config below.');
            lines.push('2. Import it into the WireGuard app or your router.');
            lines.push('3. Activate the tunnel and verify the handshake.');

            if (trial.notes) {
                lines.push('');
                lines.push(`Notes: ${trial.notes}`);
            }

            return lines.join('\n');
        }

        const lines = [
            `Request Code: ${trial.request_code}`,
            `Username: ${trial.username}`,
            `Password: ${trial.password}`,
            `Service: ${trial.service}`,
            `VPN Hostname: ${trial.service_host || trial.host || '-'}`,
            `Remote Address: ${trial.remote_address || '-'}`,
            `Expires At: ${trial.expires_label}`,
            ''
        ];

        lines.push('Public Endpoints:');
        (trial.fixed_ports || []).forEach((endpoint) => {
            const endpointText = endpoint.url || endpoint.endpoint || '-';
            lines.push(`- ${endpoint.label}: ${endpointText} -> internal ${endpoint.internal_port}`);
        });

        if (trial.notes) {
            lines.push('');
            lines.push(`Notes: ${trial.notes}`);
        }

        return lines.join('\n');
    }

    function getDownloadContent(trial) {
        if ((trial.service || '').toUpperCase() === 'WIREGUARD') {
            return trial.client_config || '';
        }

        return getResultText(trial);
    }

    async function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }

    function downloadSummary(text, requestCode, service = '', downloadName = '') {
        const prefix = window.ORDER_PAGE_CONFIG?.downloadFilenamePrefix || 'mikreman-trial';
        const extension = String(service || '').toUpperCase() === 'WIREGUARD' ? 'conf' : 'txt';
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        const baseName = String(downloadName || `${prefix}-${requestCode.toLowerCase()}`);
        const safeBaseName = baseName
            .toLowerCase()
            .replace(/[^a-z0-9._-]+/g, '-')
            .replace(/^-+|-+$/g, '') || `${prefix}-${requestCode.toLowerCase()}`;
        link.download = `${safeBaseName}.${extension}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function getTurnstileToken() {
        const input = document.querySelector('[name="cf-turnstile-response"]');
        return input ? input.value.trim() : '';
    }

    function resetTurnstileWidget() {
        if (window.turnstile && typeof window.turnstile.reset === 'function') {
            try {
                window.turnstile.reset();
            } catch (error) {
                console.warn('Failed to reset Turnstile widget.', error);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const form = $('#orderForm');
        const burger = $('#orderNavbarBurger');
        const menu = $('#orderNavbarMenu');
        const summaryText = $('#orderSummaryText');
        const requestCodeNode = $('#orderRequestCode');
        const trialServiceNode = $('#trialService');
        const trialValidityNode = $('#trialValidity');
        const trialMappingsNode = $('#trialMappings');
        const statTotalNode = $('#trialStatTotal');
        const statTodayNode = $('#trialStatToday');
        const statWeekNode = $('#trialStatWeek');
        const statMonthNode = $('#trialStatMonth');
        const copyButton = $('#copyOrderSummaryButton');
        const downloadButton = $('#downloadOrderSummaryButton');
        const downloadButtonLabel = $('#downloadOrderSummaryLabel');
        const resetButton = $('#resetOrderButton');
        const submitButton = $('#generateTrialButton');
        const serviceSelect = $('#orderService');
        const serviceField = $('#orderServiceField');
        const serviceLabel = $('#orderServiceLabel');
        const includedFeatures = $('#orderIncludedFeatures');
        const serviceNoticeText = $('#orderServiceNoticeText');
        const termsText = $('#orderTermsText');
        const guideTitleNode = $('#orderGuideTitle');
        const guideIntroNode = $('#orderGuideIntro');
        const guideStepsNode = $('#orderGuideSteps');
        const formTitleNode = $('#orderFormTitle');
        const formCopyNode = $('#orderFormCopy');
        const orderTabs = document.querySelectorAll('[data-order-tab]');

        let lastTrial = null;
        let activeCategory = 'mikrotik';
        let stats = {
            total: Number(window.ORDER_PAGE_CONFIG?.stats?.total || 0),
            today: Number(window.ORDER_PAGE_CONFIG?.stats?.today || 0),
            week: Number(window.ORDER_PAGE_CONFIG?.stats?.week || 0),
            month: Number(window.ORDER_PAGE_CONFIG?.stats?.month || 0)
        };

        if (burger && menu) {
            burger.addEventListener('click', () => {
                burger.classList.toggle('is-active');
                menu.classList.toggle('is-active');
            });
        }

        if (!form || !summaryText) {
            return;
        }

        function getTabMeta(category) {
            return window.ORDER_PAGE_CONFIG?.tabMeta?.[category] || window.ORDER_PAGE_CONFIG?.tabMeta?.mikrotik || {};
        }

        function getSelectedService() {
            if (activeCategory === 'wireguard') {
                return 'wireguard';
            }

            const selected = serviceSelect?.value || getTabMeta('mikrotik').default_service || 'l2tp';
            return selected === 'wireguard' ? (getTabMeta('mikrotik').default_service || 'l2tp') : selected;
        }

        function renderTabMeta() {
            const meta = getTabMeta(activeCategory);

            if (formTitleNode) {
                formTitleNode.textContent = meta?.title || 'Generate Trial';
            }

            if (formCopyNode) {
                formCopyNode.textContent = meta?.copy || 'Fill the form and submit once.';
            }

            if (serviceLabel) {
                serviceLabel.textContent = meta?.service_label || 'VPN Service';
            }

            if (serviceField) {
                const showServiceField = activeCategory !== 'wireguard';
                serviceField.hidden = !showServiceField;
                serviceField.classList.toggle('is-hidden', !showServiceField);
                serviceField.setAttribute('aria-hidden', showServiceField ? 'false' : 'true');
            }
        }

        function activateCategory(category, updateHash = true) {
            const nextCategory = category === 'wireguard' ? 'wireguard' : 'mikrotik';
            activeCategory = nextCategory;

            orderTabs.forEach((tab) => {
                const isActive = tab.dataset.orderTab === nextCategory;
                const link = tab.querySelector('a');
                tab.classList.toggle('is-active', isActive);
                link?.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            if (nextCategory === 'wireguard') {
                if (serviceSelect) {
                    serviceSelect.value = 'wireguard';
                }
            } else if (serviceSelect && serviceSelect.value === 'wireguard') {
                serviceSelect.value = getTabMeta('mikrotik').default_service || 'l2tp';
            }

            renderTabMeta();
            renderServiceMeta();

            if (!lastTrial) {
                renderPlaceholder();
            }

            if (updateHash) {
                window.history.replaceState(null, '', nextCategory === 'wireguard' ? '#trial-wireguard' : '#trial-mikrotik');
            }
        }

        function renderServiceMeta() {
            const selectedService = getSelectedService();
            const meta = window.ORDER_PAGE_CONFIG?.serviceMeta?.[selectedService] || window.ORDER_PAGE_CONFIG?.serviceMeta?.l2tp;

            if (serviceNoticeText) {
                serviceNoticeText.textContent = meta?.notice || '';
            }

            if (termsText) {
                termsText.textContent = meta?.terms || '';
            }

            if (includedFeatures) {
                includedFeatures.innerHTML = (meta?.features || []).map((feature) => `
                    <span class="tag is-light order-fixed-port"><strong>${feature.label}</strong><span>${feature.value}</span></span>
                `).join('');
            }

            if (guideTitleNode) {
                guideTitleNode.textContent = meta?.guide_title || 'Trial Guide';
            }

            if (guideIntroNode) {
                guideIntroNode.textContent = meta?.guide_intro || 'Use the generated trial details below to connect your device.';
            }

            if (guideStepsNode) {
                guideStepsNode.innerHTML = (meta?.guide_steps || []).map((step) => `<li>${step}</li>`).join('');
            }

            if (!lastTrial && downloadButtonLabel) {
                downloadButtonLabel.textContent = selectedService === 'wireguard' ? 'Download .conf' : 'Download TXT';
            }

            if (!lastTrial) {
                trialServiceNode.textContent = selectedService.toUpperCase();
                trialMappingsNode.textContent = meta?.mappings || 'Service-dependent';
            }
        }

        function renderPlaceholder() {
            lastTrial = null;
            requestCodeNode.textContent = 'REQ-PENDING';
            trialServiceNode.textContent = getSelectedService().toUpperCase();
            trialValidityNode.textContent = '7 days';
            trialMappingsNode.textContent = window.ORDER_PAGE_CONFIG?.serviceMeta?.[getSelectedService()]?.mappings || 'Service-dependent';
            summaryText.textContent = getTrialPlaceholder();
            if (downloadButtonLabel) {
                downloadButtonLabel.textContent = getSelectedService() === 'wireguard' ? 'Download .conf' : 'Download TXT';
            }
        }

        function renderTrial(trial) {
            lastTrial = trial;
            requestCodeNode.textContent = trial.request_code;
            trialServiceNode.textContent = trial.service;
            trialValidityNode.textContent = trial.expires_label;
            trialMappingsNode.textContent = (trial.service || '').toUpperCase() === 'WIREGUARD'
                ? 'Client config export'
                : `${(trial.fixed_ports || []).length} mappings`;
            summaryText.textContent = getResultText(trial);
            if (downloadButtonLabel) {
                downloadButtonLabel.textContent = (trial.service || '').toUpperCase() === 'WIREGUARD' ? 'Download .conf' : 'Download TXT';
            }
        }

        function renderStats() {
            if (statTotalNode) {
                statTotalNode.textContent = String(stats.total);
            }
            if (statTodayNode) {
                statTodayNode.textContent = String(stats.today);
            }
            if (statWeekNode) {
                statWeekNode.textContent = String(stats.week);
            }
            if (statMonthNode) {
                statMonthNode.textContent = String(stats.month);
            }
        }

        async function submitTrial() {
            const payload = {
                full_name: $('#orderFullName')?.value.trim() || '',
                email: $('#orderEmail')?.value.trim() || '',
                service: $('#orderService')?.value || 'l2tp',
                notes: $('#orderNotes')?.value.trim() || '',
                terms_accepted: $('#orderTerms')?.checked || false,
                turnstile_token: getTurnstileToken()
            };

            const response = await fetch(window.ORDER_PAGE_CONFIG?.endpoint || 'api/order.php?action=create_trial', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.ORDER_PAGE_CONFIG?.csrfToken || ''
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json().catch(() => ({
                success: false,
                message: 'Invalid JSON response'
            }));

            if (!response.ok || !result.success) {
                throw new Error(result.message || `HTTP ${response.status}`);
            }

            return result.trial;
        }

        renderServiceMeta();
        renderTabMeta();
        renderStats();

        orderTabs.forEach((tab) => {
            const link = tab.querySelector('a');
            link?.addEventListener('click', (event) => {
                event.preventDefault();
                activateCategory(tab.dataset.orderTab || 'mikrotik');
            });
        });

        const hashCategory = window.location.hash === '#trial-wireguard' ? 'wireguard' : 'mikrotik';
        activateCategory(hashCategory, false);

        serviceSelect?.addEventListener('change', () => {
            renderServiceMeta();
            if (!lastTrial) {
                renderPlaceholder();
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            if (window.ORDER_PAGE_CONFIG?.turnstileEnabled && !getTurnstileToken()) {
                window.AppSwal?.toast('Complete the security check first.', 'warning');
                return;
            }

            submitButton?.classList.add('is-loading');
            submitButton?.setAttribute('disabled', 'disabled');

            try {
                const trial = await submitTrial();
                renderTrial(trial);
                stats.total += 1;
                stats.today += 1;
                stats.week += 1;
                stats.month += 1;
                renderStats();

                if (window.AppSwal) {
                    window.AppSwal.alert({
                        title: 'Trial account created',
                        text: 'Your trial is ready. Copy the account details from the result panel.',
                        icon: 'success'
                    });
                }
            } catch (error) {
                if (window.AppSwal) {
                    window.AppSwal.alert({
                        title: 'Trial request failed',
                        text: error.message,
                        icon: 'error'
                    });
                }
            } finally {
                resetTurnstileWidget();
                submitButton?.classList.remove('is-loading');
                submitButton?.removeAttribute('disabled');
            }
        });

        copyButton?.addEventListener('click', async () => {
            if (!lastTrial) {
                if (window.AppSwal) {
                    window.AppSwal.toast('Generate a trial account first.', 'warning');
                }
                return;
            }

            try {
                await copyText(getResultText(lastTrial));
                if (window.AppSwal) {
                    window.AppSwal.toast('Trial details copied to clipboard.', 'success');
                }
            } catch (error) {
                if (window.AppSwal) {
                    window.AppSwal.toast('Failed to copy trial details.', 'danger');
                }
            }
        });

        downloadButton?.addEventListener('click', () => {
            if (!lastTrial) {
                if (window.AppSwal) {
                    window.AppSwal.toast('Generate a trial account first.', 'warning');
                }
                return;
            }

            if (String(lastTrial.service || '').toUpperCase() === 'WIREGUARD' && !lastTrial.client_config) {
                if (window.AppSwal) {
                    window.AppSwal.toast('WireGuard config is not available yet.', 'warning');
                }
                return;
            }

            downloadSummary(
                getDownloadContent(lastTrial),
                lastTrial.request_code,
                lastTrial.service,
                lastTrial.download_name || ''
            );
            if (window.AppSwal) {
                window.AppSwal.toast(
                    (String(lastTrial.service || '').toUpperCase() === 'WIREGUARD')
                        ? 'WireGuard config downloaded.'
                        : 'Trial details downloaded.',
                    'success'
                );
            }
        });

        resetButton?.addEventListener('click', () => {
            form.reset();
            renderPlaceholder();
            if (window.AppSwal) {
                window.AppSwal.toast('Trial form reset.', 'info');
            }
        });
    });
})();
