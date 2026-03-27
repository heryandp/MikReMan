<?php

function renderPPPModals(): void
{
    ?>
    <!-- Add User Modal -->
    <div class="modal" id="addUserModal" role="dialog" aria-modal="true" aria-labelledby="addUserModalTitle">
        <div class="modal-background" data-close-modal="addUserModal"></div>
        <form class="modal-card app-modal-card is-modal-medium" id="addUserForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="addUserModalTitle">
                    <span class="icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                    <span>Add PPP User</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="addUserModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <div class="columns is-multiline is-variable is-4">
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="userName" class="label admin-label">Username</label>
                            <div class="field has-addons admin-field-addons">
                                <div class="control is-expanded">
                                    <input type="text" class="input admin-input" id="userName" name="name" required>
                                </div>
                                <div class="control">
                                    <button type="button" class="button is-info is-light admin-addon-button" onclick="generateRandomName()" title="Generate Random Username">
                                        <span class="icon"><i class="bi bi-shuffle"></i></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="userPassword" class="label admin-label">Password</label>
                            <div class="field has-addons admin-field-addons">
                                <div class="control is-expanded">
                                    <input type="password" class="input admin-input" id="userPassword" name="password" required>
                                </div>
                                <div class="control">
                                    <button type="button" class="button is-info is-light admin-addon-button" onclick="generateRandomPassword()" title="Generate Random Password">
                                        <span class="icon"><i class="bi bi-shuffle"></i></span>
                                    </button>
                                </div>
                                <div class="control">
                                    <button type="button" class="button is-dark is-outlined admin-addon-button" onclick="togglePassword('userPassword')" title="Show/Hide Password">
                                        <span class="icon"><i class="bi bi-eye" id="userPasswordIcon"></i></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label for="userService" class="label admin-label">Service</label>
                            <div class="control">
                                <div class="select is-fullwidth">
                                    <select id="userService" name="service" required>
                                        <option value="">Select Service</option>
                                        <option value="l2tp">L2TP</option>
                                        <option value="pptp">PPTP</option>
                                        <option value="sstp">SSTP</option>
                                        <option value="any">Any</option>
                                    </select>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">The PPP profile will follow the selected service.</p>
                        </div>
                    </div>
                    <input type="hidden" id="userRemoteAddress" name="remote_address">
                    <div class="column is-12-mobile is-6-tablet">
                        <div class="field">
                            <label class="label admin-label">Forwarded Ports</label>
                            <input type="hidden" id="userPorts" name="ports">
                            <div class="ppp-port-builder">
                                <div class="ppp-port-list" id="userPortsList"></div>
                                <div class="buttons ppp-port-presets">
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="80" data-port-label="HTTP">
                                        <span>HTTP 80</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="443" data-port-label="HTTPS">
                                        <span>HTTPS 443</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8291" data-port-label="Winbox">
                                        <span>Winbox 8291</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8728" data-port-label="API">
                                        <span>API 8728</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="22" data-port-label="SSH">
                                        <span>SSH 22</span>
                                    </button>
                                </div>
                                <div class="buttons ppp-port-actions">
                                    <button type="button" class="button is-link is-light is-small admin-action-button" id="addUserPortButton">
                                        <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                                        <span>Add Port</span>
                                    </button>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">Each internal port listed here gets its own random public port that forwards to the VPN client router. You can also add an optional label like Modem, AP1, or Router Source to make the mapping easier to identify later. If left empty, the app uses the defaults 8291 (Winbox) and 8728 (API).</p>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="createNatRule">
                                    <input type="checkbox" id="createNatRule" checked>
                                    <span>Create public NAT mapping</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12 ppp-multi-port-options" id="multiPortOptions">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="createMultipleNat">
                                    <input type="checkbox" id="createMultipleNat">
                                    <span>Create one random public port for each internal port</span>
                                </label>
                            </div>
                            <p class="help has-text-grey-light">Enable this if you want each internal port to receive a different random public port, for example for AP1, AP2, and other devices behind the client router.</p>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="addUserModal">Cancel</button>
                <button type="submit" class="button is-primary admin-action-button">
                    <span class="icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                    <span>Create User</span>
                </button>
            </footer>
        </form>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal" role="dialog" aria-modal="true" aria-labelledby="editUserModalTitle">
        <div class="modal-background" data-close-modal="editUserModal"></div>
        <form class="modal-card app-modal-card is-modal-compact" id="editUserForm">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="editUserModalTitle">
                    <span class="icon"><i class="bi bi-pencil" aria-hidden="true"></i></span>
                    <span>Edit PPP User</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="editUserModal"></button>
            </header>
            <section class="modal-card-body app-modal-body">
                <input type="hidden" id="editUserId" name="user_id">
                <input type="hidden" id="editExistingNatSnapshot" name="existing_nat_snapshot">
                <div class="columns is-multiline is-variable is-4">
                    <div class="column is-12">
                        <div class="field">
                            <label for="editUserName" class="label admin-label">Username</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="editUserName" name="name" required>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <label for="editUserService" class="label admin-label">Service</label>
                            <div class="control">
                                <div class="select is-fullwidth">
                                    <select id="editUserService" name="service" required>
                                        <option value="l2tp">L2TP</option>
                                        <option value="pptp">PPTP</option>
                                        <option value="sstp">SSTP</option>
                                        <option value="any">Any</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <label for="editUserRemoteAddress" class="label admin-label">Remote Address (IP)</label>
                            <div class="control">
                                <input type="text" class="input admin-input" id="editUserRemoteAddress" name="remote_address"
                                       pattern="^((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$">
                            </div>
                        </div>
                    </div>
                    <div class="column is-12">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="editSyncNatRule">
                                    <input type="checkbox" id="editSyncNatRule" name="sync_nat_ports">
                                    <span>Sync public NAT mappings</span>
                                </label>
                            </div>
                            <p class="help has-text-grey-light">Enable this to add, remove, or refresh the public port mappings attached to this PPP user. If disabled, existing NAT mappings stay untouched.</p>
                        </div>
                    </div>
                    <div class="column is-12" id="editNatMappingSection">
                        <div class="field">
                            <label class="label admin-label">Forwarded Ports</label>
                            <input type="hidden" id="editUserPorts" name="ports">
                            <div class="ppp-port-builder">
                                <div class="ppp-port-list" id="editUserPortsList"></div>
                                <div class="buttons ppp-port-presets">
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="80" data-port-label="HTTP" data-port-target="edit">
                                        <span>HTTP 80</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="443" data-port-label="HTTPS" data-port-target="edit">
                                        <span>HTTPS 443</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8291" data-port-label="Winbox" data-port-target="edit">
                                        <span>Winbox 8291</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="8728" data-port-label="API" data-port-target="edit">
                                        <span>API 8728</span>
                                    </button>
                                    <button type="button" class="button is-dark is-outlined is-small admin-action-button" data-port-preset="22" data-port-label="SSH" data-port-target="edit">
                                        <span>SSH 22</span>
                                    </button>
                                </div>
                                <div class="buttons ppp-port-actions">
                                    <button type="button" class="button is-link is-light is-small admin-action-button" id="addEditUserPortButton">
                                        <span class="icon"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                                        <span>Add Port</span>
                                    </button>
                                </div>
                            </div>
                            <p class="help has-text-grey-light">Ports kept in this list preserve their current public port when possible. Labels are saved in the NAT comment so you can see what each public mapping is for.</p>
                        </div>
                    </div>
                    <div class="column is-12 ppp-multi-port-options" id="editMultiPortOptions">
                        <div class="field">
                            <div class="control">
                                <label class="checkbox admin-checkbox" for="editCreateMultipleNat">
                                    <input type="checkbox" id="editCreateMultipleNat" name="createMultipleNat">
                                    <span>Create one random public port for each internal port</span>
                                </label>
                            </div>
                            <p class="help has-text-grey-light">Use per-port comments when you want each forwarded service to remain clearly labeled in RouterOS.</p>
                        </div>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="editUserModal">Cancel</button>
                <button type="submit" class="button is-warning admin-action-button">
                    <span class="icon"><i class="bi bi-pencil" aria-hidden="true"></i></span>
                    <span>Update User</span>
                </button>
            </footer>
        </form>
    </div>

    <!-- User Details Modal -->
    <div class="modal" id="userDetailsModal" role="dialog" aria-modal="true" aria-labelledby="userDetailsModalTitle">
        <div class="modal-background" data-close-modal="userDetailsModal"></div>
        <div class="modal-card app-modal-card is-modal-large">
            <header class="modal-card-head app-modal-head">
                <p class="modal-card-title app-modal-title" id="userDetailsModalTitle">
                    <span class="icon"><i class="bi bi-info-circle" aria-hidden="true"></i></span>
                    <span>User Details</span>
                </p>
                <button type="button" class="delete" aria-label="close" data-close-modal="userDetailsModal"></button>
            </header>
            <section class="modal-card-body app-modal-body" id="userDetailsContent">
                <!-- Details will be loaded here -->
            </section>
            <footer class="modal-card-foot app-modal-foot">
                <button type="button" class="button is-dark is-outlined admin-action-button" data-close-modal="userDetailsModal">Close</button>
            </footer>
        </div>
    </div>
    <?php
}
