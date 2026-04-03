<?php

function getWireGuardRuntimeDefaults(): array
{
    $config = getConfig('mikrotik') ?? [];

    return [
        'interface' => trim((string)($config['wireguard_interface'] ?? 'wireguard1')) ?: 'wireguard1',
        'host' => trim((string)($config['wireguard_host'] ?? $config['host'] ?? '')),
        'port' => trim((string)($config['wireguard_port'] ?? '13231')) ?: '13231',
        'mtu' => trim((string)($config['wireguard_mtu'] ?? '1420')) ?: '1420',
        'server_address' => trim((string)($config['wireguard_server_address'] ?? '10.66.66.1/24')) ?: '10.66.66.1/24',
        'client_dns' => trim((string)($config['wireguard_client_dns'] ?? '8.8.8.8, 8.8.4.4')),
        'allowed_ips' => trim((string)($config['wireguard_allowed_ips'] ?? '0.0.0.0/0, ::/0')) ?: '0.0.0.0/0, ::/0',
        'keepalive' => trim((string)($config['wireguard_keepalive'] ?? '25')) ?: '25',
        'client_name_suffix' => trim((string)($config['wireguard_client_name_suffix'] ?? '')),
    ];
}

function buildWireGuardSuggestedName(string $peerName, string $suffix = ''): string
{
    $peerName = trim($peerName);
    $suffix = trim($suffix);

    if ($suffix === '') {
        return $peerName;
    }

    if ($peerName === '') {
        return $suffix;
    }

    return $peerName . '-' . $suffix;
}

function wireGuardPeerPrimaryName(array $peer): string
{
    $name = trim((string)($peer['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $comment = trim((string)($peer['comment'] ?? ''));
    if ($comment !== '') {
        return $comment;
    }

    $public_key = trim((string)($peer['public-key'] ?? ''));
    return $public_key !== '' ? substr($public_key, 0, 12) : 'wireguard-peer';
}

function wireGuardFormatBytes($bytes): string
{
    $value = (float)$bytes;
    if ($value <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
}

function wireGuardParseDurationSeconds($value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
        return (int)$value;
    }

    $value = strtolower(trim((string)$value));
    if ($value === '' || $value === 'never') {
        return null;
    }

    if (!preg_match_all('/(\d+)([wdhms])/', $value, $matches, PREG_SET_ORDER)) {
        return null;
    }

    $unit_map = [
        'w' => 604800,
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];

    $seconds = 0;
    foreach ($matches as $match) {
        $seconds += (int)$match[1] * ($unit_map[$match[2]] ?? 0);
    }

    return $seconds;
}

function wireGuardFormatLastHandshake($seconds): string
{
    $seconds = wireGuardParseDurationSeconds($seconds);
    if ($seconds === null) {
        return 'Never';
    }

    if ($seconds <= 0) {
        return 'Just now';
    }

    if ($seconds < 60) {
        return $seconds . 's ago';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm ago';
    }

    if ($seconds < 86400) {
        return floor($seconds / 3600) . 'h ago';
    }

    return floor($seconds / 86400) . 'd ago';
}

function wireGuardPeerIsOnline(array $peer): bool
{
    if (!empty($peer['disabled']) && ($peer['disabled'] === true || $peer['disabled'] === 'true')) {
        return false;
    }

    $seconds = wireGuardParseDurationSeconds($peer['last-handshake'] ?? null);
    if ($seconds === null) {
        return false;
    }

    return $seconds >= 0 && $seconds <= 180;
}

function normalizeWireGuardPeerForUi(MikroTikAPI $mikrotik, array $peer, ?array $interface = null, bool $with_config = false): array
{
    $interface = $interface ?? $mikrotik->getWireGuardInterface($peer['interface'] ?? null);
    $display_name = wireGuardPeerPrimaryName($peer);
    $client_config = null;
    $defaults = getWireGuardRuntimeDefaults();

    if ($with_config) {
        try {
            $suggested_name = buildWireGuardSuggestedName($display_name, $defaults['client_name_suffix']);
            $client_config = $mikrotik->buildWireGuardClientConfig($peer, [
                'interface_data' => $interface,
                'endpoint_host' => $defaults['host'],
                'endpoint_port' => $defaults['port'],
                'allowed_ips' => $defaults['allowed_ips'],
                'client_dns' => $defaults['client_dns'],
                'suggested_name' => $suggested_name,
            ]);
        } catch (Exception $e) {
            $client_config = null;
        }
    }

    $rx = (string)($peer['rx'] ?? '0');
    $tx = (string)($peer['tx'] ?? '0');
    $endpoint_host = trim((string)($peer['current-endpoint-address'] ?? ''));
    $endpoint_port = trim((string)($peer['current-endpoint-port'] ?? ''));

    return [
        '.id' => $peer['.id'] ?? '',
        'name' => trim((string)($peer['name'] ?? '')),
        'comment' => trim((string)($peer['comment'] ?? '')),
        'display_name' => $display_name,
        'download_name' => buildWireGuardSuggestedName($display_name, $defaults['client_name_suffix']),
        'interface' => trim((string)($peer['interface'] ?? '')),
        'allowed_address' => trim((string)($peer['allowed-address'] ?? '')),
        'client_address' => trim((string)($peer['allowed-address'] ?? '')),
        'client_dns' => $defaults['client_dns'],
        'client_allowed_address' => $defaults['allowed_ips'],
        'persistent_keepalive' => (string)($peer['persistent-keepalive'] ?? ''),
        'client_keepalive' => (string)($peer['persistent-keepalive'] ?? $defaults['keepalive']),
        'client_endpoint' => trim((string)($defaults['host'] !== '' ? $defaults['host'] . ':' . $defaults['port'] : '')),
        'client_listen_port' => '',
        'disabled' => ($peer['disabled'] ?? 'false') === 'true' || ($peer['disabled'] ?? false) === true,
        'online' => wireGuardPeerIsOnline($peer),
        'last_handshake_seconds' => wireGuardParseDurationSeconds($peer['last-handshake'] ?? null),
        'last_handshake_label' => wireGuardFormatLastHandshake($peer['last-handshake'] ?? null),
        'current_endpoint' => $endpoint_host !== '' ? $endpoint_host . ($endpoint_port !== '' ? ':' . $endpoint_port : '') : '-',
        'public_key' => trim((string)($peer['public-key'] ?? '')),
        'private_key' => trim((string)($peer['private-key'] ?? '')),
        'preshared_key' => trim((string)($peer['preshared-key'] ?? '')),
        'rx' => $rx,
        'tx' => $tx,
        'rx_label' => wireGuardFormatBytes($rx),
        'tx_label' => wireGuardFormatBytes($tx),
        'traffic_label' => wireGuardFormatBytes($rx) . ' / ' . wireGuardFormatBytes($tx),
        'server_public_key' => trim((string)($interface['public-key'] ?? '')),
        'listen_port' => trim((string)($interface['listen-port'] ?? '')),
        'server_address' => trim((string)($mikrotik->getWireGuardServerAddress($peer['interface'] ?? null)['address'] ?? '')),
        'client_config' => $client_config,
    ];
}

function findExistingWireGuardPeerByLabel(MikroTikAPI $mikrotik, string $label, ?string $ignore_id = null): ?array
{
    $target = strtolower(trim($label));
    if ($target === '') {
        return null;
    }

    foreach ($mikrotik->getWireGuardPeers() as $peer) {
        if ($ignore_id !== null && ($peer['.id'] ?? '') === $ignore_id) {
            continue;
        }

        $primary_name = strtolower(wireGuardPeerPrimaryName($peer));
        if ($primary_name === $target) {
            return $peer;
        }
    }

    return null;
}

function createManagedWireGuardPeer(MikroTikAPI $mikrotik, array $input): array
{
    $defaults = getWireGuardRuntimeDefaults();
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new Exception('Peer name is required');
    }

    if (findExistingWireGuardPeerByLabel($mikrotik, $name)) {
        throw new Exception('A WireGuard peer with that name already exists');
    }

    $payload = [
        'name' => $name,
        'comment' => trim((string)($input['comment'] ?? $name)),
        'interface' => trim((string)($input['interface'] ?? $defaults['interface'])) ?: $defaults['interface'],
        'allowed_address' => trim((string)($input['allowed_address'] ?? '')),
        'persistent_keepalive' => (int)($input['persistent_keepalive'] ?? $defaults['keepalive']),
        'disabled' => normalizeInputBoolean($input['disabled'] ?? false),
        'server_address' => trim((string)($input['server_address'] ?? $defaults['server_address'])),
        'mtu' => (int)($input['mtu'] ?? $defaults['mtu']),
        'responder' => true,
    ];

    $peer = $mikrotik->createWireGuardPeer($payload);

    return normalizeWireGuardPeerForUi($mikrotik, $peer, null, true);
}

function updateManagedWireGuardPeer(MikroTikAPI $mikrotik, array $input): array
{
    $peer_id = trim((string)($input['peer_id'] ?? ''));
    if ($peer_id === '') {
        throw new Exception('Peer ID is required');
    }

    $existing = $mikrotik->getWireGuardPeer($peer_id);
    if (!$existing) {
        throw new Exception('WireGuard peer not found');
    }

    $name = trim((string)($input['name'] ?? wireGuardPeerPrimaryName($existing)));
    if ($name === '') {
        throw new Exception('Peer name is required');
    }

    if (findExistingWireGuardPeerByLabel($mikrotik, $name, $peer_id)) {
        throw new Exception('A WireGuard peer with that name already exists');
    }

    $defaults = getWireGuardRuntimeDefaults();
    $payload = [
        'name' => $name,
        'comment' => trim((string)($input['comment'] ?? ($existing['comment'] ?? $name))),
        'interface' => trim((string)($input['interface'] ?? ($existing['interface'] ?? $defaults['interface']))) ?: $defaults['interface'],
        'allowed_address' => trim((string)($input['allowed_address'] ?? ($existing['allowed-address'] ?? ''))),
        'persistent_keepalive' => (int)($input['persistent_keepalive'] ?? ($existing['persistent-keepalive'] ?? $defaults['keepalive'])),
        'disabled' => normalizeInputBoolean($input['disabled'] ?? ($existing['disabled'] ?? false)),
        'regenerate_private_key' => normalizeInputBoolean($input['regenerate_private_key'] ?? false),
        'server_address' => trim((string)($input['server_address'] ?? $defaults['server_address'])),
    ];

    $peer = $mikrotik->updateWireGuardPeer($peer_id, $payload);

    return normalizeWireGuardPeerForUi($mikrotik, $peer, null, true);
}

function getWireGuardPeers()
{
    try {
        $mikrotik = new MikroTikAPI();
        $interfaces = [];
        foreach ($mikrotik->getWireGuardInterfaces() as $interface) {
            $interfaces[$interface['name'] ?? ''] = $interface;
        }

        $peers = array_map(static function (array $peer) use ($mikrotik, $interfaces): array {
            $interface_name = $peer['interface'] ?? '';
            return normalizeWireGuardPeerForUi($mikrotik, $peer, $interfaces[$interface_name] ?? null, false);
        }, $mikrotik->getWireGuardPeers());

        usort($peers, static function (array $left, array $right): int {
            return strcasecmp($left['display_name'], $right['display_name']);
        });

        $total = count($peers);
        $online = count(array_filter($peers, static function (array $peer): bool {
            return !empty($peer['online']);
        }));
        $disabled = count(array_filter($peers, static function (array $peer): bool {
            return !empty($peer['disabled']);
        }));

        echo json_encode([
            'success' => true,
            'data' => $peers,
            'stats' => [
                'total' => $total,
                'online' => $online,
                'offline' => max($total - $online, 0),
                'disabled' => $disabled,
            ],
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function addWireGuardPeer($input)
{
    try {
        $mikrotik = new MikroTikAPI();
        $peer = createManagedWireGuardPeer($mikrotik, $input);

        echo json_encode([
            'success' => true,
            'message' => 'WireGuard peer created successfully',
            'data' => $peer,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function editWireGuardPeer($input)
{
    try {
        $mikrotik = new MikroTikAPI();
        $peer = updateManagedWireGuardPeer($mikrotik, $input);

        echo json_encode([
            'success' => true,
            'message' => 'WireGuard peer updated successfully',
            'data' => $peer,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function deleteWireGuardPeer($input)
{
    try {
        $peer_id = trim((string)($input['peer_id'] ?? ''));
        if ($peer_id === '') {
            throw new Exception('Peer ID is required');
        }

        $mikrotik = new MikroTikAPI();
        $peer = $mikrotik->getWireGuardPeer($peer_id);
        if (!$peer) {
            throw new Exception('WireGuard peer not found');
        }

        $mikrotik->deleteWireGuardPeer($peer_id);

        echo json_encode([
            'success' => true,
            'message' => 'WireGuard peer ' . wireGuardPeerPrimaryName($peer) . ' deleted successfully',
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function toggleWireGuardPeerStatus($input)
{
    try {
        $peer_id = trim((string)($input['peer_id'] ?? ''));
        if ($peer_id === '') {
            throw new Exception('Peer ID is required');
        }

        $mikrotik = new MikroTikAPI();
        $peer = $mikrotik->getWireGuardPeer($peer_id);
        if (!$peer) {
            throw new Exception('WireGuard peer not found');
        }

        $enable = !normalizeInputBoolean($peer['disabled'] ?? false);
        $updated = $mikrotik->toggleWireGuardPeer($peer_id, $enable);

        echo json_encode([
            'success' => true,
            'message' => 'WireGuard peer ' . wireGuardPeerPrimaryName($updated) . ' ' . ($enable ? 'enabled' : 'disabled') . ' successfully',
            'data' => normalizeWireGuardPeerForUi($mikrotik, $updated, null, false),
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function bulkDeleteWireGuardPeers($input)
{
    try {
        $peer_ids = $input['peer_ids'] ?? null;
        if (!is_array($peer_ids) || empty($peer_ids)) {
            throw new Exception('Peer IDs array is required');
        }

        $mikrotik = new MikroTikAPI();
        $deleted = 0;
        $errors = [];

        foreach ($peer_ids as $peer_id) {
            $peer_id = trim((string)$peer_id);
            if ($peer_id === '') {
                continue;
            }

            try {
                $peer = $mikrotik->getWireGuardPeer($peer_id);
                if (!$peer) {
                    $errors[] = 'Peer ' . $peer_id . ' not found';
                    continue;
                }

                $mikrotik->deleteWireGuardPeer($peer_id);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = 'Peer ' . $peer_id . ': ' . $e->getMessage();
            }
        }

        if ($deleted === 0) {
            throw new Exception('No WireGuard peers could be deleted. ' . implode('; ', $errors));
        }

        echo json_encode([
            'success' => true,
            'message' => $deleted . ' WireGuard peer(s) deleted successfully' . (!empty($errors) ? '. ' . implode('; ', $errors) : ''),
            'deleted_count' => $deleted,
            'errors' => $errors,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function bulkToggleWireGuardPeers($input)
{
    try {
        $peer_ids = $input['peer_ids'] ?? null;
        if (!is_array($peer_ids) || empty($peer_ids)) {
            throw new Exception('Peer IDs array is required');
        }

        $mikrotik = new MikroTikAPI();
        $updated = 0;
        $errors = [];

        foreach ($peer_ids as $peer_id) {
            $peer_id = trim((string)$peer_id);
            if ($peer_id === '') {
                continue;
            }

            try {
                $peer = $mikrotik->getWireGuardPeer($peer_id);
                if (!$peer) {
                    $errors[] = 'Peer ' . $peer_id . ' not found';
                    continue;
                }

                $enable = !normalizeInputBoolean($peer['disabled'] ?? false);
                $mikrotik->toggleWireGuardPeer($peer_id, $enable);
                $updated++;
            } catch (Exception $e) {
                $errors[] = 'Peer ' . $peer_id . ': ' . $e->getMessage();
            }
        }

        if ($updated === 0) {
            throw new Exception('No WireGuard peers could be updated. ' . implode('; ', $errors));
        }

        echo json_encode([
            'success' => true,
            'message' => $updated . ' WireGuard peer(s) updated successfully' . (!empty($errors) ? '. ' . implode('; ', $errors) : ''),
            'updated_count' => $updated,
            'errors' => $errors,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function getWireGuardPeerDetails($input)
{
    try {
        $peer_id = trim((string)($input['peer_id'] ?? ''));
        if ($peer_id === '') {
            throw new Exception('Peer ID is required');
        }

        $mikrotik = new MikroTikAPI();
        $peer = $mikrotik->getWireGuardPeer($peer_id);
        if (!$peer) {
            throw new Exception('WireGuard peer not found');
        }

        echo json_encode([
            'success' => true,
            'data' => normalizeWireGuardPeerForUi($mikrotik, $peer, null, true),
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

function getAvailableWireGuardIP($input)
{
    try {
        $mikrotik = new MikroTikAPI();
        $defaults = getWireGuardRuntimeDefaults();
        $available = $mikrotik->getNextAvailableWireGuardClientAddress(
            trim((string)($input['interface'] ?? $defaults['interface'])) ?: $defaults['interface'],
            trim((string)($input['server_address'] ?? $defaults['server_address']))
        );

        echo json_encode([
            'success' => true,
            'data' => [
                'available_ip' => $available,
            ],
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}
