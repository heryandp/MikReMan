<?php
header('Content-Type: application/json');
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/auth.php';
require_once '../includes/config.php';

requireAuth();

if (!validateCsrfTokenRequest()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid CSRF token'
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($action);
            break;

        case 'POST':
            handlePostRequest();
            break;

        default:
            throw new Exception('Method not allowed');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function validateCsrfTokenRequest() {
    $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrf_token = $_SESSION['csrf_token'] ?? '';

    return !empty($csrf_token) && hash_equals($csrf_token, $csrf_header);
}

function maskedSecretValue($value) {
    return !empty($value) ? str_repeat("\u{2022}", 8) : '';
}

function handleGetRequest($action) {
    switch ($action) {
        case 'get_all':
            getAllConfig();
            break;

        case 'get_section':
            $section = $_GET['section'] ?? '';
            if (empty($section)) {
                throw new Exception('Section parameter required');
            }
            getConfigSection($section);
            break;

        case 'get_password':
            $section = $_GET['section'] ?? '';
            $key = $_GET['key'] ?? '';
            if (empty($section) || empty($key)) {
                throw new Exception('Section and key parameters required');
            }
            getPassword($section, $key);
            break;

        case 'get_auth_credentials':
            getAuthCredentials();
            break;

        case 'get_mikrotik_credentials':
            getMikrotikCredentials();
            break;

        default:
            throw new Exception('Invalid action');
    }
}

function handlePostRequest() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input');
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'update_section':
            updateConfigSectionHandler($input);
            break;

        case 'update_key':
            updateConfigKeyHandler($input);
            break;

        default:
            throw new Exception('Invalid action');
    }
}

function getAllConfig() {
    try {
        $config = getConfig();

        if ($config === null) {
            global $config_manager;
            $config = $config_manager->loadConfig();
        }

        $safe_config = $config;
        if (isset($safe_config['auth']['password'])) {
            $safe_config['auth']['password'] = maskedSecretValue($config['auth']['password'] ?? '');
        }
        if (isset($safe_config['mikrotik']['password'])) {
            $safe_config['mikrotik']['password'] = maskedSecretValue($config['mikrotik']['password'] ?? '');
        }
        if (isset($safe_config['mikrotik']['qemu_ssh_private_key'])) {
            $safe_config['mikrotik']['qemu_ssh_private_key'] = maskedSecretValue($config['mikrotik']['qemu_ssh_private_key'] ?? '');
        }
        if (isset($safe_config['mikrotik']['wg_easy_password'])) {
            $safe_config['mikrotik']['wg_easy_password'] = maskedSecretValue($config['mikrotik']['wg_easy_password'] ?? '');
        }
        if (isset($safe_config['telegram']['bot_token'])) {
            $safe_config['telegram']['bot_token'] = !empty($config['telegram']['bot_token'])
                ? substr($config['telegram']['bot_token'], 0, 10) . str_repeat("\u{2022}", 8)
                : '';
        }
        if (isset($safe_config['cloudflare']['turnstile_secret_key'])) {
            $safe_config['cloudflare']['turnstile_secret_key'] = maskedSecretValue($config['cloudflare']['turnstile_secret_key'] ?? '');
        }

        echo json_encode([
            'success' => true,
            'config' => $safe_config
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to load configuration: ' . $e->getMessage());
    }
}

function getConfigSection($section) {
    try {
        $config = getConfig($section);

        if ($config === null) {
            throw new Exception('Configuration section not found');
        }

        $config = sanitizeConfigSection($section, $config);

        echo json_encode([
            'success' => true,
            'data' => $config
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to load configuration section: ' . $e->getMessage());
    }
}

function sanitizeConfigSection($section, $config) {
    if (!is_array($config)) {
        return $config;
    }

    switch ($section) {
        case 'auth':
            if (isset($config['password'])) {
                $config['password'] = maskedSecretValue($config['password']);
            }
            unset($config['password_hash']);
            break;

        case 'mikrotik':
            if (isset($config['password'])) {
                $config['password'] = maskedSecretValue($config['password']);
            }
            if (isset($config['qemu_ssh_private_key'])) {
                $config['qemu_ssh_private_key'] = maskedSecretValue($config['qemu_ssh_private_key']);
            }
            if (isset($config['wg_easy_password'])) {
                $config['wg_easy_password'] = maskedSecretValue($config['wg_easy_password']);
            }
            break;

        case 'telegram':
            if (isset($config['bot_token'])) {
                $config['bot_token'] = !empty($config['bot_token'])
                    ? substr($config['bot_token'], 0, 10) . str_repeat("\u{2022}", 8)
                    : '';
            }
            break;

        case 'cloudflare':
            if (isset($config['turnstile_secret_key'])) {
                $config['turnstile_secret_key'] = maskedSecretValue($config['turnstile_secret_key']);
            }
            break;
    }

    return $config;
}

function getPassword($section, $key) {
    try {
        $allowed_password_keys = [
            'auth' => ['password'],
            'telegram' => ['bot_token'],
            'mikrotik' => ['password', 'qemu_ssh_private_key', 'wg_easy_password']
        ];

        if (!isset($allowed_password_keys[$section]) || !in_array($key, $allowed_password_keys[$section], true)) {
            throw new Exception('Password retrieval not allowed for this key');
        }

        $config = getConfig($section);

        if ($config === null) {
            throw new Exception('Configuration section not found');
        }

        if (!isset($config[$key])) {
            throw new Exception('Password key not found');
        }

        echo json_encode([
            'success' => true,
            'password' => $config[$key]
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to get password: ' . $e->getMessage());
    }
}

function updateConfigSectionHandler($input) {
    try {
        $section = $input['section'] ?? '';
        $data = $input['data'] ?? [];

        if (empty($section)) {
            throw new Exception('Section parameter required');
        }

        if (empty($data)) {
            throw new Exception('Data parameter required');
        }

        if ($section === 'auth') {
            $existing_auth = getConfig('auth');

            if (!isset($data['username']) || empty($data['username'])) {
                if ($existing_auth && isset($existing_auth['username'])) {
                    $data['username'] = $existing_auth['username'];
                }
            }

            if (!isset($data['password']) || empty($data['password'])) {
                if ($existing_auth && isset($existing_auth['password'])) {
                    $data['password'] = $existing_auth['password'];
                    $data['password_hash'] = $existing_auth['password_hash'];
                }
            } else {
                $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
        }

        $result = updateConfigSection($section, $data);

        if (!$result) {
            throw new Exception('Failed to save configuration');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Configuration updated successfully'
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to update configuration: ' . $e->getMessage());
    }
}

function getAuthCredentials() {
    try {
        $auth_config = getConfig('auth');

        if (!$auth_config) {
            $credentials = [
                'username' => 'user1234',
                'password' => 'mostech'
            ];
        } else {
            $credentials = [
                'username' => $auth_config['username'] ?? 'user1234',
                'password' => $auth_config['password'] ?? 'mostech'
            ];
        }

        echo json_encode([
            'success' => true,
            'credentials' => $credentials
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to get auth credentials: ' . $e->getMessage());
    }
}

function getMikrotikCredentials() {
    try {
        $mikrotik_config = getConfig('mikrotik');

        if (!$mikrotik_config) {
            $credentials = [
                'host' => '',
                'username' => '',
                'password' => '',
                'port' => '443',
                'use_ssl' => true
            ];
        } else {
            $credentials = [
                'host' => $mikrotik_config['host'] ?? '',
                'username' => $mikrotik_config['username'] ?? '',
                'password' => $mikrotik_config['password'] ?? '',
                'port' => $mikrotik_config['port'] ?? '443',
                'use_ssl' => $mikrotik_config['use_ssl'] ?? true
            ];

            if ($credentials['password'] === maskedSecretValue('x')) {
                $credentials['password'] = '';
                $credentials['password_masked'] = true;
            } else {
                $credentials['password_masked'] = false;
            }
        }

        echo json_encode([
            'success' => true,
            'credentials' => $credentials
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to get MikroTik credentials: ' . $e->getMessage());
    }
}

function updateConfigKeyHandler($input) {
    try {
        $section = $input['section'] ?? '';
        $key = $input['key'] ?? '';
        $value = $input['value'] ?? '';

        if (empty($section) || empty($key)) {
            throw new Exception('Section and key parameters required');
        }

        $result = updateConfig($section, $key, $value);

        if (!$result) {
            throw new Exception('Failed to save configuration');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Configuration updated successfully'
        ]);
    } catch (Exception $e) {
        throw new Exception('Failed to update configuration: ' . $e->getMessage());
    }
}
