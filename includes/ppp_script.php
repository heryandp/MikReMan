<?php

function renderPPPScript(array $mikrotik_config, int $session_timeout): void
{
    ?>
    <!-- PPP Users JavaScript -->
    <script>
        window.PPP_APP_CONFIG = {
            serverHost: <?php echo sanitizeOutput($mikrotik_config['host'] ?? '', 'js'); ?>,
            sessionTimeoutMs: <?php echo $session_timeout * 1000; ?>,
            externalPorts: <?php echo json_encode([
                'rest_http_port' => $mikrotik_config['rest_http_port'] ?? '7004',
                'rest_https_port' => $mikrotik_config['rest_https_port'] ?? '7005',
                'winbox_port' => $mikrotik_config['winbox_port'] ?? '7000',
                'api_port' => $mikrotik_config['api_port'] ?? '7001',
                'api_ssl_port' => $mikrotik_config['api_ssl_port'] ?? '7002',
                'ssh_port' => $mikrotik_config['ssh_port'] ?? '7003',
                'l2tp_port' => $mikrotik_config['l2tp_port'] ?? '1701',
                'l2tp_host' => $mikrotik_config['l2tp_host'] ?? '',
                'pptp_port' => $mikrotik_config['pptp_port'] ?? '1723',
                'pptp_host' => $mikrotik_config['pptp_host'] ?? '',
                'sstp_port' => $mikrotik_config['sstp_port'] ?? '443',
                'sstp_host' => $mikrotik_config['sstp_host'] ?? '',
                'ipsec_port' => $mikrotik_config['ipsec_port'] ?? '500',
                'ipsec_nat_t_port' => $mikrotik_config['ipsec_nat_t_port'] ?? '4500'
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        };
    </script>
    <script src="../assets/js/ppp.js"></script>
    <?php
}
