<?php

function getPPPStats() {
    
    // Clean output buffer before processing
    if (ob_get_level() > 0) {
        ob_get_clean();
    }
    
    try {
        $mikrotik = new MikroTikAPI();
        
        // Get all PPP secrets (total users)
        $secrets = $mikrotik->getPPPSecrets();
        $total_users = is_array($secrets) ? count($secrets) : 0;
        
        // Get active PPP sessions (online users)
        $active_sessions = $mikrotik->getPPPActiveSessions();
        $online_users = is_array($active_sessions) ? count($active_sessions) : 0;
        
        // Calculate offline users
        $offline_users = $total_users - $online_users;
        if ($offline_users < 0) $offline_users = 0;
        
        $stats = [
            'total' => $total_users,
            'online' => $online_users,
            'offline' => $offline_users
        ];
        
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
        
    } catch (Exception $e) {
        
        // If it's a connection error, return mock data
        if (strpos($e->getMessage(), 'Cannot connect to MikroTik') !== false || strpos($e->getMessage(), 'cURL error') !== false) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'total' => 2,
                    'online' => 1,
                    'offline' => 1
                ],
                'mock_data' => true
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [
                    'total' => 0,
                    'online' => 0,
                    'offline' => 0
                ]
            ]);
        }
    }
}

function getPPPLogs() {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        // Get PPP logs - try to get logs related to PPP connections
        $logs = $mikrotik->getPPPLogs();
        
        if (!$logs || !is_array($logs)) {
            // If no specific PPP logs, try general system logs
            $logs = $mikrotik->getSystemLogs();
        }
        
        // Filter and format logs for PPP-related entries
        $ppp_logs = [];
        
        if (is_array($logs)) {
            foreach ($logs as $log) {
                // Check if log contains PPP-related keywords
                $message = isset($log['message']) ? $log['message'] : 
                          (isset($log['topics']) ? $log['topics'] : '');
                          
                if (stripos($message, 'ppp') !== false || 
                    stripos($message, 'l2tp') !== false ||
                    stripos($message, 'pptp') !== false ||
                    stripos($message, 'sstp') !== false ||
                    stripos($message, 'login') !== false ||
                    stripos($message, 'logout') !== false ||
                    stripos($message, 'connect') !== false ||
                    stripos($message, 'disconnect') !== false) {
                    
                    $ppp_logs[] = [
                        'time' => $log['time'] ?? date('H:i:s'),
                        'message' => $message,
                        'topics' => $log['topics'] ?? 'ppp'
                    ];
                }
            }
        }
        
        // Limit to last 50 entries and reverse for newest first
        $ppp_logs = array_slice(array_reverse($ppp_logs), 0, 50);
        
        
        echo json_encode([
            'success' => true,
            'data' => $ppp_logs
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => []
        ]);
    }
}

function getPPPUsers() {
    
    // Clean output buffer before processing
    if (ob_get_level() > 0) {
        ob_get_clean();
    }
    
    try {
        $mikrotik = new MikroTikAPI();
        
        // Get all PPP secrets (users)
        $users = $mikrotik->getPPPSecrets();
        
        if (!is_array($users)) {
            $users = [];
        }
        
        
        $response = [
            'success' => true,
            'data' => $users
        ];
        echo json_encode($response);
        
    } catch (Exception $e) {
                
        // If it's a connection error, return mock data for development
        if (strpos($e->getMessage(), 'Cannot connect to MikroTik') !== false || strpos($e->getMessage(), 'cURL error') !== false) {
            echo json_encode([
                'success' => true,
                'data' => [
                    [
                        '.id' => '*1',
                        'name' => 'user5377',
                        'password' => 'pass123',
                        'service' => 'sstp',
                        'remote-address' => '10.51.0.100',
                        'disabled' => 'false'
                    ],
                    [
                        '.id' => '*2', 
                        'name' => 'user8291',
                        'password' => 'mypass456',
                        'service' => 'l2tp',
                        'remote-address' => '10.51.0.101',
                        'disabled' => 'false'
                    ]
                ],
                'mock_data' => true
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ]);
        }
    }
}

function getPPPProfiles() {

    try {
        $mikrotik = new MikroTikAPI();

        // Get all PPP profiles
        $profiles = $mikrotik->getPPPProfiles();

        if (!is_array($profiles)) {
            $profiles = [];
        }


        echo json_encode([
            'success' => true,
            'data' => $profiles
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => []
        ]);
    }
}

function getPPPActive() {
    try {
        $mikrotik = new MikroTikAPI();

        // Get all active PPP sessions WITH traffic statistics from interfaces
        $activeSessions = $mikrotik->getPPPActiveWithTraffic();

        if (!is_array($activeSessions)) {
            $activeSessions = [];
        }

        echo json_encode([
            'success' => true,
            'data' => $activeSessions
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => []
        ]);
    }
}

function getAvailableServices() {
    try {
        $mikrotik = new MikroTikAPI();

        // Get VPN service status from MikroTik
        $services = $mikrotik->getVPNServicesStatus();

        // Filter only enabled services
        $availableServices = [];
        foreach ($services as $service => $status) {
            if (!in_array($service, ['l2tp', 'pptp', 'sstp'], true)) {
                continue;
            }

            if ($status === true || $status === 'enabled') {
                $availableServices[] = strtolower($service);
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $availableServices
        ]);

    } catch (Exception $e) {
        // Return default services if error
        echo json_encode([
            'success' => true,
            'data' => ['l2tp', 'pptp', 'sstp', 'any']
        ]);
    }
}

function getNATByUser($input) {
    try {
        // Validate required field
        if (empty($input['username'])) {
            throw new Exception("Username is required");
        }

        $mikrotik = new MikroTikAPI();
        $username = $input['username'];

        // Get NAT rules filtered by comment (username)
        $nat_rules = $mikrotik->getFirewallNATByComment($username);

        if (!is_array($nat_rules)) {
            $nat_rules = [];
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $nat_rules
        ]);

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => []
        ]);
    }
}

function removeQemuHostFwdRules(array $nat_rules, $qemu_hostfwd) {
    $removed_count = 0;
    $errors = [];
    $processed = [];

    if (!$qemu_hostfwd || !$qemu_hostfwd->isEnabled()) {
        return [
            'removed_count' => 0,
            'errors' => []
        ];
    }

    foreach ($nat_rules as $rule) {
        $protocol = $rule['protocol'] ?? 'tcp';
        $dst_port = $rule['dst-port'] ?? '';

        if (!is_numeric($dst_port)) {
            continue;
        }

        $key = strtolower($protocol) . ':' . $dst_port;
        if (isset($processed[$key])) {
            continue;
        }
        $processed[$key] = true;

        $result = $qemu_hostfwd->removeForward((int)$dst_port, $protocol);
        if (!empty($result['success'])) {
            $removed_count++;
            error_log('[QEMU HOSTFWD] Removed forward for ' . $key);
        } elseif (empty($result['skipped'])) {
            $errors[] = $key . ': ' . ($result['message'] ?? 'Unknown error');
            error_log('[QEMU HOSTFWD] Failed to remove forward for ' . $key . ': ' . ($result['message'] ?? 'Unknown error'));
        }
    }

    return [
        'removed_count' => $removed_count,
        'errors' => $errors
    ];
}

function collectUserNatRules($mikrotik, $username, $remote_address) {
    $nat_rules = [];
    $rule_map = [];

    if (!empty($username)) {
        try {
            foreach ($mikrotik->getFirewallNATByComment($username) as $rule) {
                $rule_id = $rule['.id'] ?? md5(json_encode($rule));
                $rule_map[$rule_id] = $rule;
            }
        } catch (Exception $e) {
            error_log('[MIKROTIK NAT] Failed to collect NAT rules by comment for ' . $username . ': ' . $e->getMessage());
        }
    }

    if (!empty($remote_address)) {
        try {
            foreach ($mikrotik->getFirewallNATByIP($remote_address) as $rule) {
                $rule_id = $rule['.id'] ?? md5(json_encode($rule));
                $rule_map[$rule_id] = $rule;
            }
        } catch (Exception $e) {
            error_log('[MIKROTIK NAT] Failed to collect NAT rules by IP for ' . $remote_address . ': ' . $e->getMessage());
        }
    }

    foreach ($rule_map as $rule) {
        $nat_rules[] = $rule;
    }

    return $nat_rules;
}

function createPPPUserNatRule($mikrotik, array $nat_data, $external_port, $internal_port, $type, $qemu_hostfwd = null) {
    error_log('[ADD PPP USER][NAT] Creating rule: ' . json_encode($nat_data));

    try {
        $nat_result = $mikrotik->addFirewallNAT($nat_data);
        error_log('[ADD PPP USER][NAT] RouterOS response: ' . json_encode($nat_result));

        if ($nat_result === false || $nat_result === null) {
            return [
                'external_port' => $external_port,
                'internal_port' => $internal_port,
                'success' => false,
                'error' => 'NAT creation returned false/null',
                'type' => $type
            ];
        }

        $result = [
            'external_port' => $external_port,
            'internal_port' => $internal_port,
            'success' => true,
            'type' => $type
        ];

        if ($qemu_hostfwd && $qemu_hostfwd->isEnabled()) {
            $hostfwd_result = $qemu_hostfwd->addForward($external_port, $external_port, $nat_data['protocol'] ?? 'tcp');
            $result['hostfwd'] = $hostfwd_result;

            if (empty($hostfwd_result['success']) && empty($hostfwd_result['skipped'])) {
                $result['success'] = false;
                $result['error'] = 'NAT created but QEMU host forward failed: ' . ($hostfwd_result['message'] ?? 'Unknown error');
                error_log('[ADD PPP USER][NAT] QEMU hostfwd failed for external port ' . $external_port . ': ' . ($hostfwd_result['message'] ?? 'Unknown error'));
            } else {
                error_log('[ADD PPP USER][NAT] QEMU hostfwd ready for external port ' . $external_port);
            }
        }

        return $result;
    } catch (Exception $e) {
        error_log('[ADD PPP USER][NAT] Failed to create rule for external port ' . $external_port . ' -> ' . $internal_port . ': ' . $e->getMessage());

        return [
            'external_port' => $external_port,
            'internal_port' => $internal_port,
            'success' => false,
            'error' => $e->getMessage(),
            'type' => $type
        ];
    }
}

function addPPPUser($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        $mikrotik_config = getConfig('mikrotik') ?? [];
        $qemu_hostfwd = getQemuHostFwdManager($mikrotik_config);
        
        // Validate required fields
        $required_fields = ['name', 'password', 'service'];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) {
                throw new Exception("Field '$field' is required");
            }
        }
        
        $user_data = [
            'name' => $input['name'],
            'password' => $input['password'],
            'service' => $input['service']
        ];
        
        // Auto-determine profile based on service
        $service = strtolower($input['service']);
        $profile_mapping = [
            'l2tp' => 'L2TP',
            'pptp' => 'PPTP', 
            'sstp' => 'SSTP',
            'any' => 'default'
        ];
        
        if (isset($profile_mapping[$service])) {
            $user_data['profile'] = $profile_mapping[$service];
        }
        
        // Handle remote address - auto-assign if not provided
        if (!empty($input['remote_address'])) {
            $user_data['remote-address'] = $input['remote_address'];
        } else {
            // Auto-assign IP if not provided
            try {
                $available_ip = $mikrotik->getNextAvailableIP($service);
                if ($available_ip) {
                    $user_data['remote-address'] = $available_ip;
                } else {
                }
            } catch (Exception $e) {
                // Continue without remote-address - let MikroTik handle it
            }
        }
        
        // Create PPP user
        $result = $mikrotik->addPPPSecret($user_data);
        
        if (!$result) {
            throw new Exception('Failed to create PPP user');
        }
        
        // Create firewall NAT rules if requested and ports provided
        $nat_results = [];

        // Use the actual remote address assigned (either from input or auto-assigned)
        $remote_address = $user_data['remote-address'] ?? $input['remote_address'] ?? null;

        if (isset($input['createNatRule']) && !empty($remote_address)) {
            $requested_port_entries = parseRequestedPortEntriesFromInput($input);
            
            // If no ports specified, use default ports 8291 and 8728
            if (empty($requested_port_entries)) {
                $requested_port_entries = [
                    ['port' => '8291', 'label' => ''],
                    ['port' => '8728', 'label' => '']
                ];
            } else {
                $requested_port_entries = array_values(array_filter($requested_port_entries, static function ($entry) {
                    return !empty($entry['port']);
                }));
            }
            $ports = array_column($requested_port_entries, 'port');
            
            // Check if createMultipleNat is enabled
            $createMultipleNat = isset($input['createMultipleNat']) && $input['createMultipleNat'];
            $has_port_labels = count(array_filter($requested_port_entries, static function ($entry) {
                return trim((string)($entry['label'] ?? '')) !== '';
            })) > 0;
            $use_individual_comment = ($createMultipleNat && count($ports) > 1) || $has_port_labels;
            
            if ($createMultipleNat && count($ports) > 1) {
                // Mode 1: Create separate NAT rules for each port with same remote address
                // This is useful when you want individual port forwarding rules
                foreach ($requested_port_entries as $entry) {
                    $port = $entry['port'];
                    $label = $entry['label'] ?? '';
                    if (!is_numeric($port) || $port < 1 || $port > 65535) {
                        continue; // Skip invalid ports
                    }
                    
                    // Generate random external port for each internal port
                    try {
                        $external_port = generateRandomPort($mikrotik);
                    } catch (Exception $e) {
                        $external_port = rand(16000, 20000); // Fallback
                    }
                    
                    $nat_data = [
                        'chain' => 'dstnat',
                        'action' => 'dst-nat',
                        'protocol' => 'tcp',
                        'dst-port' => (string)$external_port,
                        'to-addresses' => $remote_address,
                        'to-ports' => (string)$port,
                        'comment' => buildPPPUserNatComment($input['name'], $port, true, $label)
                    ];

                    $nat_results[] = createPPPUserNatRule($mikrotik, $nat_data, $external_port, $port, 'individual', $qemu_hostfwd);
                }
            } else {
                // Mode 2: Create single NAT rule with port range (default behavior)
                // This is more efficient when you want to forward multiple consecutive ports
                
                if (count($ports) == 1) {
                    // Single port - create one rule
                    $entry = $requested_port_entries[0];
                    $port = $entry['port'];
                    $label = $entry['label'] ?? '';
                    if (is_numeric($port) && $port >= 1 && $port <= 65535) {
                        try {
                            $external_port = generateRandomPort($mikrotik);
                        } catch (Exception $e) {
                            $external_port = rand(16000, 20000);
                        }
                        
                        $nat_data = [
                            'chain' => 'dstnat',
                            'action' => 'dst-nat',
                            'protocol' => 'tcp',
                            'dst-port' => (string)$external_port,
                            'to-addresses' => $remote_address,
                            'to-ports' => (string)$port,
                            'comment' => buildPPPUserNatComment($input['name'], $port, $use_individual_comment, $label)
                        ];

                        $nat_results[] = createPPPUserNatRule($mikrotik, $nat_data, $external_port, $port, 'single', $qemu_hostfwd);
                    }
                } else {
                    // Multiple ports - create one rule per port (default behavior)
                    foreach ($requested_port_entries as $entry) {
                        $port = $entry['port'];
                        $label = $entry['label'] ?? '';
                        if (!is_numeric($port) || $port < 1 || $port > 65535) {
                            continue;
                        }
                        
                        try {
                            $external_port = generateRandomPort($mikrotik);
                        } catch (Exception $e) {
                            $external_port = rand(16000, 20000);
                        }
                        
                        $nat_data = [
                            'chain' => 'dstnat',
                            'action' => 'dst-nat',
                            'protocol' => 'tcp',
                            'dst-port' => (string)$external_port,
                            'to-addresses' => $remote_address,
                            'to-ports' => (string)$port,
                            'comment' => buildPPPUserNatComment($input['name'], $port, $use_individual_comment, $label)
                        ];

                        $nat_results[] = createPPPUserNatRule($mikrotik, $nat_data, $external_port, $port, 'default', $qemu_hostfwd);
                    }
                }
            }
        }
        
        $message = 'PPP user created successfully';
        if (!empty($nat_results)) {
            $successful_nats = array_filter($nat_results, function($nat) {
                return $nat['success'];
            });
            
            $failed_nats = array_filter($nat_results, function($nat) {
                return !$nat['success'];
            });
            
            if (count($successful_nats) > 0) {
                // Check if we used individual NAT mode
                $individual_nats = array_filter($successful_nats, function($nat) {
                    return isset($nat['type']) && $nat['type'] === 'individual';
                });
                
                if (count($individual_nats) > 0) {
                    $message .= '. ' . count($successful_nats) . ' individual NAT rule(s) created (separate rules for each port).';
                } else {
                    $message .= '. ' . count($successful_nats) . ' NAT rule(s) created.';
                }
            }
            
            if (count($failed_nats) > 0) {
                $error_details = array_map(function($nat) {
                    return "Port {$nat['internal_port']}: {$nat['error']}";
                }, $failed_nats);
                
                $message .= ' WARNING: ' . count($failed_nats) . ' NAT rule(s) failed: ' . implode('; ', $error_details);
            }
        }

        // Create Netwatch entry for the user's remote address
        $netwatch_created = false;
        $netwatch_error = null;
        if (!empty($user_data['remote-address'])) {
            try {
                error_log("[ADD PPP USER] Attempting to create netwatch for IP: " . $user_data['remote-address']);
                $netwatch_result = $mikrotik->createNetwatch($user_data['remote-address'], $input['name']);

                if ($netwatch_result !== false) {
                    $netwatch_created = true;
                    $message .= '. Netwatch monitoring enabled';
                    error_log("[ADD PPP USER] Netwatch created successfully");
                } else {
                    $netwatch_error = "Netwatch creation returned false";
                    error_log("[ADD PPP USER] Netwatch creation returned false");
                }
            } catch (Exception $e) {
                $netwatch_error = $e->getMessage();
                error_log("[ADD PPP USER] Netwatch exception: " . $e->getMessage());
                // Continue - netwatch is optional
            }
        } else {
            error_log("[ADD PPP USER] No remote address available for netwatch");
        }

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => $message,
            'nat_results' => $nat_results,
            'netwatch_created' => $netwatch_created,
            'netwatch_error' => $netwatch_error
        ]);

    } catch (Exception $e) {
        error_log("[ADD PPP USER ERROR] " . $e->getMessage());
        error_log("[ADD PPP USER TRACE] " . $e->getTraceAsString());

        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'debug' => [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
    }
}

function editPPPUser($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        $mikrotik_config = getConfig('mikrotik') ?? [];
        $qemu_hostfwd = getQemuHostFwdManager($mikrotik_config);
        
        if (empty($input['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        $user_id = $input['user_id'];
        $update_data = [];
        $existing_user = null;

        $ppp_users = $mikrotik->getPPPSecrets();
        if (is_array($ppp_users)) {
            foreach ($ppp_users as $ppp_user) {
                if (isset($ppp_user['.id']) && $ppp_user['.id'] === $user_id) {
                    $existing_user = $ppp_user;
                    break;
                }
            }
        }

        if (!$existing_user) {
            throw new Exception('PPP user not found');
        }

        $old_username = $existing_user['name'] ?? '';
        $old_remote_address = $existing_user['remote-address'] ?? '';
        $existing_nat_rules = collectUserNatRules($mikrotik, $old_username, $old_remote_address);
        $snapshot_nat_rules = parseNatSnapshotInput($input['existing_nat_snapshot'] ?? '');
        if (!empty($snapshot_nat_rules)) {
            $nat_rule_map = [];

            foreach (array_merge($existing_nat_rules, $snapshot_nat_rules) as $rule) {
                $rule_id = $rule['.id'] ?? md5(json_encode($rule));
                $nat_rule_map[$rule_id] = $rule;
            }

            $existing_nat_rules = array_values($nat_rule_map);
        }
        $sync_nat_ports = normalizeInputBoolean($input['sync_nat_ports'] ?? false);
        
        // Add fields to update
        if (!empty($input['name'])) {
            $update_data['name'] = $input['name'];
        }
        
        if (!empty($input['service'])) {
            $update_data['service'] = $input['service'];
        }
        
        if (isset($input['remote_address'])) {
            if (empty($input['remote_address'])) {
                $update_data['remote-address'] = '';
            } else {
                $update_data['remote-address'] = $input['remote_address'];
            }
        }
        
        if (empty($update_data) && !$sync_nat_ports) {
            throw new Exception('No data to update');
        }
        
        if (!empty($update_data)) {
            // Update PPP user
            $result = $mikrotik->updatePPPSecret($user_id, $update_data);
            
            if (!$result) {
                throw new Exception('Failed to update PPP user');
            }
        }

        $new_username = $update_data['name'] ?? $old_username;
        $new_remote_address = array_key_exists('remote-address', $update_data) ? $update_data['remote-address'] : $old_remote_address;
        $nat_summary = null;
        $message = empty($update_data)
            ? 'PPP user NAT mappings updated successfully'
            : 'PPP user updated successfully';

        if ($sync_nat_ports) {
            $requested_port_entries = parseRequestedPortEntriesFromInput($input);
            $requested_ports = array_column($requested_port_entries, 'port');
            $has_port_labels = count(array_filter($requested_port_entries, static function ($entry) {
                return trim((string)($entry['label'] ?? '')) !== '';
            })) > 0;
            $use_individual_comment = (normalizeInputBoolean($input['createMultipleNat'] ?? false) && count($requested_ports) > 1) || $has_port_labels;

            if (!empty($requested_ports) && empty($new_remote_address)) {
                throw new Exception('Remote address is required to sync public NAT mappings');
            }

            $nat_summary = syncPPPUserNatRules(
                $mikrotik,
                $qemu_hostfwd,
                $existing_nat_rules,
                $old_username,
                $new_username,
                $old_remote_address,
                $new_remote_address,
                $requested_port_entries,
                $use_individual_comment
            );

            $message .= sprintf(
                '. NAT sync completed: %d preserved, %d removed, %d created',
                $nat_summary['preserved_count'] ?? 0,
                $nat_summary['deleted_count'] ?? 0,
                $nat_summary['created_count'] ?? 0
            );

            if (!empty($nat_summary['hostfwd_removed_count'])) {
                $message .= ', ' . $nat_summary['hostfwd_removed_count'] . ' QEMU host forward(s) removed';
            }

            if (!empty($nat_summary['errors'])) {
                $message .= '. Warning: ' . implode('; ', $nat_summary['errors']);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'nat_sync' => $nat_summary
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function deletePPPUser($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        if (empty($input['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        $user_id = $input['user_id'];
        
        // Get user info first to find related NAT rules - use list-based approach for reliability
        $user = null;
        $username = '';
        $remote_address = '';
        
        try {
            // Get all PPP users and find the one we want
            $ppp_users = $mikrotik->getPPPSecrets();
            if (is_array($ppp_users)) {
                foreach ($ppp_users as $ppp_user) {
                    if (isset($ppp_user['.id']) && $ppp_user['.id'] === $user_id) {
                        $user = $ppp_user;
                        break;
                    }
                }
            }
            
            if (!$user) {
                throw new Exception('PPP user not found with ID: ' . $user_id);
            }
            
            $username = $user['name'] ?? '';
            $remote_address = $user['remote-address'] ?? '';
            
            
        } catch (Exception $e) {
            throw new Exception('PPP user not found: ' . $e->getMessage());
        }
        
        // STEP 1: Delete Netwatch entry FIRST
        $mikrotik_config = getConfig('mikrotik') ?? [];
        $qemu_hostfwd = getQemuHostFwdManager($mikrotik_config);
        $nat_rules_to_delete = collectUserNatRules($mikrotik, $username, $remote_address);
        $hostfwd_remove_result = removeQemuHostFwdRules($nat_rules_to_delete, $qemu_hostfwd);
        $hostfwd_removed = $hostfwd_remove_result['removed_count'];

        $netwatch_deleted = false;
        if (!empty($username)) {
            try {
                $netwatch_deleted = $mikrotik->deleteNetwatchByComment($username);
            } catch (Exception $e) {
                // Continue even if netwatch deletion fails
            }
        }

        // If not deleted by comment, try by IP
        if (!$netwatch_deleted && !empty($remote_address)) {
            try {
                $netwatch_deleted = $mikrotik->deleteNetwatchByHost($remote_address);
            } catch (Exception $e) {
                // Continue even if netwatch deletion fails
            }
        }

        // STEP 2: Delete NAT rules SECOND
        $nat_deleted = 0;

        // Try method 1: Delete by comment (username)
        if (!empty($username)) {
            try {
                $nat_deleted = $mikrotik->deleteFirewallNATByComment($username);
            } catch (Exception $e) {
            }
        }

        // Try method 2: Delete by IP address if method 1 found nothing
        if ($nat_deleted == 0 && !empty($remote_address)) {
            try {
                $nat_deleted = $mikrotik->deleteFirewallNATByIP($remote_address);
            } catch (Exception $e) {
            }
        }

        // STEP 3: Delete PPP user LAST (after Netwatch and NAT rules are deleted)
        try {
            $result = $mikrotik->deletePPPSecret($user_id);

            if (!$result) {
                throw new Exception('Failed to delete PPP user');
            }
        } catch (Exception $e) {
            throw new Exception('Failed to delete PPP user: ' . $e->getMessage());
        }

        $message = 'PPP user deleted successfully';
        if ($nat_deleted > 0) {
            $message .= " and $nat_deleted NAT rule(s) removed";
        } elseif (!empty($username)) {
            $message .= ' (no NAT rules found to remove)';
        }

        if ($hostfwd_removed > 0) {
            $message .= ", $hostfwd_removed QEMU host forward(s) removed";
        }

        if ($netwatch_deleted) {
            $message .= ', Netwatch entry removed';
        }

        if (!empty($hostfwd_remove_result['errors'])) {
            $message .= '. QEMU hostfwd cleanup warning: ' . implode('; ', $hostfwd_remove_result['errors']);
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'nat_deleted' => $nat_deleted,
            'netwatch_deleted' => $netwatch_deleted,
            'hostfwd_removed' => $hostfwd_removed
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function togglePPPUserStatus($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        if (empty($input['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        $user_id = $input['user_id'];
        
        // Get current user status using list-based approach for reliability
        $ppp_users = $mikrotik->getPPPSecrets();
        $user = null;
        
        if (is_array($ppp_users)) {
            foreach ($ppp_users as $ppp_user) {
                if (isset($ppp_user['.id']) && $ppp_user['.id'] === $user_id) {
                    $user = $ppp_user;
                    break;
                }
            }
        }
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Check current disabled status
        // MikroTik API may return 'true'/'false' as strings or boolean values
        $current_disabled = false;
        if (isset($user['disabled'])) {
            $current_disabled = ($user['disabled'] === 'true' || $user['disabled'] === true);
        }
        
        // Toggle status - if currently disabled, enable it; if enabled, disable it
        $new_disabled = !$current_disabled;
        
        // Update user with proper boolean values for MikroTik API
        $update_data = ['disabled' => $new_disabled];
        $result = $mikrotik->updatePPPSecret($user_id, $update_data);
        
        if (!$result) {
            throw new Exception('Failed to toggle user status');
        }
        
        $status = $new_disabled ? 'disabled' : 'enabled';
        $username = $user['name'] ?? 'User';
        
        echo json_encode([
            'success' => true,
            'message' => "User {$username} {$status} successfully",
            'status' => $status,
            'disabled' => $new_disabled
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function bulkDeletePPPUsers($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        if (empty($input['user_ids']) || !is_array($input['user_ids'])) {
            throw new Exception('User IDs array is required');
        }
        
        $user_ids = $input['user_ids'];
        $mikrotik_config = getConfig('mikrotik') ?? [];
        $qemu_hostfwd = getQemuHostFwdManager($mikrotik_config);
        $deleted_count = 0;
        $nat_deleted_count = 0;
        $hostfwd_removed_count = 0;
        $errors = [];
        
        // Get all PPP users once for efficiency
        $ppp_users = $mikrotik->getPPPSecrets();
        
        foreach ($user_ids as $user_id) {
            try {
                // Find user in the list
                $user = null;
                if (is_array($ppp_users)) {
                    foreach ($ppp_users as $ppp_user) {
                        if (isset($ppp_user['.id']) && $ppp_user['.id'] === $user_id) {
                            $user = $ppp_user;
                            break;
                        }
                    }
                }
                
                if (!$user) {
                    $errors[] = "User ID $user_id not found";
                    continue;
                }
                
                $username = $user['name'] ?? '';
                $remote_address = $user['remote-address'] ?? '';
                $nat_rules_to_delete = collectUserNatRules($mikrotik, $username, $remote_address);
                $hostfwd_remove_result = removeQemuHostFwdRules($nat_rules_to_delete, $qemu_hostfwd);
                $hostfwd_removed_count += $hostfwd_remove_result['removed_count'];
                if (!empty($hostfwd_remove_result['errors'])) {
                    $errors[] = 'Hostfwd cleanup for ' . ($username ?: $user_id) . ': ' . implode('; ', $hostfwd_remove_result['errors']);
                }

                // STEP 1: Delete Netwatch entry FIRST
                if (!empty($username)) {
                    try {
                        $mikrotik->deleteNetwatchByComment($username);
                    } catch (Exception $e) {
                        // Continue even if netwatch deletion fails
                    }
                }

                // If not deleted by comment, try by IP
                if (!empty($remote_address)) {
                    try {
                        $mikrotik->deleteNetwatchByHost($remote_address);
                    } catch (Exception $e) {
                        // Continue even if netwatch deletion fails
                    }
                }

                // STEP 2: Delete NAT rules SECOND
                $nat_deleted = 0;

                // Try method 1: Delete by comment (username)
                if (!empty($username)) {
                    try {
                        $nat_deleted = $mikrotik->deleteFirewallNATByComment($username);
                    } catch (Exception $e) {
                    }
                }

                // Try method 2: Delete by IP if method 1 found nothing
                if ($nat_deleted == 0 && !empty($remote_address)) {
                    try {
                        $nat_deleted = $mikrotik->deleteFirewallNATByIP($remote_address);
                    } catch (Exception $e) {
                    }
                }

                $nat_deleted_count += $nat_deleted;

                // STEP 3: Delete PPP user LAST (after Netwatch and NAT rules are deleted)
                $result = $mikrotik->deletePPPSecret($user_id);
                
                if ($result) {
                    $deleted_count++;
                } else {
                    $errors[] = "Failed to delete user: $username (ID: $user_id)";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error deleting user ID $user_id: " . $e->getMessage();
            }
        }
        
        if ($deleted_count > 0) {
            $message = "$deleted_count user(s) deleted successfully";
            
            if ($nat_deleted_count > 0) {
                $message .= " and $nat_deleted_count NAT rule(s) removed";
            }

            if ($hostfwd_removed_count > 0) {
                $message .= ", $hostfwd_removed_count QEMU host forward(s) removed";
            }
            
            if (!empty($errors)) {
                $message .= ". Some errors occurred: " . implode(', ', $errors);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'deleted_count' => $deleted_count,
                'nat_deleted_count' => $nat_deleted_count,
                'errors' => $errors
            ]);
        } else {
            throw new Exception('No users could be deleted. Errors: ' . implode(', ', $errors));
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function bulkTogglePPPUsers($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        if (empty($input['user_ids']) || !is_array($input['user_ids'])) {
            throw new Exception('User IDs array is required');
        }
        
        $user_ids = $input['user_ids'];
        $updated_count = 0;
        $errors = [];
        
        // Get all PPP users once for efficiency
        $ppp_users = $mikrotik->getPPPSecrets();
        
        foreach ($user_ids as $user_id) {
            try {
                // Find user in the list
                $user = null;
                if (is_array($ppp_users)) {
                    foreach ($ppp_users as $ppp_user) {
                        if (isset($ppp_user['.id']) && $ppp_user['.id'] === $user_id) {
                            $user = $ppp_user;
                            break;
                        }
                    }
                }
                
                if (!$user) {
                    $errors[] = "User with ID $user_id not found";
                    continue;
                }
                
                // Check current disabled status
                $current_disabled = false;
                if (isset($user['disabled'])) {
                    $current_disabled = ($user['disabled'] === 'true' || $user['disabled'] === true);
                }
                
                // Toggle status
                $new_disabled = !$current_disabled;
                
                // Update user with proper boolean values
                $result = $mikrotik->updatePPPSecret($user_id, ['disabled' => $new_disabled]);
                
                if ($result) {
                    $updated_count++;
                } else {
                    $errors[] = "Failed to update user: " . ($user['name'] ?? $user_id);
                }
            } catch (Exception $e) {
                $errors[] = "Error updating user $user_id: " . $e->getMessage();
            }
        }
        
        if ($updated_count > 0) {
            $message = "$updated_count user(s) status updated successfully";
            if (!empty($errors)) {
                $message .= ". Some errors occurred: " . implode(', ', $errors);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'updated_count' => $updated_count,
                'errors' => $errors
            ]);
        } else {
            throw new Exception('No users could be updated. Errors: ' . implode(', ', $errors));
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function getUserDetails($input) {
    
    try {
        if (empty($input['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        $user_id = $input['user_id'];
        
        $mikrotik = new MikroTikAPI();
        
        // Get user details - use simpler approach like debug version
        $ppp_users = $mikrotik->getPPPSecrets();
        $user = null;
        
        if (is_array($ppp_users)) {
            foreach ($ppp_users as $ppp_user) {
                if (isset($ppp_user['.id']) && $ppp_user['.id'] === $user_id) {
                    $user = $ppp_user;
                    break;
                }
            }
        }
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Get related NAT rules - search by remote-address (IP) instead of comment
        $nat_rules = [];
        if (!empty($user['remote-address'])) {
            try {
                // Primary search: by remote-address (to-addresses field)
                $nat_rules = $mikrotik->getFirewallNATByIP($user['remote-address']);
                
                
                // Fallback: if no NAT rules found by IP, try by comment (username)
                if (empty($nat_rules) && !empty($user['name'])) {
                    $nat_rules = $mikrotik->getFirewallNATByComment($user['name']);
                }
            } catch (Exception $e) {
                // Don't throw, just log and continue
            }
        }
        
        // Get MikroTik server IP from config
        try {
            $mikrotik_config = getConfig('mikrotik');
            $server_ip = $mikrotik_config['host'] ?? '[server_ip]';
        } catch (Exception $e) {
            $server_ip = '[server_ip]';
        }
        
        $response_data = [
            'user' => $user,
            'nat_rules' => $nat_rules,
            'server_ip' => $server_ip
        ];
        
        
        echo json_encode([
            'success' => true,
            'data' => $response_data
        ]);
        
    } catch (Exception $e) {
        error_log('[GET USER DETAILS] Failed for user ' . ($input['user_id'] ?? 'unknown') . ': ' . $e->getMessage());

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function getUserPassword($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        if (empty($input['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        $user_id = $input['user_id'];
        
        // Get user details
        $user = $mikrotik->getPPPSecret($user_id);
        if (!$user) {
            throw new Exception('User not found');
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'password' => $user['password'] ?? 'N/A'
            ]
        ]);
        
    } catch (Exception $e) {
        
        // If it's a connection error, return mock data
        if (strpos($e->getMessage(), 'Cannot connect to MikroTik') !== false || strpos($e->getMessage(), 'cURL error') !== false) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'password' => 'pass123'
                ],
                'mock_data' => true
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}

function getAvailableIP($input) {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        if (empty($input['service'])) {
            throw new Exception('Service is required');
        }
        
        $service = strtolower($input['service']);
        
        // Get available IP for the service
        $available_ip = $mikrotik->getNextAvailableIP($service);
        
        if (!$available_ip) {
            throw new Exception('No available IP found for service: ' . $service);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'ip' => $available_ip,
                'service' => $service
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

