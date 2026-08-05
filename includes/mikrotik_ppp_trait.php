<?php

trait MikroTikPPPTrait
{
    /**
     * Get PPP secrets (users)
     */
    public function getPPPSecrets() {
        return $this->makeRequest('/ppp/secret');
    }

    /**
     * Get PPP profiles
     */
    public function getPPPProfiles() {
        return $this->makeRequest('/ppp/profile');
    }

    /**
     * Create PPP profile for specific VPN service
     */
    public function createServiceProfile($service) {
        $profiles = [
            'l2tp' => [
                'name' => 'L2TP',
                'local-address' => '10.51.0.1',
                'bridge-learning' => 'default',
                'use-ipv6' => 'no',
                'use-mpls' => 'no',
                'use-compression' => 'no',
                'use-encryption' => 'no',
                'only-one' => 'yes',
                'change-tcp-mss' => 'default',
                'use-upnp' => 'default',
                'address-list' => '',
                'on-up' => '',
                'on-down' => ''
            ],
            'pptp' => [
                'name' => 'PPTP',
                'local-address' => '10.52.0.1',
                'bridge-learning' => 'default',
                'use-ipv6' => 'no',
                'use-mpls' => 'no',
                'use-compression' => 'no',
                'use-encryption' => 'no',
                'only-one' => 'yes',
                'change-tcp-mss' => 'default',
                'use-upnp' => 'default',
                'address-list' => '',
                'on-up' => '',
                'on-down' => ''
            ],
            'sstp' => [
                'name' => 'SSTP',
                'local-address' => '10.53.0.1',
                'bridge-learning' => 'default',
                'use-ipv6' => 'no',
                'use-mpls' => 'no',
                'use-compression' => 'no',
                'use-encryption' => 'no',
                'only-one' => 'yes',
                'change-tcp-mss' => 'default',
                'use-upnp' => 'default',
                'address-list' => '',
                'on-up' => '',
                'on-down' => ''
            ]
        ];

        $service = strtolower($service);
        if (!isset($profiles[$service])) {
            throw new Exception('Invalid service type: ' . $service);
        }

        $profile_data = $profiles[$service];

        error_log("Creating PPP profile for service: $service");
        error_log("Profile data: " . json_encode($profile_data));

        $existing_profiles = $this->getPPPProfiles();
        foreach ($existing_profiles as $profile) {
            if (isset($profile['name']) && $profile['name'] === $profile_data['name']) {
                error_log("Profile {$profile_data['name']} already exists, updating instead");
                return $this->updatePPPProfile($profile['.id'], $profile_data);
            }
        }

        $result = $this->makeRequest('/ppp/profile', 'PUT', $profile_data);

        if ($result) {
            error_log("Profile {$profile_data['name']} created successfully");
            $this->setServiceDefaultProfile($service, $profile_data['name']);
        }

        return $result;
    }

    /**
     * Update existing PPP profile
     */
    public function updatePPPProfile($id, $data) {
        return $this->makeRequest('/ppp/profile/' . $id, 'PATCH', $data);
    }

    /**
     * Set default profile for VPN service
     */
    public function setServiceDefaultProfile($service, $profile_name) {
        error_log("Setting default profile for $service service to: $profile_name");

        $commands = [
            'l2tp' => "/interface l2tp-server server set default-profile=\"$profile_name\"",
            'pptp' => "/interface pptp-server server set default-profile=\"$profile_name\"",
            'sstp' => "/interface sstp-server server set default-profile=\"$profile_name\""
        ];

        $service = strtolower($service);
        if (!isset($commands[$service])) {
            throw new Exception('Invalid service type for default profile: ' . $service);
        }

        $command = $commands[$service];
        error_log("Executing command: $command");

        $result = $this->makeRequest('/execute', 'POST', [
            'script' => $command
        ]);

        error_log("Set default profile result: " . json_encode($result));

        return $result;
    }

    /**
     * Get PPP active sessions
     */
    public function getPPPActive() {
        return $this->makeRequest('/ppp/active');
    }

    /**
     * Get PPP active sessions with traffic statistics
     * Combines /ppp/active with /interface data to get traffic counters
     */
    public function getPPPActiveWithTraffic() {
        $activeSessions = $this->getPPPActive();
        $interfaces = $this->makeRequest('/interface');

        foreach ($activeSessions as &$session) {
            $patterns = [
                '<' . $session['service'] . '-' . $session['name'] . '>',
                $session['service'] . '-' . $session['name'],
                '<' . $session['name'] . '>',
                $session['name']
            ];

            $matched = false;

            foreach ($patterns as $pattern) {
                foreach ($interfaces as $interface) {
                    if ($interface['name'] === $pattern) {
                        $session['bytes-in'] = $interface['rx-byte'] ?? '0';
                        $session['bytes-out'] = $interface['tx-byte'] ?? '0';
                        $session['rx-byte'] = $interface['rx-byte'] ?? '0';
                        $session['tx-byte'] = $interface['tx-byte'] ?? '0';
                        $session['rx-packet'] = $interface['rx-packet'] ?? '0';
                        $session['tx-packet'] = $interface['tx-packet'] ?? '0';
                        $session['interface-name'] = $interface['name'];
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (!$matched) {
                $session['bytes-in'] = '0';
                $session['bytes-out'] = '0';
                $session['rx-byte'] = '0';
                $session['tx-byte'] = '0';
                $session['interface-name'] = 'not-found';
            }
        }

        return $activeSessions;
    }

    /**
     * Get RouterOS interface counters for dashboard traffic charts.
     */
    public function getInterfaceStats() {
        $interfaces = $this->makeRequest('/interface');

        if (!is_array($interfaces)) {
            return [];
        }

        $normalizeBoolean = static function ($value) {
            return $value === true || $value === 'true';
        };

        $stats = [];

        foreach ($interfaces as $interface) {
            $name = trim((string)($interface['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $rxByte = (int)($interface['rx-byte'] ?? 0);
            $txByte = (int)($interface['tx-byte'] ?? 0);
            $rxPacket = (int)($interface['rx-packet'] ?? 0);
            $txPacket = (int)($interface['tx-packet'] ?? 0);
            $running = $normalizeBoolean($interface['running'] ?? false);
            $disabled = $normalizeBoolean($interface['disabled'] ?? false);

            $stats[] = [
                'name' => $name,
                'type' => (string)($interface['type'] ?? 'unknown'),
                'running' => $running,
                'disabled' => $disabled,
                'rx_byte' => $rxByte,
                'tx_byte' => $txByte,
                'rx_packet' => $rxPacket,
                'tx_packet' => $txPacket,
                'total_byte' => $rxByte + $txByte,
                'mtu' => (string)($interface['mtu'] ?? ''),
                'actual_mtu' => (string)($interface['actual-mtu'] ?? ''),
                'mac_address' => (string)($interface['mac-address'] ?? ''),
                'comment' => (string)($interface['comment'] ?? ''),
            ];
        }

        usort($stats, static function ($left, $right) {
            if ($left['running'] !== $right['running']) {
                return $left['running'] ? -1 : 1;
            }

            if ($left['disabled'] !== $right['disabled']) {
                return $left['disabled'] ? 1 : -1;
            }

            if ($left['total_byte'] !== $right['total_byte']) {
                return $right['total_byte'] <=> $left['total_byte'];
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $stats;
    }
}
