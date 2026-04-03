<?php
// Prevent any output before JSON
ob_start();

// Enable detailed error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to output
ini_set('log_errors', 1);

// Custom error handler to capture all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        error_log('Deprecated: ' . $errstr . ' in ' . $errfile . ':' . $errline);
        return true;
    }

    if (ob_get_level() > 0) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PHP Error: ' . $errstr,
        'debug' => [
            'file' => $errfile,
            'line' => $errline,
            'type' => $errno
        ]
    ]);
    exit;
});

// Custom exception handler
set_exception_handler(function($e) {
    if (ob_get_level() > 0) ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Uncaught Exception: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
    exit;
});

header('Content-Type: application/json');
require_once '../includes/session.php';
startSecureSession();


require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/mikrotik.php';
require_once '../includes/locks.php';
require_once '../includes/ppp_nat.php';
require_once '../includes/ppp_actions.php';
require_once '../includes/wireguard_actions.php';
require_once '../includes/qemu_hostfwd.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';


$input = [];
if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
        
    $input = json_decode($raw_input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON: ' . json_last_error_msg()
        ]);
        exit;
    }
    
    $action = $input['action'] ?? $action;
}

// All RouterOS actions in this endpoint are app-internal and require a valid session.
requireAuth();

try {
    dispatchAction($action, $input);
} catch (Exception $e) {
            
    // Clean any output buffer to remove stray content
    if (ob_get_level() > 0) {
        $buffer_content = ob_get_contents();
        if (!empty($buffer_content)) {
                    }
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => get_class($e)
    ]);
    exit;
}

function dispatchAction($action, $input) {
    $connection_actions = [
        'test_connection' => ['handler' => 'testConnection'],
        'test_qemu_hostfwd' => ['handler' => 'testQemuHostForward', 'requires_input' => true],
        'toggle_service' => ['handler' => 'toggleService', 'requires_input' => true],
        'test_vpn_service' => ['handler' => 'testVPNService', 'requires_input' => true],
        'send_backup' => ['handler' => 'sendBackup'],
        'create_service_profile' => ['handler' => 'createServiceProfile', 'requires_input' => true],
        'create_wireguard_interface' => ['handler' => 'createWireGuardInterface', 'requires_input' => true],
        'check_profiles_status' => ['handler' => 'checkProfilesStatus'],
        'check_nat_status' => ['handler' => 'checkNATStatus'],
        'create_nat_masquerade' => ['handler' => 'createNATMasquerade'],
        'system_resource' => ['handler' => 'getSystemResource'],
        'ppp_stats' => ['handler' => 'getPPPStats'],
        'ppp_logs' => ['handler' => 'getPPPLogs'],
        'get_available_services' => ['handler' => 'getAvailableServices'],
        'simple_test' => ['handler' => 'simpleTest'],
    ];

    $ppp_actions = [
        'get_ppp_users' => ['handler' => 'getPPPUsers'],
        'get_ppp_profiles' => ['handler' => 'getPPPProfiles'],
        'get_ppp_active' => ['handler' => 'getPPPActive'],
        'get_nat_by_user' => ['handler' => 'getNATByUser', 'requires_input' => true],
        'add_ppp_user' => ['handler' => 'addPPPUser', 'requires_input' => true],
        'edit_ppp_user' => ['handler' => 'editPPPUser', 'requires_input' => true],
        'delete_ppp_user' => ['handler' => 'deletePPPUser', 'requires_input' => true],
        'toggle_ppp_user_status' => ['handler' => 'togglePPPUserStatus', 'requires_input' => true],
        'bulk_delete_ppp_users' => ['handler' => 'bulkDeletePPPUsers', 'requires_input' => true],
        'bulk_toggle_ppp_users' => ['handler' => 'bulkTogglePPPUsers', 'requires_input' => true],
        'get_user_details' => ['handler' => 'getUserDetails', 'requires_input' => true],
        'get_user_password' => ['handler' => 'getUserPassword', 'requires_input' => true],
        'get_available_ip' => ['handler' => 'getAvailableIP', 'requires_input' => true],
    ];

    $wireguard_actions = [
        'get_wireguard_peers' => ['handler' => 'getWireGuardPeers'],
        'add_wireguard_peer' => ['handler' => 'addWireGuardPeer', 'requires_input' => true],
        'edit_wireguard_peer' => ['handler' => 'editWireGuardPeer', 'requires_input' => true],
        'delete_wireguard_peer' => ['handler' => 'deleteWireGuardPeer', 'requires_input' => true],
        'toggle_wireguard_peer_status' => ['handler' => 'toggleWireGuardPeerStatus', 'requires_input' => true],
        'bulk_delete_wireguard_peers' => ['handler' => 'bulkDeleteWireGuardPeers', 'requires_input' => true],
        'bulk_toggle_wireguard_peers' => ['handler' => 'bulkToggleWireGuardPeers', 'requires_input' => true],
        'get_wireguard_peer_details' => ['handler' => 'getWireGuardPeerDetails', 'requires_input' => true],
        'get_available_wireguard_ip' => ['handler' => 'getAvailableWireGuardIP', 'requires_input' => true],
    ];

    $monitoring_actions = [
        'get_netwatch' => ['handler' => 'getNetwatch'],
        'add_netwatch' => ['handler' => 'addNetwatch', 'requires_input' => true],
        'delete_netwatch' => ['handler' => 'deleteNetwatch', 'requires_input' => true],
    ];

    $action_map = $connection_actions + $ppp_actions + $wireguard_actions + $monitoring_actions;

    if (!isset($action_map[$action])) {
        throw new Exception('Invalid action: ' . $action);
    }

    $definition = $action_map[$action];

    if (!empty($definition['requires_input']) && empty($input)) {
        throw new Exception('No input data provided for ' . $action);
    }

    $handler = $definition['handler'];
    $locked_actions = [
        'add_ppp_user',
        'edit_ppp_user',
        'delete_ppp_user',
        'toggle_ppp_user_status',
        'bulk_delete_ppp_users',
        'bulk_toggle_ppp_users',
        'add_wireguard_peer',
        'edit_wireguard_peer',
        'delete_wireguard_peer',
        'toggle_wireguard_peer_status',
        'bulk_delete_wireguard_peers',
        'bulk_toggle_wireguard_peers',
        'add_netwatch',
        'delete_netwatch',
        'toggle_service',
        'create_service_profile',
        'create_wireguard_interface',
        'create_nat_masquerade',
    ];

    $execute = function () use ($definition, $handler, $input) {
        if (!empty($definition['requires_input'])) {
            $handler($input);
            return;
        }

        $handler();
    };

    if (in_array($action, $locked_actions, true)) {
        withAppLock('router-mutation', $execute, 20);
        return;
    }

    $execute();
}

function simpleTest() {
    echo json_encode([
        'success' => true,
        'message' => 'Simple test working',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

function testConnection() {
    try {
        $mikrotik = new MikroTikAPI();
        $result = $mikrotik->testConnection();
        
        if ($result['success']) {
            // Update service statuses in config
            $services = $mikrotik->getVPNServicesStatus();
            updateConfigSection('services', $services);
            
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['data']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message']
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function testQemuHostForward($input) {
    try {
        $saved_config = getConfig('mikrotik') ?: [];
        $runtime_config = array_merge($saved_config, is_array($input) ? $input : []);

        if (isset($runtime_config['qemu_ssh_private_key']) && $runtime_config['qemu_ssh_private_key'] === '••••••••') {
            $runtime_config['qemu_ssh_private_key'] = $saved_config['qemu_ssh_private_key'] ?? '';
        }

        $manager = getQemuHostFwdManager($runtime_config);
        $result = $manager->testAccess();

        if (!empty($result['success'])) {
            echo json_encode([
                'success' => true,
                'message' => $result['message'] ?? 'QEMU host forward access is working',
                'output' => $result['output'] ?? '',
            ]);
            return;
        }

        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to access QEMU host forward monitor'
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function toggleService($input) {
    
    try {
        $service = $input['service'] ?? '';
        $enable = $input['enable'] ?? false;
        
        
        if (empty($service)) {
            throw new Exception('Service parameter required');
        }
        
        if (!in_array($service, ['l2tp', 'pptp', 'sstp', 'wireguard'], true)) {
            throw new Exception('Invalid service type: ' . $service);
        }
        
        $mikrotik = new MikroTikAPI();

        if ($service === 'wireguard' && $enable) {
            $mikrotik_config = getConfig('mikrotik') ?? [];
            $interface_name = trim((string)($mikrotik_config['wireguard_interface'] ?? 'wireguard1'));

            if ($mikrotik->getWireGuardInterface($interface_name) === null) {
                $mikrotik->createOrUpdateWireGuardInterface([
                    'name' => $interface_name,
                    'listen_port' => $mikrotik_config['wireguard_port'] ?? '13231',
                    'mtu' => $mikrotik_config['wireguard_mtu'] ?? '1420',
                    'comment' => 'Managed by MikReMan'
                ]);
            }
        }
        
        // Toggle service on MikroTik
        $mikrotik->toggleVPNService($service, $enable);
        
        // Update service status in config
        $result = updateConfig('services', $service, $enable);
        
        if (!$result) {
            throw new Exception('Service toggled on MikroTik but failed to update local configuration');
        }
        
        $service_labels = [
            'l2tp' => 'L2TP',
            'pptp' => 'PPTP',
            'sstp' => 'SSTP',
            'wireguard' => 'WireGuard',
        ];
        $message = ($service_labels[$service] ?? ucfirst($service)) . ' service ' . ($enable ? 'enabled' : 'disabled') . ' successfully';
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function testVPNService($input) {
    try {
        $service = strtolower($input['service'] ?? '');

        if (!in_array($service, ['l2tp', 'pptp', 'sstp', 'wireguard'], true)) {
            throw new Exception('Invalid service type: ' . $service);
        }

        $mikrotik = new MikroTikAPI();
        $service_enabled = false;
        $wireguard_interface = trim((string)(getConfig('mikrotik', 'wireguard_interface') ?? 'wireguard1'));
        $wireguard_details = null;

        switch ($service) {
            case 'l2tp':
                $service_enabled = $mikrotik->getL2TPServerStatus();
                break;
            case 'pptp':
                $service_enabled = $mikrotik->getPPTPServerStatus();
                break;
            case 'sstp':
                $service_enabled = $mikrotik->getSSTServerStatus();
                break;
            case 'wireguard':
                $wireguard_details = $mikrotik->getWireGuardInterface($wireguard_interface);
                $service_enabled = $mikrotik->getWireGuardInterfaceStatus($wireguard_interface);
                break;
        }

        $mikrotik_config = getConfig('mikrotik') ?? [];
        $host = $mikrotik_config['host'] ?? '';
        $endpoint_data = getPublishedServiceEndpoint($service, $host, $mikrotik_config);
        $probe = probePublishedServiceEndpoint($endpoint_data);

        $notes = [];
        if ($service === 'l2tp') {
            $notes[] = 'L2TP in Docker usually also needs IPsec UDP ports 500 (IKE) and 4500 (NAT-T).';
            $notes[] = 'UDP reachability is not probed here because a simple socket check is not reliable for L2TP/IPsec.';
        } elseif ($service === 'pptp') {
            $notes[] = 'PPTP also relies on GRE in addition to TCP 1723, so a TCP-open result alone is not a full end-to-end validation.';
        } elseif ($service === 'sstp') {
            $notes[] = 'SSTP is tested via the published TCP endpoint only. Authentication and certificate validity are outside this quick probe.';
        } elseif ($service === 'wireguard') {
            $notes[] = 'WireGuard uses UDP, so this quick test does not perform a reliable public port reachability probe.';
            $notes[] = 'Provision the interface first, then manage peers separately. This phase only covers the server interface.';
            if (!$wireguard_details) {
                $notes[] = 'Configured interface "' . $wireguard_interface . '" was not found on the router.';
            }
        }

        echo json_encode([
            'success' => true,
            'message' => strtoupper($service) . ' service test completed',
            'data' => [
                'service' => $service,
                'enabled' => $service_enabled,
                'endpoint' => $endpoint_data['display'],
                'probe' => $probe,
                'notes' => $notes,
                'details' => $wireguard_details ? [
                    'name' => $wireguard_details['name'] ?? $wireguard_interface,
                    'listen_port' => $wireguard_details['listen-port'] ?? '',
                    'public_key' => $wireguard_details['public-key'] ?? '',
                ] : null,
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function getPublishedServiceEndpoint($service, $host, $mikrotik_config) {
    $service_hosts = [
        'l2tp' => trim((string)($mikrotik_config['l2tp_host'] ?? '')),
        'pptp' => trim((string)($mikrotik_config['pptp_host'] ?? '')),
        'sstp' => trim((string)($mikrotik_config['sstp_host'] ?? '')),
        'wireguard' => trim((string)($mikrotik_config['wireguard_host'] ?? '')),
    ];

    $ports = [
        'l2tp' => (string)($mikrotik_config['l2tp_port'] ?? '1701'),
        'pptp' => (string)($mikrotik_config['pptp_port'] ?? '1723'),
        'sstp' => (string)($mikrotik_config['sstp_port'] ?? '443'),
        'wireguard' => (string)($mikrotik_config['wireguard_port'] ?? '13231'),
    ];

    $protocols = [
        'l2tp' => 'udp',
        'pptp' => 'tcp',
        'sstp' => 'tcp',
        'wireguard' => 'udp',
    ];

    $resolved_host = $service_hosts[$service] !== '' ? $service_hosts[$service] : $host;
    $port = $ports[$service] ?? '';
    $protocol = $protocols[$service] ?? 'tcp';
    $display = $resolved_host !== '' ? sprintf('%s:%s/%s', $resolved_host, $port, $protocol) : sprintf('[host]:%s/%s', $port, $protocol);

    return [
        'host' => $resolved_host,
        'port' => $port,
        'protocol' => $protocol,
        'display' => $display,
    ];
}

function probePublishedServiceEndpoint($endpoint_data) {
    $host = $endpoint_data['host'] ?? '';
    $port = (int)($endpoint_data['port'] ?? 0);
    $protocol = $endpoint_data['protocol'] ?? 'tcp';

    if ($host === '' || $port <= 0) {
        return [
            'supported' => false,
            'reachable' => false,
            'message' => 'Host or published port is not configured.'
        ];
    }

    if ($protocol !== 'tcp') {
        return [
            'supported' => false,
            'reachable' => false,
            'message' => 'Quick probe is only available for TCP endpoints.'
        ];
    }

    $timeout = 3;
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

    if (is_resource($socket)) {
        fclose($socket);
        return [
            'supported' => true,
            'reachable' => true,
            'message' => 'TCP port is reachable from the application server.'
        ];
    }

    return [
        'supported' => true,
        'reachable' => false,
        'message' => trim($errstr) !== '' ? $errstr : ('Connection failed with error ' . $errno)
    ];
}

function sendBackup() {
    try {
        
        $telegram_config = getConfig('telegram');
        
        if (!$telegram_config || empty($telegram_config['bot_token']) || empty($telegram_config['chat_id'])) {
            throw new Exception('Telegram bot not configured. Please set bot token and chat ID first.');
        }
        
        if (!$telegram_config['enabled']) {
            throw new Exception('Telegram bot is not enabled. Please enable it in settings.');
        }
        
        $mikrotik = new MikroTikAPI();
        
        // Generate backup filename
        $filename = 'backup-' . date('Y-m-d-H-i-s') . '.rsc';
        
        // Export configuration and get content
        
        $export_data = $mikrotik->exportConfigurationForTelegram($filename);
        
        if (!$export_data || !isset($export_data['content'])) {
            throw new Exception('Failed to export configuration from MikroTik');
        }
        
        
        // Prepare content for Telegram
        $content = $export_data['content'];
        if (is_array($content)) {
            // If content is array (from API response), convert to string
            $content = implode("\n", $content);
        }
        
        if (empty($content) || strlen(trim($content)) < 10) {
            throw new Exception('Export content is empty or too small. Please check MikroTik connection.');
        }
        
        // Create caption with system info
        $system_info = $mikrotik->getSystemResource();
        $caption = "📦 *MikroTik Configuration Backup*\n\n";
        $caption .= "🗓 *Date:* " . date('Y-m-d H:i:s') . "\n";
        
        if ($system_info && is_array($system_info) && !empty($system_info)) {
            $resource = $system_info[0] ?? $system_info;
            if (isset($resource['board-name'])) {
                $caption .= "🖥 *Board:* " . $resource['board-name'] . "\n";
            }
            if (isset($resource['version'])) {
                $caption .= "⚙️ *Version:* " . $resource['version'] . "\n";
            }
            if (isset($resource['uptime'])) {
                $caption .= "⏱ *Uptime:* " . $resource['uptime'] . "\n";
            }
        }
        
        $caption .= "\n📄 *File:* " . $filename;
        
        
        // Send to Telegram using internal API
        $telegram_data = [
            'filename' => $filename,
            'content' => $content,
            'caption' => $caption
        ];
        
        // Call Telegram API directly
        $telegram_result = sendFileToTelegram($telegram_data);
        
        if (!$telegram_result) {
            throw new Exception('Failed to send backup to Telegram');
        }
        
        
        echo json_encode([
            'success' => true,
            'message' => "✅ Backup created and sent to Telegram successfully!\n\n📄 File: {$filename}\n📤 Sent to chat ID: {$telegram_config['chat_id']}"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function sendFileToTelegram($data) {
    $telegram_config = getConfig('telegram');
    $bot_token = $telegram_config['bot_token'];
    $chat_id = $telegram_config['chat_id'];
    
    // Create temporary file
    $temp_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $data['filename'];
    file_put_contents($temp_file, $data['content']);
    
    $send_url = "https://api.telegram.org/bot{$bot_token}/sendDocument";
    
    // Prepare multipart form data
    $post_data = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($temp_file, 'text/plain', $data['filename']),
        'caption' => $data['caption'] ?? '',
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $send_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    // Clean up temporary file
    if (file_exists($temp_file)) {
        unlink($temp_file);
    }
    
    if ($error) {
        return false;
    }
    
    if ($http_code !== 200) {
        return false;
    }
    
    $result = json_decode($response, true);
    
    if (!$result || !$result['ok']) {
        $error_msg = isset($result['description']) ? $result['description'] : 'Unknown error';
        return false;
    }
    
    return true;
}

function createServiceProfile($input) {
    
    try {
        $service = $input['service'] ?? '';
        
        
        if (empty($service)) {
            throw new Exception('Service parameter required');
        }
        
        if (!in_array(strtolower($service), ['l2tp', 'pptp', 'sstp'], true)) {
            throw new Exception('Invalid service type: ' . $service);
        }
        
        $mikrotik = new MikroTikAPI();
        
        
        // Create the service profile
        $result = $mikrotik->createServiceProfile($service);
        
        if (!$result) {
            throw new Exception('Failed to create profile for service: ' . $service);
        }
        
        $profile_name = strtoupper($service);
        $message = "✅ {$profile_name} profile created successfully and set as default for {$profile_name} server!";
        
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'service' => $service,
            'profile_name' => $profile_name
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function createWireGuardInterface($input) {
    try {
        $mikrotik = new MikroTikAPI();
        $mikrotik_config = getConfig('mikrotik') ?? [];
        $runtime_config = array_merge($mikrotik_config, is_array($input) ? $input : []);
        $interface = $mikrotik->createOrUpdateWireGuardInterface([
            'name' => $runtime_config['wireguard_interface'] ?? 'wireguard1',
            'listen_port' => $runtime_config['wireguard_port'] ?? '13231',
            'mtu' => $runtime_config['wireguard_mtu'] ?? '1420',
            'server_address' => $runtime_config['wireguard_server_address'] ?? '',
            'comment' => 'Managed by MikReMan'
        ]);
        $server_address = $mikrotik->getWireGuardServerAddress($interface['name'] ?? ($runtime_config['wireguard_interface'] ?? 'wireguard1'));

        $interface_name = $interface['name'] ?? ($runtime_config['wireguard_interface'] ?? 'wireguard1');
        updateConfig('services', 'wireguard', $mikrotik->getWireGuardInterfaceStatus($interface_name));

        echo json_encode([
            'success' => true,
            'message' => 'WireGuard interface provisioned successfully',
            'data' => [
                'name' => $interface_name,
                'listen_port' => $interface['listen-port'] ?? ($runtime_config['wireguard_port'] ?? '13231'),
                'public_key' => $interface['public-key'] ?? '',
                'server_address' => $server_address['address'] ?? ($runtime_config['wireguard_server_address'] ?? '')
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function checkProfilesStatus() {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        $profiles = $mikrotik->getPPPProfiles();
        
        $profile_status = [
            'l2tp' => false,
            'pptp' => false,
            'sstp' => false
        ];
        
        // Check if service profiles exist
        foreach ($profiles as $profile) {
            if (isset($profile['name'])) {
                $name = $profile['name'];
                if ($name === 'L2TP') {
                    $profile_status['l2tp'] = true;
                } elseif ($name === 'PPTP') {
                    $profile_status['pptp'] = true;
                } elseif ($name === 'SSTP') {
                    $profile_status['sstp'] = true;
                }
            }
        }
        
        
        echo json_encode([
            'success' => true,
            'profiles' => $profile_status
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'profiles' => [
                'l2tp' => false,
                'pptp' => false,
                'sstp' => false
            ]
        ]);
    }
}

function checkNATStatus() {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        $nat_exists = $mikrotik->checkMasqueradeNAT();
        
        echo json_encode([
            'success' => true,
            'nat_exists' => $nat_exists
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'nat_exists' => false
        ]);
    }
}

function createNATMasquerade() {
    
    try {
        $mikrotik = new MikroTikAPI();
        
        // Check if masquerade NAT already exists
        $nat_exists = $mikrotik->checkMasqueradeNAT();
        
        if ($nat_exists) {
            echo json_encode([
                'success' => true,
                'message' => 'Masquerade NAT rule already exists',
                'already_exists' => true
            ]);
            return;
        }
        
        // Create masquerade NAT rule
        $result = $mikrotik->createMasqueradeNAT();
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Masquerade NAT rule created successfully',
                'created' => true
            ]);
        } else {
            throw new Exception('Failed to create masquerade NAT rule');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function getSystemResource() {
    
    try {
        $mikrotik = new MikroTikAPI();
        $result = $mikrotik->getSystemResource();
        
        if ($result && is_array($result) && !empty($result)) {
            // If result is array, get first element
            $data = isset($result[0]) ? $result[0] : $result;
            
            echo json_encode([
                'success' => true,
                'data' => $data
            ]);
        } else {
            throw new Exception('Failed to get system resource data');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Get Netwatch entries
 */
function getNetwatch() {
    try {
        $mikrotik = new MikroTikAPI();
        $entries = $mikrotik->getNetwatchEntries();

        ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $entries ?? []
        ]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Add Netwatch entry
 */
function addNetwatch($input) {
    try {
        $host = $input['host'] ?? null;
        $name = $input['name'] ?? '';
        $interval = $input['interval'] ?? '10s';
        $timeout = $input['timeout'] ?? '5s';

        if (empty($host)) {
            throw new Exception('Host is required');
        }

        $mikrotik = new MikroTikAPI();

        $data = [
            'host' => $host,
            'interval' => $interval,
            'timeout' => $timeout
        ];

        if (!empty($name)) {
            $data['comment'] = $name;
        }

        $result = $mikrotik->addNetwatch($data);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Netwatch host added successfully',
            'data' => $result
        ]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Delete Netwatch entry
 */
function deleteNetwatch($input) {
    try {
        $id = $input['id'] ?? null;

        if (empty($id)) {
            throw new Exception('Netwatch ID is required');
        }

        $mikrotik = new MikroTikAPI();
        $mikrotik->deleteNetwatch($id);

        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Netwatch host deleted successfully'
        ]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function generateRandomPort($mikrotik) {
    $max_attempts = 100;
    $attempt = 0;

    do {
        $port = rand(16000, 20000);
        $attempt++;

        try {
            // Check if port is already used in NAT rules
            $existing_nats = $mikrotik->getFirewallNAT();
            $port_exists = false;

            if (is_array($existing_nats)) {
                foreach ($existing_nats as $nat) {
                    if (isset($nat['dst-port']) && $nat['dst-port'] == $port) {
                        $port_exists = true;
                        break;
                    }
                }
            }
            
            if (!$port_exists) {
                return $port;
            }
        } catch (Exception $e) {
            // If we can't check, just return the random port
            return $port;
        }
    } while ($attempt < $max_attempts);
    
    // If all attempts failed, return a random port anyway
    return rand(16000, 20000);
}


?>
