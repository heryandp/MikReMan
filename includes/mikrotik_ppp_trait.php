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
}
