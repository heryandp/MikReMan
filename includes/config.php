<?php

class ConfigManager {
    private $config_file;
    private $key_file;
    private $encryption_key;
    
    public function __construct() {
        $this->config_file = __DIR__ . '/../config/config.json.enc';
        $this->key_file = __DIR__ . '/../config/encryption.key';
        $this->initializeEncryption();
    }
    
    private function initializeEncryption() {
        // Create config directory if it doesn't exist
        $config_dir = dirname($this->config_file);
        if (!file_exists($config_dir)) {
            mkdir($config_dir, 0755, true);
        }
        
        // Generate or load encryption key
        if (!file_exists($this->key_file)) {
            $this->encryption_key = random_bytes(32); // 256-bit key for AES-256
            file_put_contents($this->key_file, base64_encode($this->encryption_key));
            chmod($this->key_file, 0600); // Read/write for owner only
        } else {
            $this->encryption_key = base64_decode(file_get_contents($this->key_file));
        }
        
        // Initialize default config if doesn't exist
        if (!file_exists($this->config_file)) {
            $this->createDefaultConfig();
        }
    }
    
    private function getDefaultConfig() {
        return [
            'auth' => [
                'username' => 'user1234',
                'password' => 'mostech', // Store plaintext for admin retrieval
                'password_hash' => password_hash('mostech', PASSWORD_DEFAULT) // Keep hash for verification
            ],
            'mikrotik' => [
                'host' => '',
                'username' => '',
                'password' => '',
                'port' => '443',
                'use_ssl' => true,
                'qemu_hostfwd_enabled' => false,
                'qemu_hostfwd_mode' => 'local',
                'qemu_hmp_socket' => '/opt/ros7-monitor/hmp.sock',
                'qemu_hostfwd_binary' => '/usr/bin/socat',
                'qemu_ssh_host' => '',
                'qemu_ssh_port' => '22',
                'qemu_ssh_user' => '',
                'qemu_ssh_private_key' => '',
                'qemu_ssh_known_hosts_path' => '',
                'qemu_ssh_binary' => '/usr/bin/ssh',
                'rest_http_port' => '7004',
                'rest_https_port' => '7005',
                'winbox_port' => '7000',
                'api_port' => '7001',
                'api_ssl_port' => '7002',
                'ssh_port' => '7003',
                'l2tp_port' => '1701',
                'l2tp_host' => '',
                'pptp_port' => '1723',
                'pptp_host' => '',
                'sstp_port' => '443',
                'sstp_host' => '',
                'wireguard_port' => '13231',
                'wireguard_host' => '',
                'wireguard_backend' => 'mikrotik',
                'wireguard_interface' => 'wireguard1',
                'wireguard_mtu' => '1420',
                'wireguard_server_address' => '10.66.66.1/24',
                'wireguard_client_dns' => '8.8.8.8, 8.8.4.4',
                'wireguard_allowed_ips' => '0.0.0.0/0, ::/0',
                'wireguard_keepalive' => '25',
                'wireguard_client_name_suffix' => '',
                'wg_easy_url' => '',
                'wg_easy_endpoint_host' => '',
                'wg_easy_endpoint_port' => '51820',
                'wg_easy_username' => '',
                'wg_easy_password' => '',
                'ipsec_port' => '500',
                'ipsec_nat_t_port' => '4500'
            ],
            'telegram' => [
                'bot_token' => '',
                'chat_id' => '',
                'enabled' => false
            ],
            'cloudflare' => [
                'turnstile_enabled' => false,
                'turnstile_login_enabled' => true,
                'turnstile_order_enabled' => true,
                'turnstile_site_key' => '',
                'turnstile_secret_key' => ''
            ],
            'system' => [
                'session_timeout' => 3600, // 60 minutes
                'app_version' => '1.69',
                'app_name' => 'MikReMan'
            ],
            'services' => [
                'l2tp' => false,
                'pptp' => false,
                'sstp' => false,
                'wireguard' => false
            ]
        ];
    }

    private function mergeDefaultConfig(array $config) {
        return array_replace_recursive($this->getDefaultConfig(), $config);
    }
    
    private function createDefaultConfig() {
        $default_config = $this->getDefaultConfig();
        
        $result = $this->saveConfig($default_config);
        if (!$result) {
            throw new Exception('Failed to create default configuration file');
        }
        
        return $default_config;
    }
    
    public function encrypt($data) {
        $iv = random_bytes(16); // AES block size
        $encrypted = openssl_encrypt(json_encode($data), 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public function decrypt($encrypted_data) {
        $data = base64_decode($encrypted_data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return json_decode($decrypted, true);
    }
    
    public function loadConfig() {
        if (!file_exists($this->config_file)) {
            // Create default config if file doesn't exist
            return $this->createDefaultConfig();
        }
        
        try {
            $encrypted_data = file_get_contents($this->config_file);
            if ($encrypted_data === false) {
                throw new Exception('Failed to read configuration file');
            }
            
            if (empty($encrypted_data)) {
                // If file is empty, recreate default config
                return $this->createDefaultConfig();
            }
            
            $decrypted = $this->decrypt($encrypted_data);
            if ($decrypted === null || $decrypted === false) {
                throw new Exception('Failed to decrypt configuration file');
            }
            
            $merged_config = $this->mergeDefaultConfig($decrypted);

            if ($merged_config !== $decrypted) {
                $this->saveConfig($merged_config);
            }

            return $merged_config;
        } catch (Exception $e) {
            // If decryption fails, backup old file and create new default config
            if (file_exists($this->config_file)) {
                rename($this->config_file, $this->config_file . '.backup.' . time());
            }
            return $this->createDefaultConfig();
        }
    }
    
    public function saveConfig($config) {
        $encrypted_data = $this->encrypt($config);
        $result = file_put_contents($this->config_file, $encrypted_data);
        if ($result !== false) {
            chmod($this->config_file, 0600); // Read/write for owner only
        }
        return $result !== false;
    }
    
    public function getConfig($section = null, $key = null) {
        $config = $this->loadConfig();
        
        if ($section === null) {
            return $config;
        }
        
        if (!isset($config[$section])) {
            return null;
        }
        
        if ($key === null) {
            return $config[$section];
        }
        
        return $config[$section][$key] ?? null;
    }
    
    public function updateConfig($section, $key, $value) {
        $config = $this->loadConfig();
        
        if ($config === null) {
            return false;
        }
        
        if (!isset($config[$section])) {
            $config[$section] = [];
        }
        
        $config[$section][$key] = $value;
        
        return $this->saveConfig($config);
    }
    
    public function updateSection($section, $data) {
        $config = $this->loadConfig();
        
        if ($config === null) {
            return false;
        }
        
        $config[$section] = array_merge($config[$section] ?? [], $data);
        
        return $this->saveConfig($config);
    }
}

// Global config instance
$config_manager = new ConfigManager();

// Helper functions
function getConfig($section = null, $key = null) {
    global $config_manager;
    return $config_manager->getConfig($section, $key);
}

function updateConfig($section, $key, $value) {
    global $config_manager;
    return $config_manager->updateConfig($section, $key, $value);
}

function updateConfigSection($section, $data) {
    global $config_manager;
    return $config_manager->updateSection($section, $data);
}
?>
