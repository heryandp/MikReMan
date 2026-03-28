(function () {
    function $(selector) {
        return document.querySelector(selector);
    }

    function getTrialPlaceholder() {
        return [
            'No trial account has been generated yet.',
            '',
            'Submit the form to get:',
            '- username',
            '- password',
            '- expiry time',
            '- public endpoints for Winbox, API, and HTTP'
        ].join('\n');
    }

    function getResultText(trial) {
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

    function downloadSummary(text, requestCode) {
        const prefix = window.ORDER_PAGE_CONFIG?.downloadFilenamePrefix || 'mikreman-trial';
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${prefix}-${requestCode.toLowerCase()}.txt`;
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
        const resetButton = $('#resetOrderButton');
        const submitButton = $('#generateTrialButton');

        let lastTrial = null;
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

        function renderPlaceholder() {
            lastTrial = null;
            requestCodeNode.textContent = 'REQ-PENDING';
            trialServiceNode.textContent = 'Not created';
            trialValidityNode.textContent = '7 days';
            trialMappingsNode.textContent = '3 fixed ports';
            summaryText.textContent = getTrialPlaceholder();
        }

        function renderTrial(trial) {
            lastTrial = trial;
            requestCodeNode.textContent = trial.request_code;
            trialServiceNode.textContent = trial.service;
            trialValidityNode.textContent = trial.expires_label;
            trialMappingsNode.textContent = `${(trial.fixed_ports || []).length} mappings`;
            summaryText.textContent = getResultText(trial);
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

        renderPlaceholder();
        renderStats();

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
                        text: 'Your PPP trial is ready. Copy the account details from the result panel.',
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

            downloadSummary(getResultText(lastTrial), lastTrial.request_code);
            if (window.AppSwal) {
                window.AppSwal.toast('Trial details downloaded.', 'success');
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
