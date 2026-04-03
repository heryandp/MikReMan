(function () {
    class WireGuardManager {
        constructor() {
            this.peers = [];
            this.filteredPeers = [];
            this.selectedPeers = new Set();
            this.init();
        }

        init() {
            this.applyDefaultFormValues();
            this.bindEvents();
            this.loadPeers();
        }

        get defaults() {
            return window.WIREGUARD_APP_CONFIG?.defaults || {};
        }

        bindEvents() {
            document.getElementById('wgSearchInput')?.addEventListener('input', () => this.filterPeers());
            document.getElementById('wgStatusFilter')?.addEventListener('change', () => this.filterPeers());
            document.getElementById('wgSelectAll')?.addEventListener('change', (event) => this.handleSelectAll(event));

            document.getElementById('addWireGuardPeerForm')?.addEventListener('submit', (event) => this.handleAddPeer(event));
            document.getElementById('editWireGuardPeerForm')?.addEventListener('submit', (event) => this.handleEditPeer(event));

            document.addEventListener('click', (event) => {
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
                    ['addWireGuardPeerModal', 'editWireGuardPeerModal', 'wireGuardPeerDetailsModal'].forEach((modalId) => this.closeModal(modalId));
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

        applyDefaultFormValues() {
            const keepalive = this.defaults.keepalive || '25';

            const addForm = document.getElementById('addWireGuardPeerForm');
            if (addForm) {
                addForm.reset();
                const keepaliveNode = document.getElementById('wgPeerKeepalive');
                if (keepaliveNode) keepaliveNode.value = keepalive;
            }
        }

        openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) {
                return;
            }

            modal.classList.add('is-active');
            document.documentElement.classList.add('is-clipped');

            if (modalId === 'addWireGuardPeerModal') {
                this.applyDefaultFormValues();
            }
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
                headers: {}
            };

            let url = `../api/mikrotik.php?action=${encodeURIComponent(action)}`;

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

        async loadPeers() {
            const tbody = document.getElementById('wireGuardPeersTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="has-text-centered">
                            <div class="app-empty-state">
                                <div class="empty-icon"><i class="bi bi-hurricane"></i></div>
                                <div class="empty-title">Loading WireGuard peers...</div>
                            </div>
                        </td>
                    </tr>
                `;
            }

            try {
                const result = await this.fetchAPI('get_wireguard_peers');
                this.peers = Array.isArray(result.data) ? result.data : [];
                this.filterPeers();
                this.updateStats(result.stats || null);
            } catch (error) {
                this.peers = [];
                this.filteredPeers = [];
                this.renderPeers();
                this.updateStats(null);
                window.AppSwal?.toast(error.message, 'danger');
            }
        }

        updateStats(stats = null) {
            const source = stats || {
                total: this.peers.length,
                online: this.peers.filter((peer) => peer.online).length,
                offline: this.peers.filter((peer) => !peer.online).length
            };

            const totalNode = document.getElementById('wg-total-peers');
            const onlineNode = document.getElementById('wg-online-peers');
            const offlineNode = document.getElementById('wg-offline-peers');
            if (totalNode) totalNode.textContent = String(source.total || 0);
            if (onlineNode) onlineNode.textContent = String(source.online || 0);
            if (offlineNode) offlineNode.textContent = String(source.offline || 0);
        }

        filterPeers() {
            const search = (document.getElementById('wgSearchInput')?.value || '').trim().toLowerCase();
            const status = (document.getElementById('wgStatusFilter')?.value || '').trim();

            this.filteredPeers = this.peers.filter((peer) => {
                const haystack = [
                    peer.display_name,
                    peer.name,
                    peer.comment,
                    peer.allowed_address,
                    peer.client_address,
                    peer.public_key,
                    peer.current_endpoint
                ].join(' ').toLowerCase();

                if (search && !haystack.includes(search)) {
                    return false;
                }

                if (status === 'online' && !peer.online) {
                    return false;
                }

                if (status === 'offline' && peer.online) {
                    return false;
                }

                if (status === 'disabled' && !peer.disabled) {
                    return false;
                }

                if (status === 'enabled' && peer.disabled) {
                    return false;
                }

                return true;
            });

            this.renderPeers();
        }

        renderPeers() {
            const tbody = document.getElementById('wireGuardPeersTableBody');
            if (!tbody) {
                return;
            }

            if (this.filteredPeers.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="has-text-centered">
                            <div class="app-empty-state">
                                <div class="empty-icon"><i class="bi bi-hurricane"></i></div>
                                <div class="empty-title">No WireGuard peers found</div>
                            </div>
                        </td>
                    </tr>
                `;
                this.updateBulkActions();
                return;
            }

            tbody.innerHTML = this.filteredPeers.map((peer) => {
                const statusClass = peer.disabled
                    ? 'is-danger is-light'
                    : (peer.online ? 'is-success' : 'is-warning is-light');
                const statusLabel = peer.disabled
                    ? 'Disabled'
                    : (peer.online ? 'Online' : 'Offline');

                return `
                    <tr>
                        <td>
                            <input type="checkbox" class="wg-peer-checkbox" data-peer-id="${this.escapeHtml(peer['.id'])}" ${this.selectedPeers.has(peer['.id']) ? 'checked' : ''}>
                        </td>
                        <td>
                            <div class="is-flex is-flex-direction-column">
                                <strong>${this.escapeHtml(peer.display_name)}</strong>
                                <small class="has-text-grey">${this.escapeHtml(peer.comment || peer.interface || '-')}</small>
                            </div>
                        </td>
                        <td class="has-text-centered">${this.escapeHtml(peer.client_address || peer.allowed_address || '-')}</td>
                        <td class="has-text-centered is-hidden-touch">${this.escapeHtml(peer.client_endpoint || '-')}</td>
                        <td class="has-text-centered">${this.escapeHtml(peer.last_handshake_label || 'Never')}</td>
                        <td class="has-text-centered">
                            <span class="tag ppp-state-badge ${statusClass}">${this.escapeHtml(statusLabel)}</span>
                        </td>
                        <td class="has-text-centered is-hidden-touch">${this.escapeHtml(peer.traffic_label || '0 B / 0 B')}</td>
                        <td>
                            <div class="ppp-table-actions">
                                <button class="button is-info is-light is-small" type="button" onclick="wireGuardManager.showPeerDetails('${this.escapeHtml(peer['.id'])}')">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                                <button class="button is-warning is-light is-small" type="button" onclick="wireGuardManager.editPeer('${this.escapeHtml(peer['.id'])}')">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="button is-success is-light is-small" type="button" onclick="wireGuardManager.togglePeerStatus('${this.escapeHtml(peer['.id'])}')">
                                    <i class="bi bi-toggle-${peer.disabled ? 'off' : 'on'}"></i>
                                </button>
                                <button class="button is-danger is-light is-small" type="button" onclick="wireGuardManager.deletePeer('${this.escapeHtml(peer['.id'])}')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            tbody.querySelectorAll('.wg-peer-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', (event) => this.handlePeerSelection(event));
            });

            this.updateBulkActions();
        }

        handleSelectAll(event) {
            const checked = !!event.target.checked;
            document.querySelectorAll('.wg-peer-checkbox').forEach((checkbox) => {
                checkbox.checked = checked;
                if (checked) {
                    this.selectedPeers.add(checkbox.dataset.peerId);
                } else {
                    this.selectedPeers.delete(checkbox.dataset.peerId);
                }
            });

            this.updateBulkActions();
        }

        handlePeerSelection(event) {
            const peerId = event.target.dataset.peerId;
            if (!peerId) {
                return;
            }

            if (event.target.checked) {
                this.selectedPeers.add(peerId);
            } else {
                this.selectedPeers.delete(peerId);
            }

            this.updateBulkActions();
        }

        updateBulkActions() {
            const bar = document.getElementById('wgBulkActions');
            const selectedCount = document.getElementById('wgSelectedCount');
            const selectAll = document.getElementById('wgSelectAll');

            if (selectedCount) {
                selectedCount.textContent = String(this.selectedPeers.size);
            }

            if (bar) {
                bar.style.display = this.selectedPeers.size > 0 ? 'block' : 'none';
            }

            if (selectAll) {
                const visibleIds = this.filteredPeers.map((peer) => peer['.id']);
                const selectedVisible = visibleIds.filter((id) => this.selectedPeers.has(id));
                selectAll.checked = visibleIds.length > 0 && selectedVisible.length === visibleIds.length;
            }
        }

        serializeForm(form) {
            const formData = new FormData(form);
            const payload = {};

            formData.forEach((value, key) => {
                payload[key] = typeof value === 'string' ? value.trim() : value;
            });

            form.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
                payload[checkbox.name] = checkbox.checked;
            });

            payload.interface = this.defaults.interface || 'wireguard1';
            payload.server_address = this.defaults.server_address || '';
            payload.mtu = this.defaults.mtu || '1420';

            return payload;
        }

        async handleAddPeer(event) {
            event.preventDefault();
            const form = event.currentTarget;
            if (!form.reportValidity()) {
                return;
            }

            const payload = this.serializeForm(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton?.classList.add('is-loading');

            try {
                const result = await this.fetchAPI('add_wireguard_peer', payload, 'POST');
                this.closeModal('addWireGuardPeerModal');
                this.applyDefaultFormValues();
                await this.loadPeers();
                window.AppSwal?.toast(result.message || 'WireGuard peer created.', 'success');

                if (result.data?.['.id']) {
                    await this.showPeerDetails(result.data['.id']);
                }
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Create peer failed',
                    text: error.message,
                    icon: 'error'
                });
            } finally {
                submitButton?.classList.remove('is-loading');
            }
        }

        async handleEditPeer(event) {
            event.preventDefault();
            const form = event.currentTarget;
            if (!form.reportValidity()) {
                return;
            }

            const payload = this.serializeForm(form);
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton?.classList.add('is-loading');

            try {
                const result = await this.fetchAPI('edit_wireguard_peer', payload, 'POST');
                this.closeModal('editWireGuardPeerModal');
                await this.loadPeers();
                window.AppSwal?.toast(result.message || 'WireGuard peer updated.', 'success');
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Update peer failed',
                    text: error.message,
                    icon: 'error'
                });
            } finally {
                submitButton?.classList.remove('is-loading');
            }
        }

        async showPeerDetails(peerId) {
            try {
                const result = await this.fetchAPI('get_wireguard_peer_details', { peer_id: peerId }, 'POST');
                const peer = result.data;
                const content = document.getElementById('wireGuardPeerDetailsContent');
                if (!content) {
                    return;
                }

                const statusClass = peer.disabled
                    ? 'is-danger is-light'
                    : (peer.online ? 'is-success' : 'is-warning is-light');
                const statusLabel = peer.disabled ? 'Disabled' : (peer.online ? 'Online' : 'Offline');
                const configId = `wgPeerConfig_${peer['.id'].replace(/[^a-zA-Z0-9]/g, '')}`;
                const serverKeyId = `wgPeerServerKey_${peer['.id'].replace(/[^a-zA-Z0-9]/g, '')}`;

                content.innerHTML = `
                    <div class="ppp-details-grid">
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Peer</span>
                            <strong>${this.escapeHtml(peer.display_name)}</strong>
                        </div>
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Status</span>
                            <span class="tag ppp-state-badge ${statusClass}">${this.escapeHtml(statusLabel)}</span>
                        </div>
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Interface</span>
                            <strong>${this.escapeHtml(peer.interface || '-')}</strong>
                        </div>
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Client Address</span>
                            <strong>${this.escapeHtml(peer.client_address || peer.allowed_address || '-')}</strong>
                        </div>
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Endpoint</span>
                            <strong>${this.escapeHtml(peer.client_endpoint || '-')}</strong>
                        </div>
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Last Handshake</span>
                            <strong>${this.escapeHtml(peer.last_handshake_label || 'Never')}</strong>
                        </div>
                        <div class="ppp-detail-panel">
                            <span class="ppp-detail-label">Traffic</span>
                            <strong>${this.escapeHtml(peer.traffic_label || '0 B / 0 B')}</strong>
                        </div>
                    </div>
                    <div class="content mt-4">
                        <p><strong>Comment:</strong> ${this.escapeHtml(peer.comment || '-')}</p>
                        <p><strong>Current Endpoint:</strong> ${this.escapeHtml(peer.current_endpoint || '-')}</p>
                        <p><strong>Client DNS:</strong> ${this.escapeHtml(peer.client_dns || '-')}</p>
                        <p><strong>Allowed IPs:</strong> ${this.escapeHtml(peer.client_allowed_address || '-')}</p>
                    </div>
                    <div class="ppp-code-shell mb-4">
                        <label class="ppp-detail-label mb-2">Server Public Key</label>
                        <textarea class="textarea admin-input ppp-code-textarea" id="${serverKeyId}" rows="2" readonly>${this.escapeHtml(peer.server_public_key || '-')}</textarea>
                        <div class="buttons is-justify-content-flex-end mt-3">
                            <button type="button" class="button is-link is-light is-small" id="${serverKeyId}_copy">
                                <span class="icon"><i class="bi bi-clipboard-check"></i></span>
                                <span>Copy Server Key</span>
                            </button>
                        </div>
                    </div>
                    <div class="ppp-code-shell">
                        <textarea class="textarea admin-input ppp-code-textarea" id="${configId}" rows="14" readonly>${this.escapeHtml(peer.client_config || 'Client config is not available.')}</textarea>
                        <div class="buttons is-justify-content-flex-end mt-3">
                            <button type="button" class="button is-success is-light is-small" id="${configId}_copy">
                                <span class="icon"><i class="bi bi-clipboard-check"></i></span>
                                <span>Copy Config</span>
                            </button>
                            <button type="button" class="button is-link is-light is-small" id="${configId}_download">
                                <span class="icon"><i class="bi bi-download"></i></span>
                                <span>Download .conf</span>
                            </button>
                        </div>
                    </div>
                `;

                content.querySelector(`#${serverKeyId}_copy`)?.addEventListener('click', () => this.copyTextAreaValue(serverKeyId, 'Server public key copied.'));
                content.querySelector(`#${configId}_copy`)?.addEventListener('click', () => this.copyPeerConfig(configId));
                content.querySelector(`#${configId}_download`)?.addEventListener('click', () => this.downloadPeerConfig(peer));
                this.openModal('wireGuardPeerDetailsModal');
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Load peer details failed',
                    text: error.message,
                    icon: 'error'
                });
            }
        }

        async editPeer(peerId) {
            const peer = this.peers.find((entry) => entry['.id'] === peerId);
            if (!peer) {
                window.AppSwal?.toast('WireGuard peer not found in current list.', 'warning');
                return;
            }

            document.getElementById('editWgPeerId').value = peer['.id'] || '';
            document.getElementById('editWgPeerName').value = peer.name || peer.display_name || '';
            document.getElementById('editWgPeerAllowedAddress').value = peer.allowed_address || peer.client_address || '';
            document.getElementById('editWgPeerKeepalive').value = peer.persistent_keepalive || (this.defaults.keepalive || '25');
            document.getElementById('editWgPeerComment').value = peer.comment || '';
            document.getElementById('editWgPeerDisabled').checked = !!peer.disabled;
            document.getElementById('editWgPeerRegenerateKey').checked = false;
            this.openModal('editWireGuardPeerModal');
        }

        async togglePeerStatus(peerId) {
            const peer = this.peers.find((entry) => entry['.id'] === peerId);
            if (!peer) {
                return;
            }

            try {
                const result = await this.fetchAPI('toggle_wireguard_peer_status', { peer_id: peerId }, 'POST');
                await this.loadPeers();
                window.AppSwal?.toast(result.message || 'Peer status updated.', 'success');
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Toggle peer failed',
                    text: error.message,
                    icon: 'error'
                });
            }
        }

        async deletePeer(peerId) {
            const peer = this.peers.find((entry) => entry['.id'] === peerId);
            if (!peer) {
                return;
            }

            const confirmed = await window.AppSwal?.confirm({
                title: 'Delete WireGuard Peer?',
                text: `Delete peer ${peer.display_name}? This removes the peer from RouterOS and invalidates the client config.`,
                confirmButtonText: 'Delete Peer',
                icon: 'warning'
            });

            if (!confirmed) {
                return;
            }

            try {
                const result = await this.fetchAPI('delete_wireguard_peer', { peer_id: peerId }, 'POST');
                this.selectedPeers.delete(peerId);
                await this.loadPeers();
                window.AppSwal?.toast(result.message || 'Peer deleted.', 'success');
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Delete peer failed',
                    text: error.message,
                    icon: 'error'
                });
            }
        }

        async bulkDeletePeers() {
            const ids = Array.from(this.selectedPeers);
            if (ids.length === 0) {
                window.AppSwal?.toast('Select one or more peers first.', 'warning');
                return;
            }

            const confirmed = await window.AppSwal?.confirm({
                title: 'Delete Selected Peers?',
                text: `Delete ${ids.length} WireGuard peer(s)?`,
                confirmButtonText: 'Delete Selected',
                icon: 'warning'
            });

            if (!confirmed) {
                return;
            }

            try {
                const result = await this.fetchAPI('bulk_delete_wireguard_peers', { peer_ids: ids }, 'POST');
                this.selectedPeers.clear();
                await this.loadPeers();
                window.AppSwal?.toast(result.message || 'Selected peers deleted.', 'success');
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Bulk delete failed',
                    text: error.message,
                    icon: 'error'
                });
            }
        }

        async bulkTogglePeers() {
            const ids = Array.from(this.selectedPeers);
            if (ids.length === 0) {
                window.AppSwal?.toast('Select one or more peers first.', 'warning');
                return;
            }

            try {
                const result = await this.fetchAPI('bulk_toggle_wireguard_peers', { peer_ids: ids }, 'POST');
                await this.loadPeers();
                window.AppSwal?.toast(result.message || 'Selected peers updated.', 'success');
            } catch (error) {
                window.AppSwal?.alert({
                    title: 'Bulk toggle failed',
                    text: error.message,
                    icon: 'error'
                });
            }
        }

        async copyTextAreaValue(textareaId, successMessage = 'Copied to clipboard.') {
            const textarea = document.getElementById(textareaId);
            if (!textarea) {
                return;
            }

            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(textarea.value);
                } else {
                    textarea.select();
                    document.execCommand('copy');
                }
                window.AppSwal?.toast(successMessage, 'success');
            } catch (error) {
                window.AppSwal?.toast('Failed to copy.', 'danger');
            }
        }

        async copyPeerConfig(textareaId) {
            return this.copyTextAreaValue(textareaId, 'WireGuard config copied to clipboard.');
        }

        downloadPeerConfig(peer) {
            const config = peer?.client_config || '';
            if (!config) {
                window.AppSwal?.toast('WireGuard config is not available yet.', 'warning');
                return;
            }

            const safeName = String(peer.download_name || peer.display_name || peer.name || 'wireguard-peer')
                .toLowerCase()
                .replace(/[^a-z0-9._-]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'wireguard-peer';

            const blob = new Blob([config], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${safeName}.conf`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            window.AppSwal?.toast('WireGuard config downloaded.', 'success');
        }

        resetFilters() {
            const searchNode = document.getElementById('wgSearchInput');
            const statusNode = document.getElementById('wgStatusFilter');
            if (searchNode) searchNode.value = '';
            if (statusNode) statusNode.value = '';
            this.filterPeers();
        }

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    }

    let wireGuardManager;

    document.addEventListener('DOMContentLoaded', () => {
        wireGuardManager = new WireGuardManager();
        window.wireGuardManager = wireGuardManager;
    });

    window.clearWireGuardFilters = function () {
        wireGuardManager?.resetFilters();
    };

    window.bulkDeleteWireGuardPeers = function () {
        wireGuardManager?.bulkDeletePeers();
    };

    window.bulkToggleWireGuardPeers = function () {
        wireGuardManager?.bulkTogglePeers();
    };
})();
