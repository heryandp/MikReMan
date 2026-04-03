<?php
header('Content-Type: application/json');

require_once '../includes/session.php';
startSecureSession();

require_once '../includes/config.php';
require_once '../includes/mikrotik.php';
require_once '../includes/qemu_hostfwd.php';
require_once '../includes/ppp_nat.php';
require_once '../includes/ppp_actions.php';
require_once '../includes/wireguard_actions.php';
require_once '../includes/wg_easy.php';
require_once '../includes/trial_orders.php';
require_once '../includes/turnstile.php';
require_once '../includes/locks.php';

const ORDER_TRIAL_DURATION_DAYS = 7;
const ORDER_RATE_LIMIT_MAX = 3;
const ORDER_RATE_LIMIT_WINDOW = 3600;
const ORDER_EMAIL_COOLDOWN_SECONDS = 1800;
const ORDER_IP_COOLDOWN_SECONDS = 3600;
const ORDER_MAX_NOTES_LENGTH = 500;
const ORDER_MAX_FULL_NAME_LENGTH = 120;
const ORDER_ALLOWED_SERVICES = ['l2tp', 'pptp', 'sstp', 'wireguard'];
const ORDER_FIXED_PORTS = [
    ['port' => '8291', 'label' => 'Winbox'],
    ['port' => '8728', 'label' => 'API'],
    ['port' => '80', 'label' => 'HTTP'],
];
const ORDER_BLOCKED_EMAIL_DOMAINS = [
    'mailinator.com',
    'guerrillamail.com',
    '10minutemail.com',
    'tempmail.com',
    'sharklasers.com',
    'yopmail.com'
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method !== 'POST' || $action !== 'create_trial') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

if (!validateOrderCsrfToken()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid CSRF token'
    ]);
    exit;
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (isOrderRateLimited($client_ip)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Too many trial requests. Please try again later.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON payload'
    ]);
    exit;
}

if (isTurnstileEnabledFor('order')) {
    $turnstileVerification = validateTurnstileToken(
        $input['turnstile_token'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
        'order'
    );

    if (!$turnstileVerification['success']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => $turnstileVerification['message']
        ]);
        exit;
    }
}

try {
    $response = withAppLock('router-mutation', function () use ($input) {
        return createPublicTrialOrder($input);
    }, 20);
    recordOrderAttempt($client_ip);
    recordTrialRequestEvent([
        'status' => 'success',
        'request_code' => $response['trial']['request_code'] ?? '',
        'email' => strtolower(trim((string) ($input['email'] ?? ''))),
        'client_ip' => $client_ip,
        'service' => strtoupper(trim((string) ($input['service'] ?? ''))),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
    ]);
    echo json_encode($response);
} catch (Exception $e) {
    recordTrialRequestEvent([
        'status' => 'rejected',
        'email' => strtolower(trim((string) ($input['email'] ?? ''))),
        'client_ip' => $client_ip,
        'service' => strtoupper(trim((string) ($input['service'] ?? ''))),
        'message' => $e->getMessage(),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
    ]);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function validateOrderCsrfToken() {
    $csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrf_token = $_SESSION['order_csrf_token'] ?? '';

    return !empty($csrf_token) && hash_equals($csrf_token, $csrf_header);
}

function getOrderRateLimitKey($ip) {
    return 'order_attempts_' . md5((string)$ip);
}

function isOrderRateLimited($ip) {
    $key = getOrderRateLimitKey($ip);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'last_attempt' => 0];

    if (time() - ($attempts['last_attempt'] ?? 0) > ORDER_RATE_LIMIT_WINDOW) {
        unset($_SESSION[$key]);
        return false;
    }

    return ($attempts['count'] ?? 0) >= ORDER_RATE_LIMIT_MAX;
}

function recordOrderAttempt($ip) {
    $key = getOrderRateLimitKey($ip);
    $attempts = $_SESSION[$key] ?? ['count' => 0, 'last_attempt' => 0];
    $attempts['count'] = ($attempts['count'] ?? 0) + 1;
    $attempts['last_attempt'] = time();
    $_SESSION[$key] = $attempts;
}

function createPublicTrialOrder(array $input) {
    $full_name = trim((string)($input['full_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $service = strtolower(trim((string)($input['service'] ?? '')));
    $notes = trim((string)($input['notes'] ?? ''));
    $terms_accepted = !empty($input['terms_accepted']);
    $client_ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($full_name === '') {
        throw new Exception('Full name is required.');
    }

    if (mb_strlen($full_name) > ORDER_MAX_FULL_NAME_LENGTH) {
        throw new Exception('Full name is too long.');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('A valid email address is required.');
    }

    $email_domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    if ($email_domain !== '' && in_array($email_domain, ORDER_BLOCKED_EMAIL_DOMAINS, true)) {
        throw new Exception('Please use a non-temporary email address.');
    }

    if (!in_array($service, ORDER_ALLOWED_SERVICES, true)) {
        throw new Exception('Invalid VPN service.');
    }

    if (mb_strlen($notes) > ORDER_MAX_NOTES_LENGTH) {
        throw new Exception('Notes are too long.');
    }

    if (!$terms_accepted) {
        throw new Exception('You must accept the 7-day trial terms.');
    }

    $constraint = getExistingTrialConstraint(
        $email,
        $client_ip,
        ORDER_EMAIL_COOLDOWN_SECONDS,
        ORDER_IP_COOLDOWN_SECONDS
    );

    if ($constraint) {
        throw new Exception($constraint['message']);
    }

    $mikrotik_config = getConfig('mikrotik') ?? [];
    $public_access_host = getPublicTrialAccessHost($mikrotik_config);
    $service_access_host = getPublicTrialServiceHost($service, $mikrotik_config, $public_access_host);

    $request_code = buildPublicTrialRequestCode();
    $expires_at = new DateTimeImmutable('+' . ORDER_TRIAL_DURATION_DAYS . ' days', getTrialDisplayTimezone());

    if ($service === 'wireguard') {
        if (usesWgEasyBackend($mikrotik_config)) {
            $username = generatePublicTrialWireGuardNameForWgEasy();
            $service_access_host = getWgEasyTrialEndpointHost($mikrotik_config, $public_access_host);
            $service_access_port = getWgEasyTrialEndpointPort($mikrotik_config);

            return createPublicWgEasyTrialOrder($mikrotik_config, [
                'request_code' => $request_code,
                'username' => $username,
                'email' => $email,
                'full_name' => $full_name,
                'client_ip' => $client_ip,
                'service' => $service,
                'service_host' => $service_access_host,
                'service_port' => $service_access_port,
                'host' => $public_access_host,
                'expires_at' => $expires_at,
                'notes' => $notes,
            ]);
        }

        $mikrotik = new MikroTikAPI();
        ensureOrderServiceIsAvailable($mikrotik, $service);
        $username = generatePublicTrialWireGuardName($mikrotik);

        return createPublicWireGuardTrialOrder($mikrotik, [
            'request_code' => $request_code,
            'username' => $username,
            'email' => $email,
            'full_name' => $full_name,
            'client_ip' => $client_ip,
            'service' => $service,
            'service_host' => $service_access_host,
            'host' => $public_access_host,
            'expires_at' => $expires_at,
            'notes' => $notes,
        ]);
    }

    $mikrotik = new MikroTikAPI();
    $qemu_hostfwd = getQemuHostFwdManager($mikrotik_config);

    ensureOrderServiceIsAvailable($mikrotik, $service);

    $username = generatePublicTrialUsername($mikrotik);
    $password = generatePublicTrialPassword();

    $service_profile_mapping = [
        'l2tp' => 'L2TP',
        'pptp' => 'PPTP',
        'sstp' => 'SSTP',
    ];

    $user_data = [
        'name' => $username,
        'password' => $password,
        'service' => $service,
        'profile' => $service_profile_mapping[$service] ?? 'default',
        'comment' => buildPublicTrialComment($request_code, $full_name, $email, $expires_at)
    ];

    $remote_address = $mikrotik->getNextAvailableIP($service);
    if (!empty($remote_address)) {
        $user_data['remote-address'] = $remote_address;
    }

    $created_secret = $mikrotik->addPPPSecret($user_data);
    if (!$created_secret) {
        throw new Exception('Failed to create the trial PPP account.');
    }

    $created_user_id = $created_secret['.id'] ?? findPPPSecretIdByName($mikrotik, $username);
    $nat_results = [];
    $netwatch_created = false;

    try {
        if (empty($remote_address)) {
            throw new Exception('No remote address was assigned for the trial user.');
        }

        foreach (ORDER_FIXED_PORTS as $entry) {
            $internal_port = $entry['port'];
            $label = $entry['label'];
            $external_port = (string)$mikrotik->generateRandomPort();

            $nat_data = [
                'chain' => 'dstnat',
                'action' => 'dst-nat',
                'protocol' => 'tcp',
                'dst-port' => $external_port,
                'to-addresses' => $remote_address,
                'to-ports' => $internal_port,
                'comment' => buildPPPUserNatComment($username, $internal_port, true, $label)
            ];

            $nat_result = createPPPUserNatRule($mikrotik, $nat_data, $external_port, $internal_port, 'trial', $qemu_hostfwd);
            if (empty($nat_result['success'])) {
                throw new Exception('Failed to create required NAT mapping for ' . $label . ': ' . ($nat_result['error'] ?? 'Unknown error'));
            }

            $nat_results[] = $nat_result;
        }

        $netwatch_result = $mikrotik->createNetwatch($remote_address, $username);
        $netwatch_created = $netwatch_result !== false;

        persistTrialOrderRecord([
            'request_code' => $request_code,
            'username' => $username,
            'email' => $email,
            'full_name' => $full_name,
            'client_ip' => $client_ip,
            'service' => strtoupper($service),
            'service_host' => $service_access_host,
            'host' => $public_access_host,
            'remote_address' => $remote_address,
            'expires_at' => $expires_at->format(DATE_ATOM),
            'notes' => $notes,
            'user_id' => $created_user_id,
            'fixed_ports' => array_map(function ($nat_result) use ($public_access_host) {
                $label_map = [
                    '8291' => 'Winbox',
                    '8728' => 'API',
                    '80' => 'HTTP',
                ];

                $internal_port = (string)$nat_result['internal_port'];
                return [
                    'label' => $label_map[$internal_port] ?? ('Port ' . $internal_port),
                    'internal_port' => $internal_port,
                    'external_port' => (string)$nat_result['external_port'],
                    'endpoint' => $public_access_host . ':' . (string)$nat_result['external_port'],
                    'url' => $internal_port === '80'
                        ? 'http://' . $public_access_host . ':' . (string)$nat_result['external_port']
                        : '',
                ];
            }, $nat_results),
            'created_at' => (new DateTimeImmutable('now', getTrialDisplayTimezone()))->format(DATE_ATOM),
            'cleanup_mode' => 'cron'
        ]);
    } catch (Exception $e) {
        cleanupPublicTrialResources(
            $mikrotik,
            $created_user_id,
            $username,
            $remote_address,
            $qemu_hostfwd
        );

        throw $e;
    }

    return [
        'success' => true,
        'message' => 'Free PPP trial created successfully.',
        'trial' => [
            'request_code' => $request_code,
            'username' => $username,
            'password' => $password,
            'service' => strtoupper($service),
            'service_host' => $service_access_host,
            'remote_address' => $remote_address,
            'expires_at' => $expires_at->format(DATE_ATOM),
            'expires_label' => formatTrialDisplayDate($expires_at, 'Y-m-d H:i:s') . ' WIB',
            'host' => $public_access_host,
            'fixed_ports' => array_map(function ($nat_result) use ($public_access_host) {
                $label_map = [
                    '8291' => 'Winbox',
                    '8728' => 'API',
                    '80' => 'HTTP',
                ];

                $internal_port = (string)$nat_result['internal_port'];
                return [
                    'label' => $label_map[$internal_port] ?? ('Port ' . $internal_port),
                    'internal_port' => $internal_port,
                    'external_port' => (string)$nat_result['external_port'],
                    'endpoint' => $public_access_host . ':' . (string)$nat_result['external_port'],
                    'url' => $internal_port === '80'
                        ? 'http://' . $public_access_host . ':' . (string)$nat_result['external_port']
                        : '',
                ];
            }, $nat_results),
            'notes' => $notes,
            'netwatch_enabled' => $netwatch_created,
        ]
    ];
}

function ensureOrderServiceIsAvailable(MikroTikAPI $mikrotik, $service) {
    $services = $mikrotik->getVPNServicesStatus();
    $status = $services[$service] ?? false;

    if ($status !== true && $status !== 'enabled') {
        throw new Exception(strtoupper($service) . ' is not currently available.');
    }
}

function buildPublicTrialRequestCode() {
    return 'REQ-' . strtoupper(bin2hex(random_bytes(4)));
}

function buildPublicTrialComment($request_code, $full_name, $email, DateTimeImmutable $expires_at) {
    $name_slug = preg_replace('/\s+/', ' ', trim($full_name));
    $name_slug = substr($name_slug, 0, 40);
    $email_slug = substr($email, 0, 64);

    return sprintf(
        'trial|%s|%s|%s|exp=%s',
        $request_code,
        $name_slug,
        $email_slug,
        $expires_at->format('Y-m-d H:i:s')
    );
}

function getPublicTrialAccessHost(array $mikrotik_config) {
    $candidates = [
        sanitizePublicTrialHost($_SERVER['HTTP_HOST'] ?? ''),
        sanitizePublicTrialHost($_SERVER['SERVER_NAME'] ?? ''),
        sanitizePublicTrialHost(trim((string)($mikrotik_config['host'] ?? '')))
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return 'localhost';
}

function getPublicTrialServiceHost($service, array $mikrotik_config, $fallback_host) {
    $service = strtolower((string)$service);
    $key_map = [
        'l2tp' => 'l2tp_host',
        'pptp' => 'pptp_host',
        'sstp' => 'sstp_host',
        'wireguard' => 'wireguard_host',
    ];

    $config_key = $key_map[$service] ?? null;
    if ($config_key === null) {
        return $fallback_host;
    }

    $candidate = sanitizePublicTrialHost(trim((string)($mikrotik_config[$config_key] ?? '')));
    return $candidate !== '' ? $candidate : $fallback_host;
}

function getWgEasyTrialEndpointHost(array $mikrotik_config, $fallback_host) {
    $candidate = sanitizePublicTrialHost(trim((string)($mikrotik_config['wg_easy_endpoint_host'] ?? '')));
    if ($candidate !== '') {
        return $candidate;
    }

    return getPublicTrialServiceHost('wireguard', $mikrotik_config, $fallback_host);
}

function getWgEasyTrialEndpointPort(array $mikrotik_config) {
    $port = (int)($mikrotik_config['wg_easy_endpoint_port'] ?? 51820);
    return ($port > 0 && $port <= 65535) ? $port : 51820;
}

function sanitizePublicTrialHost($host) {
    $host = trim((string)$host);
    if ($host === '') {
        return '';
    }

    if (preg_match('/^\[(.*)\](?::\d+)?$/', $host, $matches)) {
        $host = $matches[1];
    } elseif (substr_count($host, ':') === 1) {
        [$host] = explode(':', $host, 2);
    }

    $sanitized = preg_replace('/[^A-Za-z0-9.-]/', '', $host);
    return $sanitized ?: '';
}

function generatePublicTrialUsername(MikroTikAPI $mikrotik) {
    $existing_names = [];
    $secrets = $mikrotik->getPPPSecrets();

    if (is_array($secrets)) {
        foreach ($secrets as $secret) {
            if (!empty($secret['name'])) {
                $existing_names[strtolower((string)$secret['name'])] = true;
            }
        }
    }

    for ($i = 0; $i < 100; $i++) {
        $candidate = 'trial' . strtolower(bin2hex(random_bytes(3)));
        if (!isset($existing_names[$candidate])) {
            return $candidate;
        }
    }

    throw new Exception('Failed to generate a unique trial username.');
}

function generatePublicTrialPassword() {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';

    for ($i = 0; $i < 12; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

function generatePublicTrialWireGuardName(MikroTikAPI $mikrotik) {
    $existing_names = [];
    $peers = $mikrotik->getWireGuardPeers();

    if (is_array($peers)) {
        foreach ($peers as $peer) {
            $name = strtolower(trim((string)($peer['name'] ?? '')));
            if ($name !== '') {
                $existing_names[$name] = true;
            }
        }
    }

    for ($i = 0; $i < 100; $i++) {
        $candidate = 'wgtrial' . strtolower(bin2hex(random_bytes(3)));
        if (!isset($existing_names[$candidate])) {
            return $candidate;
        }
    }

    throw new Exception('Failed to generate a unique WireGuard trial name.');
}

function generatePublicTrialWireGuardNameForWgEasy() {
    return 'wgtrial' . strtolower(bin2hex(random_bytes(3)));
}

function findPPPSecretIdByName(MikroTikAPI $mikrotik, $username) {
    $secrets = $mikrotik->getPPPSecrets();

    if (!is_array($secrets)) {
        return null;
    }

    foreach ($secrets as $secret) {
        if (($secret['name'] ?? '') === $username) {
            return $secret['.id'] ?? null;
        }
    }

    return null;
}

function createPublicWireGuardTrialOrder(MikroTikAPI $mikrotik, array $context) {
    $mikrotik_config = getConfig('mikrotik') ?? [];
    $request_code = (string)($context['request_code'] ?? '');
    $username = (string)($context['username'] ?? '');
    $email = (string)($context['email'] ?? '');
    $full_name = (string)($context['full_name'] ?? '');
    $client_ip = (string)($context['client_ip'] ?? '');
    $service_access_host = (string)($context['service_host'] ?? '');
    $public_access_host = (string)($context['host'] ?? '');
    $notes = (string)($context['notes'] ?? '');
    $expires_at = $context['expires_at'] ?? null;

    if (!$expires_at instanceof DateTimeImmutable) {
        throw new Exception('Missing WireGuard trial expiry time');
    }

    $peer_id = null;
    $peer_label = $username;

    try {
        $peer = $mikrotik->createWireGuardPeer([
            'name' => $peer_label,
            'comment' => buildPublicTrialComment($request_code, $full_name, $email, $expires_at),
            'interface' => $mikrotik_config['wireguard_interface'] ?? 'wireguard1',
            'client_dns' => $mikrotik_config['wireguard_client_dns'] ?? '8.8.8.8, 8.8.4.4',
            'client_allowed_address' => $mikrotik_config['wireguard_allowed_ips'] ?? '0.0.0.0/0, ::/0',
            'persistent_keepalive' => $mikrotik_config['wireguard_keepalive'] ?? '25',
            'client_keepalive' => $mikrotik_config['wireguard_keepalive'] ?? '25',
            'client_endpoint_host' => $service_access_host,
            'client_endpoint_port' => $mikrotik_config['wireguard_port'] ?? '13231',
            'server_address' => $mikrotik_config['wireguard_server_address'] ?? '10.66.66.1/24',
            'mtu' => $mikrotik_config['wireguard_mtu'] ?? '1420',
            'disabled' => false,
            'responder' => true,
        ]);

        $peer_id = $peer['.id'] ?? null;
        $normalized_peer = normalizeWireGuardPeerForUi($mikrotik, $peer, null, true);
        $normalized_peer['client_endpoint'] = $service_access_host !== ''
            ? $service_access_host . ':' . ($mikrotik_config['wireguard_port'] ?? '13231')
            : ($normalized_peer['client_endpoint'] ?? '');
        $normalized_peer['download_name'] = buildWireGuardSuggestedName(
            $peer_label,
            trim((string)($mikrotik_config['wireguard_client_name_suffix'] ?? ''))
        );
        $normalized_peer['client_config'] = $mikrotik->buildWireGuardClientConfig($peer, [
            'endpoint_host' => $service_access_host,
            'endpoint_port' => $mikrotik_config['wireguard_port'] ?? '13231',
            'allowed_ips' => $mikrotik_config['wireguard_allowed_ips'] ?? '0.0.0.0/0, ::/0',
            'client_dns' => $mikrotik_config['wireguard_client_dns'] ?? '8.8.8.8, 8.8.4.4',
            'suggested_name' => $normalized_peer['download_name'],
        ]);

        persistTrialOrderRecord([
            'request_code' => $request_code,
            'username' => $peer_label,
            'email' => $email,
            'full_name' => $full_name,
            'client_ip' => $client_ip,
            'service' => 'WIREGUARD',
            'service_host' => $service_access_host,
            'host' => $public_access_host,
            'remote_address' => $normalized_peer['client_address'] ?? '',
            'expires_at' => $expires_at->format(DATE_ATOM),
            'notes' => $notes,
            'peer_id' => $peer_id,
            'wireguard' => [
                'interface' => $normalized_peer['interface'] ?? ($mikrotik_config['wireguard_interface'] ?? 'wireguard1'),
                'client_address' => $normalized_peer['client_address'] ?? '',
                'endpoint' => $normalized_peer['client_endpoint'] ?? '',
                'listen_port' => $normalized_peer['listen_port'] ?? ($mikrotik_config['wireguard_port'] ?? '13231'),
                'server_public_key' => $normalized_peer['server_public_key'] ?? '',
                'public_key' => $normalized_peer['public_key'] ?? '',
                'client_config' => $normalized_peer['client_config'] ?? '',
            ],
            'fixed_ports' => [],
            'created_at' => (new DateTimeImmutable('now', getTrialDisplayTimezone()))->format(DATE_ATOM),
            'cleanup_mode' => 'cron'
        ]);

        return [
            'success' => true,
            'message' => 'Free WireGuard trial created successfully.',
            'trial' => [
                'request_code' => $request_code,
                'username' => $peer_label,
                'service' => 'WIREGUARD',
                'service_host' => $service_access_host,
                'remote_address' => $normalized_peer['client_address'] ?? '',
                'expires_at' => $expires_at->format(DATE_ATOM),
                'expires_label' => formatTrialDisplayDate($expires_at, 'Y-m-d H:i:s') . ' WIB',
                'host' => $public_access_host,
                'endpoint' => $normalized_peer['client_endpoint'] ?? ($service_access_host . ':' . ($mikrotik_config['wireguard_port'] ?? '13231')),
                'interface' => $normalized_peer['interface'] ?? ($mikrotik_config['wireguard_interface'] ?? 'wireguard1'),
                'server_public_key' => $normalized_peer['server_public_key'] ?? '',
                'public_key' => $normalized_peer['public_key'] ?? '',
                'client_config' => $normalized_peer['client_config'] ?? '',
                'download_name' => $normalized_peer['download_name'] ?? $peer_label,
                'fixed_ports' => [],
                'notes' => $notes,
            ]
        ];
    } catch (Exception $e) {
        cleanupPublicTrialWireGuardResources($mikrotik, $peer_id, $peer_label);
        throw $e;
    }
}

function createPublicWgEasyTrialOrder(array $mikrotik_config, array $context) {
    $request_code = (string)($context['request_code'] ?? '');
    $username = (string)($context['username'] ?? '');
    $email = (string)($context['email'] ?? '');
    $full_name = (string)($context['full_name'] ?? '');
    $client_ip = (string)($context['client_ip'] ?? '');
    $service_access_host = (string)($context['service_host'] ?? '');
    $service_access_port = (int)($context['service_port'] ?? getWgEasyTrialEndpointPort($mikrotik_config));
    $public_access_host = (string)($context['host'] ?? '');
    $notes = (string)($context['notes'] ?? '');
    $expires_at = $context['expires_at'] ?? null;

    if (!$expires_at instanceof DateTimeImmutable) {
        throw new Exception('Missing WireGuard trial expiry time');
    }

    $wgEasy = getWgEasyClient($mikrotik_config);
    $wgEasy->login();

    $clientId = null;

    try {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $clientId = $wgEasy->createClient($username, $expires_at->format(DATE_ATOM));
                break;
            } catch (Exception $e) {
                if (stripos($e->getMessage(), 'already') !== false || stripos($e->getMessage(), 'duplicate') !== false) {
                    $username = generatePublicTrialWireGuardNameForWgEasy();
                    continue;
                }

                throw $e;
            }
        }

        if ($clientId === null) {
            throw new Exception('Failed to create a unique wg-easy trial client.');
        }

        $client = $wgEasy->getClient($clientId);
        $clientConfig = $wgEasy->getClientConfiguration($clientId);
        $downloadName = buildWireGuardSuggestedName(
            $username,
            trim((string)($mikrotik_config['wireguard_client_name_suffix'] ?? ''))
        );

        $endpoint = trim((string)($client['endpoint'] ?? ''));
        if ($endpoint === '' && $service_access_host !== '') {
            $endpoint = $service_access_host . ':' . $service_access_port;
        }

        $clientAddress = trim((string)($client['ipv4Address'] ?? $client['address'] ?? ''));
        $serverPublicKey = '';
        if (preg_match('/^\s*PublicKey\s*=\s*(.+)$/mi', $clientConfig, $matches)) {
            $serverPublicKey = trim((string)$matches[1]);
        }

        persistTrialOrderRecord([
            'request_code' => $request_code,
            'username' => $username,
            'email' => $email,
            'full_name' => $full_name,
            'client_ip' => $client_ip,
            'service' => 'WIREGUARD',
            'wireguard_backend' => 'wg-easy',
            'service_host' => $service_access_host,
            'host' => $public_access_host,
            'remote_address' => $clientAddress,
            'expires_at' => $expires_at->format(DATE_ATOM),
            'notes' => $notes,
            'wg_easy_client_id' => $clientId,
            'wireguard' => [
                'interface' => 'wg-easy',
                'client_address' => $clientAddress,
                'endpoint' => $endpoint,
                'listen_port' => (string)$service_access_port,
                'server_public_key' => $serverPublicKey,
                'public_key' => trim((string)($client['publicKey'] ?? '')),
                'client_config' => $clientConfig,
            ],
            'fixed_ports' => [],
            'created_at' => (new DateTimeImmutable('now', getTrialDisplayTimezone()))->format(DATE_ATOM),
            'cleanup_mode' => 'cron'
        ]);

        return [
            'success' => true,
            'message' => 'Free WireGuard trial created successfully.',
            'trial' => [
                'request_code' => $request_code,
                'username' => $username,
                'service' => 'WIREGUARD',
                'service_host' => $service_access_host,
                'remote_address' => $clientAddress,
                'expires_at' => $expires_at->format(DATE_ATOM),
                'expires_label' => formatTrialDisplayDate($expires_at, 'Y-m-d H:i:s') . ' WIB',
                'host' => $public_access_host,
                'endpoint' => $endpoint,
                'interface' => 'wg-easy',
                'server_public_key' => $serverPublicKey,
                'public_key' => trim((string)($client['publicKey'] ?? '')),
                'client_config' => $clientConfig,
                'download_name' => $downloadName,
                'fixed_ports' => [],
                'notes' => $notes,
            ]
        ];
    } catch (Exception $e) {
        if ($clientId !== null) {
            try {
                $wgEasy->deleteClient((int)$clientId);
            } catch (Exception $cleanupError) {
                error_log('[ORDER CLEANUP] wg-easy client cleanup failed: ' . $cleanupError->getMessage());
            }
        }

        throw $e;
    }
}

function cleanupPublicTrialWireGuardResources(MikroTikAPI $mikrotik, $peer_id, $peer_label) {
    try {
        if (!empty($peer_id) && $mikrotik->getWireGuardPeer((string)$peer_id)) {
            $mikrotik->deleteWireGuardPeer((string)$peer_id);
            return;
        }

        foreach ($mikrotik->getWireGuardPeers() as $peer) {
            if (($peer['name'] ?? '') === $peer_label || ($peer['comment'] ?? '') === $peer_label) {
                if (!empty($peer['.id'])) {
                    $mikrotik->deleteWireGuardPeer((string)$peer['.id']);
                }
                break;
            }
        }
    } catch (Exception $e) {
        error_log('[ORDER CLEANUP] WireGuard peer cleanup failed: ' . $e->getMessage());
    }
}

function cleanupPublicTrialResources(MikroTikAPI $mikrotik, $user_id, $username, $remote_address, $qemu_hostfwd) {
    try {
        $nat_rules = collectUserNatRules($mikrotik, $username, $remote_address);
        if (!empty($nat_rules)) {
            deletePPPUserNatRules($mikrotik, $nat_rules, $qemu_hostfwd);
        }
    } catch (Exception $e) {
        error_log('[ORDER CLEANUP] NAT cleanup failed: ' . $e->getMessage());
    }

    try {
        if (!empty($remote_address)) {
            $mikrotik->deleteNetwatchByComment($username);
        }
    } catch (Exception $e) {
        error_log('[ORDER CLEANUP] Netwatch cleanup failed: ' . $e->getMessage());
    }
    try {
        if (!empty($user_id)) {
            $mikrotik->deletePPPSecret($user_id);
        }
    } catch (Exception $e) {
        error_log('[ORDER CLEANUP] PPP secret cleanup failed: ' . $e->getMessage());
    }
}
