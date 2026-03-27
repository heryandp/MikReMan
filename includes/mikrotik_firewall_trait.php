<?php

trait MikroTikFirewallTrait
{
    /**
     * Get firewall NAT rules
     */
    public function getFirewallNAT() {
        return $this->makeRequest('/ip/firewall/nat');
    }

    /**
     * Check if masquerade NAT rule exists
     */
    public function checkMasqueradeNAT() {
        try {
            $nat_rules = $this->getFirewallNAT();

            if (is_array($nat_rules)) {
                foreach ($nat_rules as $rule) {
                    if (isset($rule['chain']) && $rule['chain'] === 'srcnat' &&
                        isset($rule['action']) && $rule['action'] === 'masquerade') {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create masquerade NAT rule
     */
    public function createMasqueradeNAT() {
        try {
            $nat_data = [
                'chain' => 'srcnat',
                'action' => 'masquerade',
                'comment' => 'BB'
            ];

            $result = $this->addFirewallNAT($nat_data);
            return $result !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Add PPP secret (user)
     */
    public function addPPPSecret($data) {
        return $this->makeRequest('/ppp/secret', 'PUT', $data);
    }

    /**
     * Update PPP secret
     */
    public function updatePPPSecret($id, $data) {
        return $this->makeRequest('/ppp/secret/' . $id, 'PATCH', $data);
    }

    /**
     * Delete PPP secret
     */
    public function deletePPPSecret($id) {
        return $this->makeRequest('/ppp/secret/' . $id, 'DELETE');
    }

    /**
     * Add firewall NAT rule
     */
    public function addFirewallNAT($data) {
        return $this->makeRequest('/ip/firewall/nat', 'PUT', $data);
    }

    /**
     * Delete firewall NAT rule
     */
    public function deleteFirewallNAT($id) {
        error_log("[MIKROTIK NAT] Attempting to delete NAT rule with ID: $id");

        try {
            $result = $this->makeRequest('/ip/firewall/nat/' . $id, 'DELETE');
            error_log("[MIKROTIK NAT] Delete NAT rule result: " . json_encode($result));
            return $result;
        } catch (Exception $e) {
            error_log("[MIKROTIK NAT] Failed to delete NAT rule ID $id: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get used ports from NAT rules
     */
    public function getUsedPorts() {
        try {
            $nat_rules = $this->getFirewallNAT();
            $used_ports = [];

            foreach ($nat_rules as $rule) {
                if (isset($rule['dst-port']) && !empty($rule['dst-port'])) {
                    $port = $rule['dst-port'];
                    if (strpos($port, ',') !== false) {
                        $ports = explode(',', $port);
                        $used_ports = array_merge($used_ports, array_map('trim', $ports));
                    } else {
                        $used_ports[] = trim($port);
                    }
                }
            }

            return array_unique(array_filter($used_ports, 'is_numeric'));
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Generate random unused port
     */
    public function generateRandomPort($min = 16000, $max = 20000) {
        $used_ports = $this->getUsedPorts();
        $max_attempts = 100;

        for ($i = 0; $i < $max_attempts; $i++) {
            $port = rand($min, $max);
            if (!in_array($port, $used_ports)) {
                return $port;
            }
        }

        throw new Exception('Unable to find available port after ' . $max_attempts . ' attempts');
    }

    /**
     * Get log entries
     */
    public function getLogEntries($limit = 50) {
        return $this->makeRequest('/log?' . http_build_query(['.proplist' => 'time,topics,message']));
    }

    /**
     * Get PPP active sessions (alias for getPPPActive)
     */
    public function getPPPActiveSessions() {
        return $this->getPPPActive();
    }

    /**
     * Get PPP logs (filtered log entries for PPP-related activities)
     */
    public function getPPPLogs($limit = 100) {
        try {
            $logs = $this->getLogEntries($limit);
            return $logs;
        } catch (Exception $e) {
            error_log("Error getting PPP logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get system logs (general log entries)
     */
    public function getSystemLogs($limit = 100) {
        try {
            return $this->getLogEntries($limit);
        } catch (Exception $e) {
            error_log("Error getting system logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get single PPP secret by ID
     */
    public function getPPPSecret($id) {

        try {
            $encoded_id = urlencode($id);
            $result = $this->makeRequest('/ppp/secret/' . $encoded_id);

            if (is_array($result)) {
                if (count($result) === 1) {
                    return $result[0];
                }
                if (count($result) > 0) {
                    return $result[0];
                }
                return $this->findSecretById($id);
            }

            return $result;

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Find PPP secret by ID from all secrets (alternative method)
     */
    private function findSecretById($id) {

        try {
            $all_secrets = $this->getPPPSecrets();

            if (is_array($all_secrets)) {
                foreach ($all_secrets as $secret) {
                    if (isset($secret['.id']) && $secret['.id'] === $id) {
                        return $secret;
                    }
                }
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get firewall NAT rules by comment
     */
    public function getFirewallNATByComment($comment) {
        $nat_rules = $this->getFirewallNAT();
        $filtered_rules = [];

        if (is_array($nat_rules) && count($nat_rules) > 0) {
        }

        if (is_array($nat_rules)) {
            foreach ($nat_rules as $rule) {
                if (isset($rule['comment']) && $rule['comment'] === $comment) {
                    $filtered_rules[] = $rule;
                }
            }
        }

        return $filtered_rules;
    }

    /**
     * Get firewall NAT rules by IP address (to-addresses field)
     */
    public function getFirewallNATByIP($ip_address) {
        $nat_rules = $this->getFirewallNAT();
        $filtered_rules = [];

        if (is_array($nat_rules)) {
            foreach ($nat_rules as $rule) {
                $rule_ip = isset($rule['to-addresses']) ? $rule['to-addresses'] : 'N/A';

                if (isset($rule['to-addresses']) && $rule['to-addresses'] === $ip_address) {
                    $filtered_rules[] = $rule;
                }
            }
        }

        return $filtered_rules;
    }

    /**
     * Delete firewall NAT rules by comment
     */
    public function deleteFirewallNATByComment($comment) {
        error_log("[MIKROTIK NAT] Searching for NAT rules with comment: $comment");

        $nat_rules = $this->getFirewallNATByComment($comment);
        $deleted_count = 0;

        error_log("[MIKROTIK NAT] Found " . count($nat_rules) . " NAT rules to delete");

        foreach ($nat_rules as $rule) {
            if (isset($rule['.id'])) {
                try {
                    error_log("[MIKROTIK NAT] Deleting NAT rule ID: {$rule['.id']} for comment: $comment");
                    $result = $this->deleteFirewallNAT($rule['.id']);
                    if ($result !== false) {
                        $deleted_count++;
                        error_log("[MIKROTIK NAT] Successfully deleted NAT rule ID: {$rule['.id']}");
                    } else {
                        error_log("[MIKROTIK NAT] Failed to delete NAT rule ID: {$rule['.id']} (returned false)");
                    }
                } catch (Exception $e) {
                    error_log("[MIKROTIK NAT] Error deleting NAT rule {$rule['.id']}: " . $e->getMessage());
                }
            }
        }

        error_log("[MIKROTIK NAT] Successfully deleted $deleted_count NAT rules for comment: $comment");
        return $deleted_count;
    }

    /**
     * Delete firewall NAT rules by IP address (to-addresses)
     */
    public function deleteFirewallNATByIP($ip_address) {
        error_log("[MIKROTIK NAT] Searching for NAT rules with to-addresses: $ip_address");

        $nat_rules = $this->getFirewallNATByIP($ip_address);
        $deleted_count = 0;

        error_log("[MIKROTIK NAT] Found " . count($nat_rules) . " NAT rules to delete by IP");

        foreach ($nat_rules as $rule) {
            if (isset($rule['.id'])) {
                try {
                    error_log("[MIKROTIK NAT] Deleting NAT rule ID: {$rule['.id']} for IP: $ip_address");
                    $result = $this->deleteFirewallNAT($rule['.id']);
                    if ($result !== false) {
                        $deleted_count++;
                        error_log("[MIKROTIK NAT] Successfully deleted NAT rule ID: {$rule['.id']}");
                    } else {
                        error_log("[MIKROTIK NAT] Failed to delete NAT rule ID: {$rule['.id']} (returned false)");
                    }
                } catch (Exception $e) {
                    error_log("[MIKROTIK NAT] Error deleting NAT rule {$rule['.id']}: " . $e->getMessage());
                }
            }
        }

        error_log("[MIKROTIK NAT] Successfully deleted $deleted_count NAT rules for IP: $ip_address");
        return $deleted_count;
    }
}
