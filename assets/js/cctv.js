(function () {
    class CCTVManager {
        constructor() {
            this.apiUrl = '../api/cctv.php';
            this.overview = window.CCTV_APP_CONFIG?.overview || null;
            this.init();
        }

        init() {
            this.bindEvents();
            this.bindTabs();

            if (this.overview) {
                this.renderOverview(this.overview);
            }

            this.loadOverview();
        }

        getCsrfHeaders(additionalHeaders = {}) {
            return {
                'X-CSRF-Token': window.CCTV_APP_CONFIG?.csrfToken || '',
                ...additionalHeaders
            };
        }

        bindEvents() {
            document.getElementById('addCctvStreamForm')?.addEventListener('submit', (event) => this.handleAddStream(event));
            document.getElementById('editCctvStreamForm')?.addEventListener('submit', (event) => this.handleEditStream(event));
            document.getElementById('youtubeRestreamForm')?.addEventListener('submit', (event) => this.handleSaveYoutubeRestream(event));
            document.getElementById('cctvYoutubeSource')?.addEventListener('change', (event) => this.handleYoutubeSourceChange(event));

            document.addEventListener('click', (event) => {
                const actionTarget = event.target.closest('[data-cctv-action]');
                if (actionTarget) {
                    const action = actionTarget.getAttribute('data-cctv-action');
                    if (action === 'edit-stream') {
                        this.editStream(actionTarget.getAttribute('data-stream-name') || '');
                        return;
                    }
                    if (action === 'delete-stream') {
                        this.deleteStream(actionTarget.getAttribute('data-stream-name') || '');
                        return;
                    }
                    if (action === 'delete-youtube') {
                        this.deleteYoutubeRestream(actionTarget.getAttribute('data-youtube-alias') || '');
                        return;
                    }
                }

                const openTarget = event.target.closest('[data-open-modal]');
                if (openTarget) {
                    this.openModal(openTarget.getAttribute('data-open-modal'));
                    return;
                }

                const closeTarget = event.target.closest('[data-close-modal]');
                if (closeTarget) {
                    this.closeModal(closeTarget.getAttribute('data-close-modal'));
                    return;
                }

                if (event.target.classList.contains('delete')) {
                    const modal = event.target.closest('.modal');
                    if (modal?.id) {
                        this.closeModal(modal.id);
                    }
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    ['addCctvStreamModal', 'editCctvStreamModal'].forEach((modalId) => this.closeModal(modalId));
                }
            });

            const topNavbarBurger = document.getElementById('topNavbarBurger');
            const topNavbarMenu = document.getElementById('topNavbarMenu');
            if (topNavbarBurger && topNavbarMenu) {
                topNavbarBurger.addEventListener('click', () => {
                    const isActive = topNavbarMenu.classList.toggle('is-active');
                    topNavbarBurger.classList.toggle('is-active', isActive);
                    topNavbarBurger.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                });
            }
        }

        bindTabs() {
            const tabs = document.querySelectorAll('[data-cctv-tab]');
            if (!tabs.length) {
                return;
            }

            tabs.forEach((tab) => {
                const link = tab.querySelector('a');
                link?.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.activateTab(tab.dataset.cctvTab);
                });
            });

            const hashMatch = window.location.hash.match(/^#cctv-tab-(.+)$/);
            const requestedTab = hashMatch?.[1];
            const initialTab = Array.from(tabs).some((tab) => tab.dataset.cctvTab === requestedTab)
                ? requestedTab
                : tabs[0].dataset.cctvTab;

            this.activateTab(initialTab, false);
        }

        activateTab(tabName, updateHash = true) {
            const tabs = document.querySelectorAll('[data-cctv-tab]');
            const panels = document.querySelectorAll('[data-cctv-panel]');

            if (!tabName || !tabs.length || !panels.length) {
                return;
            }

            let hasMatch = false;

            tabs.forEach((tab) => {
                const isActive = tab.dataset.cctvTab === tabName;
                const link = tab.querySelector('a');

                tab.classList.toggle('is-active', isActive);
                link?.setAttribute('aria-selected', isActive ? 'true' : 'false');

                if (isActive) {
                    hasMatch = true;
                }
            });

            panels.forEach((panel) => {
                const isActive = panel.dataset.cctvPanel === tabName;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });

            if (hasMatch && updateHash) {
                window.history.replaceState(null, '', `#cctv-tab-${tabName}`);
            }
        }

        openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) {
                return;
            }

            modal.classList.add('is-active');
            document.documentElement.classList.add('is-clipped');
        }

        closeModal(modalId) {
            document.getElementById(modalId)?.classList.remove('is-active');

            if (!document.querySelector('.modal.is-active')) {
                document.documentElement.classList.remove('is-clipped');
            }
        }

        async fetchAPI(action, data = null, method = 'GET') {
            const options = {
                method,
                headers: this.getCsrfHeaders()
            };

            let url = `${this.apiUrl}?action=${encodeURIComponent(action)}`;

            if (method === 'GET' && data && typeof data === 'object') {
                Object.entries(data).forEach(([key, value]) => {
                    url += `&${encodeURIComponent(key)}=${encodeURIComponent(value ?? '')}`;
                });
            }

            if (method !== 'GET') {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify({
                    action,
                    ...(data || {})
                });
            }

            const response = await fetch(url, options);
            const result = await response.json().catch(() => ({
                success: false,
                message: 'Invalid JSON response'
            }));

            if (!response.ok || !result.success) {
                throw new Error(result.message || `HTTP ${response.status}`);
            }

            return result;
        }

        async loadOverview() {
            try {
                const result = await this.fetchAPI('overview');
                this.renderOverview(result.data || null);
            } catch (error) {
                this.showAlert(error.message, 'danger');
            }
        }

        renderOverview(overview) {
            this.overview = overview || null;
            const data = overview || {};
            const details = data.details || {};
            const streams = Array.isArray(data.streams) ? data.streams : [];
            const youtubeRestreams = Array.isArray(data.youtube_restreams) ? data.youtube_restreams : [];
            const sourceStreams = this.filterSourceStreams(streams, youtubeRestreams);

            const statusNode = document.getElementById('cctv-service-status');
            const countNode = document.getElementById('cctv-stream-count');
            const rtspPortNode = document.getElementById('cctv-rtsp-port');
            const endpointModeNode = document.getElementById('cctv-endpoint-mode');
            const apiTargetNode = document.getElementById('cctv-api-target');
            const apiProbeNode = document.getElementById('cctv-api-probe');
            const webUrlNode = document.getElementById('cctv-web-url');
            const rtspTemplateNode = document.getElementById('cctv-rtsp-template');
            const webrtcUrlNode = document.getElementById('cctv-webrtc-url');
            const configPathNode = document.getElementById('cctv-config-path');
            const versionNode = document.getElementById('cctv-version');
            const configNode = document.getElementById('cctv-config-text');
            const warningNode = document.getElementById('cctv-warning');

            if (statusNode) {
                statusNode.textContent = data.online ? 'Online' : 'Offline';
                statusNode.classList.toggle('has-text-success', !!data.online);
                statusNode.classList.toggle('has-text-danger', !data.online);
            }
            if (countNode) countNode.textContent = String(data.stream_count || 0);
            if (rtspPortNode) rtspPortNode.textContent = String(details.rtsp_port || '-');
            if (endpointModeNode) endpointModeNode.textContent = String(details.endpoint_mode || 'Auto');
            if (apiTargetNode) apiTargetNode.textContent = String(details.api_target || '-');
            if (apiProbeNode) apiProbeNode.textContent = String(details.api_probe || '-');
            if (webUrlNode) {
                webUrlNode.textContent = String(details.web_url || '-');
                webUrlNode.href = String(details.web_url || '#');
            }
            if (rtspTemplateNode) rtspTemplateNode.textContent = String(details.rtsp_url_template || '-');
            if (webrtcUrlNode) {
                webrtcUrlNode.textContent = String(details.webrtc_url || '-');
                webrtcUrlNode.href = String(details.webrtc_url || '#');
            }
            if (configPathNode) configPathNode.textContent = String(details.config_path || '-');
            if (versionNode) versionNode.textContent = String(details.version || '-');
            if (configNode) configNode.textContent = String(data.config_text || 'Configuration is unavailable.');

            if (warningNode) {
                warningNode.classList.toggle('is-hidden', !!data.online);
            }

            this.renderStreams(sourceStreams, details);
            this.renderYoutubeSourceOptions(sourceStreams);
            this.renderYoutubeRestreams(youtubeRestreams);
        }

        renderStreams(streams, details) {
            const tbody = document.getElementById('cctvStreamsTableBody');
            if (!tbody) {
                return;
            }

            if (!Array.isArray(streams) || streams.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="has-text-centered">
                            <div class="app-empty-state">
                                <span class="icon"><i class="bi bi-camera-video-off has-text-grey-light"></i></span>
                                <p>No source aliases configured yet in go2rtc.</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = streams.map((stream) => {
                const consumerCount = Number(stream.consumer_count || 0);
                const statusTag = stream.online
                    ? '<span class="tag is-success is-light ppp-state-badge">Online</span>'
                    : '<span class="tag is-warning is-light ppp-state-badge">Idle</span>';
                const watchUrl = `${details.web_url || ''}stream.html?src=${encodeURIComponent(stream.name || '')}`;

                return `
                    <tr>
                        <td>
                            <div class="is-flex is-flex-direction-column">
                                <strong>${this.escapeHtml(stream.name || '')}</strong>
                                <small class="has-text-grey">${statusTag}</small>
                            </div>
                        </td>
                        <td><code>${this.escapeHtml(stream.source_url || '-')}</code></td>
                        <td><code>${this.escapeHtml(stream.relay_url || '-')}</code></td>
                        <td class="has-text-centered">${consumerCount}</td>
                        <td class="has-text-centered">${stream.online ? 'Live source connected' : 'Alias saved only'}</td>
                        <td>
                            <div class="ppp-table-actions">
                                <a class="button is-info is-light is-small" href="${this.escapeHtml(watchUrl)}" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <button class="button is-warning is-light is-small" type="button" data-cctv-action="edit-stream" data-stream-name="${this.escapeHtml(stream.name || '')}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="button is-danger is-light is-small" type="button" data-cctv-action="delete-stream" data-stream-name="${this.escapeHtml(stream.name || '')}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        renderYoutubeSourceOptions(streams) {
            const select = document.getElementById('cctvYoutubeSource');
            if (!select) {
                return;
            }

            const currentValue = select.value;
            const options = ['<option value="">Select a source alias</option>'];

            if (Array.isArray(streams)) {
                streams.forEach((stream) => {
                    const name = String(stream?.name || '').trim();
                    if (!name) {
                        return;
                    }

                    const selected = name === currentValue ? ' selected' : '';
                    options.push(`<option value="${this.escapeHtml(name)}"${selected}>${this.escapeHtml(name)}</option>`);
                });
            }

            select.innerHTML = options.join('');
        }

        renderYoutubeRestreams(restreams) {
            const container = document.getElementById('cctvYoutubeRestreamList');
            if (!container) {
                return;
            }

            if (!Array.isArray(restreams) || restreams.length === 0) {
                container.innerHTML = `
                    <div class="app-empty-state">
                        <span class="icon"><i class="bi bi-youtube has-text-grey-light"></i></span>
                        <p>No YouTube publish targets configured yet.</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = restreams.map((restream) => `
                <div class="notification is-light mb-3">
                    <div><strong>${this.escapeHtml(restream.alias || '')}</strong></div>
                    <div><small class="has-text-grey">Source Alias: ${this.escapeHtml(restream.source_name || '-')}</small></div>
                    <div><small class="has-text-grey">Publish Target: ${this.escapeHtml(this.maskYoutubeDestination(restream.destination || '-'))}</small></div>
                    <div class="buttons mt-3">
                        <button class="button is-danger is-light is-small" type="button" data-cctv-action="delete-youtube" data-youtube-alias="${this.escapeHtml(restream.alias || '')}">
                            <i class="bi bi-trash"></i>
                            <span>Remove</span>
                        </button>
                    </div>
                </div>
            `).join('');
        }

        filterSourceStreams(streams, youtubeRestreams) {
            const youtubeAliases = new Set(
                (Array.isArray(youtubeRestreams) ? youtubeRestreams : [])
                    .map((restream) => String(restream?.alias || '').trim())
                    .filter(Boolean)
            );

            return (Array.isArray(streams) ? streams : []).filter((stream) => {
                const name = String(stream?.name || '').trim();
                return name && !youtubeAliases.has(name);
            });
        }

        async handleAddStream(event) {
            event.preventDefault();
            const form = event.currentTarget;
            const sourceValue = this.resolveStreamSource(form);
            if (!form.reportValidity() || !sourceValue) {
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton?.classList.add('is-loading');

            try {
                const result = await this.fetchAPI('save_stream', {
                    name: form.elements.name.value.trim(),
                    src: sourceValue
                }, 'POST');

                this.renderOverview(result.overview || null);
                form.reset();
                this.closeModal('addCctvStreamModal');
                this.showAlert(result.data?.warning || result.message || 'Source alias saved successfully.', result.data?.warning ? 'warning' : 'success');
            } catch (error) {
                this.showModalError('Create source alias failed', error.message);
            } finally {
                submitButton?.classList.remove('is-loading');
            }
        }

        async editStream(name) {
            try {
                const result = await this.fetchAPI('get_stream', { name }, 'GET');
                const stream = result.data || {};
                const sourceValue = String(stream.source_url || '').trim();
                const isPlainUrl = this.isPlainSourceUrl(sourceValue);

                document.getElementById('editCctvOldName').value = stream.name || name || '';
                document.getElementById('editCctvStreamName').value = stream.name || name || '';
                document.getElementById('editCctvStreamSource').value = isPlainUrl ? sourceValue : '';
                document.getElementById('editCctvStreamSourceAdvanced').value = isPlainUrl ? '' : sourceValue;
                this.openModal('editCctvStreamModal');
            } catch (error) {
                this.showModalError('Load source alias failed', error.message);
            }
        }

        async handleEditStream(event) {
            event.preventDefault();
            const form = event.currentTarget;
            const sourceValue = this.resolveStreamSource(form);
            if (!form.reportValidity() || !sourceValue) {
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton?.classList.add('is-loading');

            try {
                const result = await this.fetchAPI('save_stream', {
                    old_name: form.elements.old_name.value.trim(),
                    name: form.elements.name.value.trim(),
                    src: sourceValue
                }, 'POST');

                this.renderOverview(result.overview || null);
                this.closeModal('editCctvStreamModal');
                this.showAlert(result.data?.warning || result.message || 'Source alias updated successfully.', result.data?.warning ? 'warning' : 'success');
            } catch (error) {
                this.showModalError('Update source alias failed', error.message);
            } finally {
                submitButton?.classList.remove('is-loading');
            }
        }

        handleYoutubeSourceChange(event) {
            const sourceName = String(event?.target?.value || '').trim();
            const aliasField = document.getElementById('cctvYoutubeAlias');
            if (!aliasField || !sourceName || aliasField.value.trim() !== '') {
                return;
            }

            aliasField.value = `${this.slugify(sourceName)}-youtube`;
        }

        async handleSaveYoutubeRestream(event) {
            event.preventDefault();
            const form = event.currentTarget;
            if (!form.reportValidity()) {
                return;
            }

            const sourceName = form.elements.source_name.value.trim();
            const alias = form.elements.alias.value.trim();
            const profile = String(form.elements.source_profile?.value || 'cloudsave-medium').trim();
            if (sourceName && alias && sourceName.toLowerCase() === alias.toLowerCase()) {
                this.showModalError('Save YouTube restream failed', 'Publish alias must be different from the source stream name.');
                return;
            }

            const sourceExpression = this.resolveYoutubeSourceExpression(
                sourceName,
                profile,
                form.elements.source_expression?.value || ''
            );

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton?.classList.add('is-loading');

            try {
                const result = await this.fetchAPI('save_youtube_restream', {
                    source_name: sourceName,
                    alias,
                    ingest_url: form.elements.ingest_url.value.trim(),
                    stream_key: form.elements.stream_key.value.trim(),
                    source_expression: sourceExpression
                }, 'POST');

                this.renderOverview(result.overview || null);
                form.elements.stream_key.value = '';
                this.showAlert(result.message || 'YouTube restream saved successfully.', 'success');
            } catch (error) {
                this.showModalError('Save YouTube restream failed', error.message);
            } finally {
                submitButton?.classList.remove('is-loading');
            }
        }

        async deleteStream(name) {
            const confirmed = await (window.AppSwal
                ? window.AppSwal.confirm({
                    title: 'Delete Stream?',
                    text: `Delete source alias ${name}?`,
                    confirmButtonText: 'Delete Alias',
                    icon: 'warning'
                })
                : Promise.resolve(confirm(`Delete source alias ${name}?`)));

            if (!confirmed) {
                return;
            }

            try {
                const result = await this.fetchAPI('delete_stream', { name }, 'POST');
                this.renderOverview(result.overview || null);
                const removedPublishAliases = Array.isArray(result.data?.removed_publish_aliases)
                    ? result.data.removed_publish_aliases
                    : [];
                const message = removedPublishAliases.length > 0
                    ? `${result.message || 'Source alias deleted.'} Removed publish aliases: ${removedPublishAliases.join(', ')}`
                    : (result.message || 'Source alias deleted successfully.');
                this.showAlert(message, removedPublishAliases.length > 0 ? 'warning' : 'success');
            } catch (error) {
                this.showModalError('Delete source alias failed', error.message);
            }
        }

        async deleteYoutubeRestream(alias) {
            const confirmed = await (window.AppSwal
                ? window.AppSwal.confirm({
                    title: 'Remove YouTube Restream?',
                    text: `Remove YouTube restream ${alias}?`,
                    confirmButtonText: 'Remove Restream',
                    icon: 'warning'
                })
                : Promise.resolve(confirm(`Remove YouTube restream ${alias}?`)));

            if (!confirmed) {
                return;
            }

            try {
                const result = await this.fetchAPI('delete_youtube_restream', { alias }, 'POST');
                this.renderOverview(result.overview || null);
                this.showAlert(result.message || 'YouTube restream removed successfully.', 'success');
            } catch (error) {
                this.showModalError('Delete YouTube restream failed', error.message);
            }
        }

        showModalError(title, text) {
            if (window.AppSwal) {
                window.AppSwal.alert({ title, text, icon: 'error' });
                return;
            }

            alert(`${title}: ${text}`);
        }

        showAlert(message, type = 'info') {
            if (window.AppSwal) {
                window.AppSwal.toast(message, type);
                return;
            }

            const container = document.getElementById('alerts-container');
            if (!container) {
                return;
            }

            const alertId = `cctv-alert-${Date.now()}`;
            container.insertAdjacentHTML('beforeend', `
                <div class="notification ${type === 'success' ? 'is-success' : type === 'warning' ? 'is-warning' : 'is-danger'} admin-notification fade-in" id="${alertId}">
                    <button type="button" class="delete" aria-label="Close"></button>
                    ${this.escapeHtml(message)}
                </div>
            `);

            setTimeout(() => document.getElementById(alertId)?.remove(), 5000);
        }

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        resolveStreamSource(form) {
            const advancedValue = String(form?.elements?.src_advanced?.value || '').trim();
            const basicValue = String(form?.elements?.src?.value || '').trim();
            const sourceValue = advancedValue || basicValue;

            if (!sourceValue) {
                this.showModalError('Save source alias failed', 'Please provide an upstream camera URL or an advanced go2rtc source expression.');
                return '';
            }

            return sourceValue;
        }

        buildYoutubeRawExpression(sourceName, options = {}) {
            const alias = String(sourceName || '').trim();
            if (!alias) {
                return '';
            }

            const bitrate = String(options.videoBitrate || '1400k').trim();
            const maxrate = String(options.maxrate || bitrate).trim();
            const bufsize = String(options.bufsize || '2800k').trim();
            const audioBitrate = String(options.audioBitrate || '128k').trim();
            const gop = String(options.gop || '20').trim();
            const preset = String(options.preset || 'veryfast').trim();

            return `ffmpeg:${alias}#raw=-c:v libx264 -preset ${preset} -tune zerolatency -pix_fmt yuv420p -g ${gop} -keyint_min ${gop} -sc_threshold 0 -profile:v high -level:v 4.1 -b:v ${bitrate} -maxrate ${maxrate} -bufsize ${bufsize} -c:a aac -ar 48000 -b:a ${audioBitrate} -ac 2`;
        }

        resolveYoutubeSourceExpression(sourceName, profile, customValue) {
            const customExpression = String(customValue || '').trim();
            if (customExpression) {
                return customExpression;
            }

            const alias = String(sourceName || '').trim();
            if (!alias) {
                return '';
            }

            if (profile === 'relay-copy') {
                return `ffmpeg:${alias}#video=copy#audio=aac`;
            }

            if (profile === 'relay-transcode') {
                return this.buildYoutubeRawExpression(alias, {
                    videoBitrate: '1800k',
                    maxrate: '1800k',
                    bufsize: '3600k',
                    audioBitrate: '128k',
                    gop: '20',
                    preset: 'veryfast'
                });
            }

            if (profile === 'cloudsave-low') {
                return this.buildYoutubeRawExpression(alias, {
                    videoBitrate: '900k',
                    maxrate: '900k',
                    bufsize: '1800k',
                    audioBitrate: '96k',
                    gop: '20',
                    preset: 'veryfast'
                });
            }

            if (profile === 'cloudsave-medium') {
                return this.buildYoutubeRawExpression(alias, {
                    videoBitrate: '1400k',
                    maxrate: '1400k',
                    bufsize: '2800k',
                    audioBitrate: '128k',
                    gop: '20',
                    preset: 'veryfast'
                });
            }

            return this.buildYoutubeRawExpression(alias, {
                videoBitrate: '1400k',
                maxrate: '1400k',
                bufsize: '2800k',
                audioBitrate: '128k',
                gop: '20',
                preset: 'veryfast'
            });
        }

        isPlainSourceUrl(value) {
            const source = String(value ?? '').trim();
            if (!source) {
                return false;
            }

            if (source.includes('\n') || source.includes('\r') || source.includes('#')) {
                return false;
            }

            return /^(rtsp|rtsps|http|https):\/\//i.test(source);
        }

        slugify(value) {
            return String(value ?? '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'youtube-stream';
        }

        maskYoutubeDestination(value) {
            const destination = String(value ?? '').trim();
            const parts = destination.split('/');
            if (parts.length < 2) {
                return destination;
            }

            const key = parts.pop() || '';
            const maskedKey = key.length > 6
                ? `${key.slice(0, 3)}...${key.slice(-3)}`
                : '***';

            parts.push(maskedKey);
            return parts.join('/');
        }
    }

    let cctvManager;

    document.addEventListener('DOMContentLoaded', () => {
        cctvManager = new CCTVManager();
        window.cctvManager = cctvManager;
    });
})();
