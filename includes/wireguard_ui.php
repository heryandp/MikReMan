<?php

function renderWireGuardModals(): void
{
    ?>
    <div class="modal" id="addWireGuardPeerModal" role="dialog" aria-modal="true" aria-labelledby="addWireGuardPeerModalTitle">
        <div class="modal-background" data-close-modal="addWireGuardPeerModal"></div>
        <form class="modal-card app-modal-card is-modal-medium" id="addWireGuardPeerForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="addWireGuardPeerModalTitle">
                    <span class="icon"><i class="bi bi-hurricane" aria-hidden="true"></i></span>
                    <span>Add WireGuard Peer</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="addWireGuardPeerModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <div class="columns is-multiline is-variable is-4">
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="wgPeerName" class="label admin-label">Peer Name</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="wgPeerName" name="name" maxlength="64" required>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label class="label admin-label">Client Address</label>
                            <div class="control">
                                <input type="text" class="input admin-input" value="Auto assigned from WireGuard subnet" readonly>
                            </div>
                            <p class="help has-text-grey-light">MikReMan allocates the next free /32 address automatically from the configured WireGuard subnet.</p>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="wgPeerKeepalive" class="label admin-label">Persistent Keepalive</label>
                            <div class="control">
                                <input type="number" class="input admin-input" id="wgPeerKeepalive" name="persistent_keepalive" min="0" max="65535" placeholder="25">
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <label for="wgPeerComment" class="label admin-label">Comment</label>
                            <div class="control">
                                <textarea class="textarea admin-input" id="wgPeerComment" name="comment" rows="3" maxlength="200" placeholder="Optional note for this peer."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <p class="help has-text-grey-light">Client DNS and Allowed IPs follow the global WireGuard defaults from the Admin page.</p>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="wgPeerDisabled">
                                    <input type="checkbox" id="wgPeerDisabled" name="disabled">
                                    <span>Create peer in disabled state</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="addWireGuardPeerModal">Cancel</button>
                <button type="submit" class="button is-primary admin-action-button">
                    <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                    <span>Create Peer</span>
                </button>
            </footer>
        </form>
    </div>

    <div class="modal" id="editWireGuardPeerModal" role="dialog" aria-modal="true" aria-labelledby="editWireGuardPeerModalTitle">
        <div class="modal-background" data-close-modal="editWireGuardPeerModal"></div>
        <form class="modal-card app-modal-card is-modal-medium" id="editWireGuardPeerForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="editWireGuardPeerModalTitle">
                    <span class="icon"><i class="bi bi-pencil" aria-hidden="true"></i></span>
                    <span>Edit WireGuard Peer</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="editWireGuardPeerModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <input type="hidden" id="editWgPeerId" name="peer_id">
                <div class="columns is-multiline is-variable is-4">
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="editWgPeerName" class="label admin-label">Peer Name</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="editWgPeerName" name="name" maxlength="64" required>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="editWgPeerAllowedAddress" class="label admin-label">Client Address</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="editWgPeerAllowedAddress" name="allowed_address" placeholder="10.66.66.2/32" readonly>
                            </div>
                            <p class="help has-text-grey-light">WireGuard peer addresses are auto assigned and kept stable unless the peer is recreated.</p>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="editWgPeerKeepalive" class="label admin-label">Persistent Keepalive</label>
                            <div class="control">
                                <input type="number" class="input admin-input" id="editWgPeerKeepalive" name="persistent_keepalive" min="0" max="65535" placeholder="25">
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <label for="editWgPeerComment" class="label admin-label">Comment</label>
                            <div class="control">
                                <textarea class="textarea admin-input" id="editWgPeerComment" name="comment" rows="3" maxlength="200" placeholder="Optional note for this peer."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <p class="help has-text-grey-light">Client DNS and Allowed IPs follow the global WireGuard defaults from the Admin page.</p>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="editWgPeerDisabled">
                                    <input type="checkbox" id="editWgPeerDisabled" name="disabled">
                                    <span>Disable peer</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="editWgPeerRegenerateKey">
                                    <input type="checkbox" id="editWgPeerRegenerateKey" name="regenerate_private_key">
                                    <span>Regenerate client private key</span>
                                </label>
                            </div>
                            <p class="help has-text-grey-light">Enable only when you intentionally want a new client config.</p>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="editWireGuardPeerModal">Cancel</button>
                <button type="submit" class="button is-warning admin-action-button">
                    <span class="icon"><i class="bi bi-pencil" aria-hidden="true"></i></span>
                    <span>Update Peer</span>
                </button>
            </footer>
        </form>
    </div>

    <div class="modal" id="wireGuardPeerDetailsModal" role="dialog" aria-modal="true" aria-labelledby="wireGuardPeerDetailsModalTitle">
        <div class="modal-background" data-close-modal="wireGuardPeerDetailsModal"></div>
        <div class="modal-card app-modal-card is-modal-large">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="wireGuardPeerDetailsModalTitle">
                    <span class="icon"><i class="bi bi-info-circle" aria-hidden="true"></i></span>
                    <span>WireGuard Peer Details</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="wireGuardPeerDetailsModal"></button>
            </header>
            <section class="modal-card-body app-modal-body" id="wireGuardPeerDetailsContent">
                <!-- Details injected by JS -->
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="wireGuardPeerDetailsModal">Close</button>
            </footer>
        </div>
    </div>
    <?php
}
