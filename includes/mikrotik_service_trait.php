<?php

trait MikroTikServiceTrait
{
    public function runScript($script) {
        return $this->makeRequest('/execute', 'POST', [
            'script' => $script
        ]);
    }

    public function getSystemSchedulers() {
        return $this->makeRequest('/system/scheduler');
    }

    public function addSystemScheduler(array $data) {
        return $this->makeRequest('/system/scheduler', 'PUT', $data);
    }

    public function deleteSystemScheduler($id) {
        return $this->makeRequest('/system/scheduler/' . $id, 'DELETE');
    }

    public function deleteSystemSchedulerByName($name) {
        $schedulers = $this->getSystemSchedulers();

        if (!is_array($schedulers)) {
            return 0;
        }

        $deleted = 0;
        foreach ($schedulers as $scheduler) {
            if (($scheduler['name'] ?? '') !== $name || empty($scheduler['.id'])) {
                continue;
            }

            $this->deleteSystemScheduler($scheduler['.id']);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Test connection to MikroTik
     */
    public function testConnection() {
        try {
            $result = $this->makeRequest('/system/resource');

            if (is_array($result) && !empty($result)) {
                $resource = $result[0] ?? $result;
                return [
                    'success' => true,
                    'message' => 'Connected successfully to ' . ($resource['board-name'] ?? 'MikroTik Router'),
                    'data' => [
                        'board' => $resource['board-name'] ?? 'Unknown',
                        'version' => $resource['version'] ?? 'Unknown',
                        'architecture' => $resource['architecture-name'] ?? 'Unknown',
                        'uptime' => $resource['uptime'] ?? 'Unknown'
                    ]
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'data' => []
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * Get system resource information
     */
    public function getSystemResource() {
        return $this->makeRequest('/system/resource');
    }

    /**
     * Get L2TP server status
     */
    public function getL2TPServerStatus() {
        try {
            $result = $this->makeRequest('/interface/l2tp-server/server');

            if (is_array($result) && isset($result['enabled'])) {
                $enabled = $result['enabled'];
                return $enabled === 'true' || $enabled === true;
            } elseif (is_array($result) && count($result) > 0 && isset($result[0]['enabled'])) {
                $enabled = $result[0]['enabled'];
                return $enabled === 'true' || $enabled === true;
            }
            return false;
        } catch (Exception $e) {
            error_log("L2TP status error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get PPTP server status
     */
    public function getPPTPServerStatus() {
        try {
            $result = $this->makeRequest('/interface/pptp-server/server');

            if (is_array($result) && isset($result['enabled'])) {
                $enabled = $result['enabled'];
                return $enabled === 'true' || $enabled === true;
            } elseif (is_array($result) && count($result) > 0 && isset($result[0]['enabled'])) {
                $enabled = $result[0]['enabled'];
                return $enabled === 'true' || $enabled === true;
            }
            return false;
        } catch (Exception $e) {
            error_log("PPTP status error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get SSTP server status
     */
    public function getSSTServerStatus() {
        try {
            $result = $this->makeRequest('/interface/sstp-server/server');

            if (is_array($result) && isset($result['enabled'])) {
                $enabled = $result['enabled'];
                return $enabled === 'true' || $enabled === true;
            } elseif (is_array($result) && count($result) > 0 && isset($result[0]['enabled'])) {
                $enabled = $result[0]['enabled'];
                return $enabled === 'true' || $enabled === true;
            }
            return false;
        } catch (Exception $e) {
            error_log("SSTP status error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Toggle L2TP server
     */
    public function toggleL2TPServer($enable = true) {
        try {
            error_log("toggleL2TPServer called with enable: " . ($enable ? 'true' : 'false'));

            $enabled_value = $enable ? 'yes' : 'no';
            $command = "/interface l2tp-server server set enabled=$enabled_value";

            error_log("Executing L2TP command: $command");

            $result = $this->makeRequest('/execute', 'POST', [
                'script' => $command
            ]);

            error_log("L2TP command result: " . json_encode($result));

            $verify_result = $this->getL2TPServerStatus();
            error_log("L2TP status after toggle: " . ($verify_result ? 'enabled' : 'disabled'));

            return $result;
        } catch (Exception $e) {
            error_log("L2TP toggle error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Toggle PPTP server
     */
    public function togglePPTPServer($enable = true) {
        try {
            error_log("togglePPTPServer called with enable: " . ($enable ? 'true' : 'false'));

            $enabled_value = $enable ? 'yes' : 'no';
            $command = "/interface pptp-server server set enabled=$enabled_value";

            error_log("Executing PPTP command: $command");

            $result = $this->makeRequest('/execute', 'POST', [
                'script' => $command
            ]);

            error_log("PPTP command result: " . json_encode($result));

            $verify_result = $this->getPPTPServerStatus();
            error_log("PPTP status after toggle: " . ($verify_result ? 'enabled' : 'disabled'));

            return $result;
        } catch (Exception $e) {
            error_log("PPTP toggle error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Toggle SSTP server
     */
    public function toggleSSTServer($enable = true) {
        try {
            error_log("toggleSSTServer called with enable: " . ($enable ? 'true' : 'false'));

            $enabled_value = $enable ? 'yes' : 'no';
            $command = "/interface sstp-server server set enabled=$enabled_value";

            error_log("Executing SSTP command: $command");

            $result = $this->makeRequest('/execute', 'POST', [
                'script' => $command
            ]);

            error_log("SSTP command result: " . json_encode($result));

            $verify_result = $this->getSSTServerStatus();
            error_log("SSTP status after toggle: " . ($verify_result ? 'enabled' : 'disabled'));

            return $result;
        } catch (Exception $e) {
            error_log("SSTP toggle error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get all VPN services status
     */
    public function getVPNServicesStatus() {
        return [
            'l2tp' => $this->getL2TPServerStatus(),
            'pptp' => $this->getPPTPServerStatus(),
            'sstp' => $this->getSSTServerStatus(),
            'wireguard' => $this->getWireGuardInterfaceStatus()
        ];
    }

    public function getRawServiceStatus($service) {
        switch (strtolower($service)) {
            case 'l2tp':
                return $this->makeRequest('/interface/l2tp-server/server');
            case 'pptp':
                return $this->makeRequest('/interface/pptp-server/server');
            case 'sstp':
                return $this->makeRequest('/interface/sstp-server/server');
            case 'wireguard':
                $interface = $this->getWireGuardInterface();
                if ($interface === null) {
                    throw new Exception('WireGuard interface not found');
                }
                return $interface;
            default:
                throw new Exception('Invalid service: ' . $service);
        }
    }

    /**
     * Toggle VPN service
     */
    public function toggleVPNService($service, $enable = true) {
        switch (strtolower($service)) {
            case 'l2tp':
                return $this->toggleL2TPServer($enable);
            case 'pptp':
                return $this->togglePPTPServer($enable);
            case 'sstp':
                return $this->toggleSSTServer($enable);
            case 'wireguard':
                return $this->toggleWireGuardInterface($enable);
            default:
                throw new Exception('Invalid service type: ' . $service);
        }
    }

    /**
     * Export configuration
     */
    public function exportConfiguration($filename = null) {
        if (!$filename) {
            $filename = 'backup-' . date('Y-m-d-H-i-s') . '.rsc';
        }

        $command = "/export compact file=$filename";

        return $this->makeRequest('/execute', 'POST', [
            'script' => $command
        ]);
    }

    /**
     * Get exported file content
     */
    public function getExportedFile($filename) {
        $files = $this->makeRequest('/file');

        $found_file = null;
        foreach ($files as $file) {
            if (isset($file['name']) && $file['name'] === $filename) {
                $found_file = $file;
                break;
            }
        }

        if (!$found_file) {
            throw new Exception("Export file not found: $filename");
        }

        return [
            'filename' => $filename,
            'size' => $found_file['size'] ?? 0,
            'created' => $found_file['creation-time'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Export configuration and get content for Telegram
     */
    public function exportConfigurationForTelegram($filename = null) {
        if (!$filename) {
            $filename = 'backup-' . date('Y-m-d-H-i-s') . '.rsc';
        }

        error_log("exportConfigurationForTelegram: Starting export for $filename");

        try {
            $backup_content = $this->createConfigurationBackup();

            if (empty($backup_content)) {
                throw new Exception('Failed to create configuration backup');
            }

            error_log("exportConfigurationForTelegram: Backup content length: " . strlen($backup_content));

            $this->exportConfiguration($filename);

            return [
                'filename' => $filename,
                'content' => $backup_content,
                'success' => true
            ];

        } catch (Exception $e) {
            error_log("exportConfigurationForTelegram error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create comprehensive configuration backup
     */
    private function createConfigurationBackup() {
        $backup_content = "# MikroTik Configuration Backup\n";
        $backup_content .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
        $backup_content .= "# Source: VPN Remote Manager\n\n";

        try {
            $system_info = $this->getSystemResource();
            if ($system_info && !empty($system_info)) {
                $resource = is_array($system_info) ? ($system_info[0] ?? $system_info) : $system_info;
                $backup_content .= "# ===== SYSTEM INFORMATION =====\n";
                if (isset($resource['board-name'])) {
                    $backup_content .= "# Board: " . $resource['board-name'] . "\n";
                }
                if (isset($resource['version'])) {
                    $backup_content .= "# RouterOS Version: " . $resource['version'] . "\n";
                }
                if (isset($resource['architecture-name'])) {
                    $backup_content .= "# Architecture: " . $resource['architecture-name'] . "\n";
                }
                if (isset($resource['cpu'])) {
                    $backup_content .= "# CPU: " . $resource['cpu'] . "\n";
                }
                if (isset($resource['uptime'])) {
                    $backup_content .= "# Uptime: " . $resource['uptime'] . "\n";
                }
                $backup_content .= "\n";
            }
        } catch (Exception $e) {
            $backup_content .= "# System info error: " . $e->getMessage() . "\n\n";
        }

        try {
            $ppp_users = $this->getPPPSecrets();
            $backup_content .= "# ===== PPP SECRETS (" . count($ppp_users) . " users) =====\n";
            foreach ($ppp_users as $user) {
                $backup_content .= "/ppp secret add";
                if (isset($user['name'])) $backup_content .= " name=\"" . $user['name'] . "\"";
                if (isset($user['service'])) $backup_content .= " service=" . $user['service'];
                if (isset($user['profile'])) $backup_content .= " profile=\"" . $user['profile'] . "\"";
                if (isset($user['remote-address'])) $backup_content .= " remote-address=" . $user['remote-address'];
                if (isset($user['comment'])) $backup_content .= " comment=\"" . $user['comment'] . "\"";
                if (isset($user['disabled']) && $user['disabled'] === 'true') $backup_content .= " disabled=yes";
                $backup_content .= "\n";
            }
            $backup_content .= "\n";
        } catch (Exception $e) {
            $backup_content .= "# PPP secrets error: " . $e->getMessage() . "\n\n";
        }

        try {
            $nat_rules = $this->getFirewallNAT();
            if (!empty($nat_rules)) {
                $backup_content .= "# ===== FIREWALL NAT RULES (" . count($nat_rules) . " rules) =====\n";
                foreach ($nat_rules as $rule) {
                    $backup_content .= "/ip firewall nat add";
                    if (isset($rule['chain'])) $backup_content .= " chain=" . $rule['chain'];
                    if (isset($rule['action'])) $backup_content .= " action=" . $rule['action'];
                    if (isset($rule['protocol'])) $backup_content .= " protocol=" . $rule['protocol'];
                    if (isset($rule['dst-port'])) $backup_content .= " dst-port=" . $rule['dst-port'];
                    if (isset($rule['to-addresses'])) $backup_content .= " to-addresses=" . $rule['to-addresses'];
                    if (isset($rule['to-ports'])) $backup_content .= " to-ports=" . $rule['to-ports'];
                    if (isset($rule['comment'])) $backup_content .= " comment=\"" . $rule['comment'] . "\"";
                    if (isset($rule['disabled']) && $rule['disabled'] === 'true') $backup_content .= " disabled=yes";
                    $backup_content .= "\n";
                }
                $backup_content .= "\n";
            }
        } catch (Exception $e) {
            $backup_content .= "# Firewall NAT error: " . $e->getMessage() . "\n\n";
        }

        try {
            $services = $this->getVPNServicesStatus();
            $backup_content .= "# ===== VPN SERVICES STATUS =====\n";
            $backup_content .= "# L2TP Server: " . ($services['l2tp'] ? 'enabled' : 'disabled') . "\n";
            $backup_content .= "# PPTP Server: " . ($services['pptp'] ? 'enabled' : 'disabled') . "\n";
            $backup_content .= "# SSTP Server: " . ($services['sstp'] ? 'enabled' : 'disabled') . "\n";
            $backup_content .= "# WireGuard Interface: " . ($services['wireguard'] ? 'enabled' : 'disabled') . "\n";
            $backup_content .= "\n";
        } catch (Exception $e) {
            $backup_content .= "# VPN services error: " . $e->getMessage() . "\n\n";
        }

        $backup_content .= "# ===== END OF BACKUP =====\n";
        $backup_content .= "# Generated by VPN Remote Manager\n";
        $backup_content .= "# Total lines: " . substr_count($backup_content, "\n") . "\n";

        return $backup_content;
    }
}
