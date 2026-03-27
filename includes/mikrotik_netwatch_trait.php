<?php

trait MikroTikNetwatchTrait
{
    /**
     * Get next available IP address for a service based on profile configuration
     */
    public function getNextAvailableIP($service) {
        error_log("[MIKROTIK IP] Getting next available IP for service: $service");

        try {
            $profile_mapping = [
                'l2tp' => 'L2TP',
                'pptp' => 'PPTP',
                'sstp' => 'SSTP',
                'any' => 'default'
            ];

            $profile_name = $profile_mapping[strtolower($service)] ?? 'default';

            $profiles = $this->getPPPProfiles();
            $target_profile = null;

            foreach ($profiles as $profile) {
                if (isset($profile['name']) && $profile['name'] === $profile_name) {
                    $target_profile = $profile;
                    break;
                }
            }

            if (!$target_profile) {
                error_log("[MIKROTIK IP] Profile $profile_name not found, using default ranges");
                $ip_ranges = $this->getDefaultIPRanges($service);
            } else {
                error_log("[MIKROTIK IP] Found profile: " . json_encode($target_profile));
                $ip_ranges = $this->extractIPRangeFromProfile($target_profile, $service);
            }

            $used_ips = $this->getUsedPPPIPs();
            error_log("[MIKROTIK IP] Used IPs: " . json_encode($used_ips));

            foreach ($ip_ranges as $range) {
                $available_ip = $this->findNextAvailableIPInRange($range, $used_ips);
                if ($available_ip) {
                    error_log("[MIKROTIK IP] Found available IP: $available_ip in range $range");
                    return $available_ip;
                }
            }

            error_log("[MIKROTIK IP] No available IP found in any range");
            return null;

        } catch (Exception $e) {
            error_log("[MIKROTIK IP] Error getting next available IP: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get default IP ranges for services
     */
    private function getDefaultIPRanges($service) {
        $default_ranges = [
            'l2tp' => ['10.51.0.0/24'],
            'pptp' => ['10.52.0.0/24'],
            'sstp' => ['10.53.0.0/24'],
            'any' => ['10.50.0.0/24']
        ];

        return $default_ranges[strtolower($service)] ?? ['10.50.0.0/24'];
    }

    /**
     * Extract IP range from profile configuration
     */
    private function extractIPRangeFromProfile($profile, $service) {
        $local_address = $profile['local-address'] ?? '';

        if (!empty($local_address)) {
            $parts = explode('.', $local_address);
            if (count($parts) >= 3) {
                $network = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
                error_log("[MIKROTIK IP] Extracted network from profile: $network");
                return [$network];
            }
        }

        return $this->getDefaultIPRanges($service);
    }

    /**
     * Get all used IP addresses from PPP secrets
     */
    private function getUsedPPPIPs() {
        try {
            $secrets = $this->getPPPSecrets();
            $used_ips = [];

            foreach ($secrets as $secret) {
                if (isset($secret['remote-address']) && !empty($secret['remote-address'])) {
                    $used_ips[] = $secret['remote-address'];
                }
            }

            return $used_ips;
        } catch (Exception $e) {
            error_log("[MIKROTIK IP] Error getting used PPP IPs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Find next available IP in given CIDR range
     */
    private function findNextAvailableIPInRange($cidr_range, $used_ips) {
        list($network, $prefix) = explode('/', $cidr_range);
        $prefix = (int)$prefix;

        $network_long = ip2long($network);
        $hosts = pow(2, 32 - $prefix) - 2;

        for ($i = 2; $i <= $hosts; $i++) {
            $ip_long = $network_long + $i;
            $ip = long2ip($ip_long);

            if ($i == $hosts) {
                continue;
            }

            if (!in_array($ip, $used_ips)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Create Netwatch entry for PPP user
     */
    public function createNetwatch($host, $comment) {
        try {
            $data = [
                'host' => $host,
                'comment' => $comment,
                'interval' => '00:01:00',
                'timeout' => '00:00:05'
            ];

            error_log("[MIKROTIK NETWATCH] Attempting to create netwatch for host: $host, comment: $comment");

            $response = $this->makeRequest('/tool/netwatch', 'PUT', $data);

            error_log("[MIKROTIK NETWATCH] Response: " . json_encode($response));

            if (isset($response['.id'])) {
                error_log("[MIKROTIK NETWATCH] Successfully created netwatch with ID: " . $response['.id']);
                return $response;
            }

            error_log("[MIKROTIK NETWATCH] Response did not contain .id field");
            return false;
        } catch (Exception $e) {
            error_log("[MIKROTIK NETWATCH] Exception caught: " . $e->getMessage());
            error_log("[MIKROTIK NETWATCH] Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Get all Netwatch entries
     */
    public function getNetwatchEntries() {
        return $this->makeRequest('/tool/netwatch');
    }

    /**
     * Delete Netwatch entry by comment
     */
    public function deleteNetwatchByComment($comment) {
        $netwatch_entries = $this->getNetwatchEntries();

        if (!is_array($netwatch_entries)) {
            return false;
        }

        $deleted_count = 0;
        foreach ($netwatch_entries as $entry) {
            if (isset($entry['comment']) && $entry['comment'] === $comment) {
                $this->makeRequest('/tool/netwatch/' . $entry['.id'], 'DELETE');
                $deleted_count++;
            }
        }

        return $deleted_count > 0;
    }

    /**
     * Delete Netwatch entry by host IP
     */
    public function deleteNetwatchByHost($host) {
        $netwatch_entries = $this->getNetwatchEntries();

        if (!is_array($netwatch_entries)) {
            return false;
        }

        $deleted_count = 0;
        foreach ($netwatch_entries as $entry) {
            if (isset($entry['host']) && $entry['host'] === $host) {
                $this->makeRequest('/tool/netwatch/' . $entry['.id'], 'DELETE');
                $deleted_count++;
            }
        }

        return $deleted_count > 0;
    }

    /**
     * Add Netwatch entry
     */
    public function addNetwatch($data) {
        return $this->makeRequest('/tool/netwatch', 'PUT', $data);
    }

    /**
     * Delete Netwatch entry by ID
     */
    public function deleteNetwatch($id) {
        return $this->makeRequest('/tool/netwatch/' . $id, 'DELETE');
    }
}
