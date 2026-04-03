<?php

function renderWireGuardPageScript(array $mikrotik_config, int $session_timeout): void
{
    ?>
    <script>
        window.WIREGUARD_APP_CONFIG = {
            sessionTimeoutMs: <?php echo $session_timeout * 1000; ?>,
            defaults: <?php echo json_encode([
                'interface' => $mikrotik_config['wireguard_interface'] ?? 'wireguard1',
                'port' => $mikrotik_config['wireguard_port'] ?? '13231',
                'host' => $mikrotik_config['wireguard_host'] ?? ($mikrotik_config['host'] ?? ''),
                'mtu' => $mikrotik_config['wireguard_mtu'] ?? '1420',
                'server_address' => $mikrotik_config['wireguard_server_address'] ?? '10.66.66.1/24',
                'client_dns' => $mikrotik_config['wireguard_client_dns'] ?? '8.8.8.8, 8.8.4.4',
                'allowed_ips' => $mikrotik_config['wireguard_allowed_ips'] ?? '0.0.0.0/0, ::/0',
                'keepalive' => $mikrotik_config['wireguard_keepalive'] ?? '25',
                'client_name_suffix' => $mikrotik_config['wireguard_client_name_suffix'] ?? '',
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        };
    </script>
    <script src="../assets/js/wireguard.js"></script>
    <?php
}
