<?php

trait MikroTikWireGuardTrait
{
    private function normalizeRouterBoolean($value): bool
    {
        return $value === true
            || $value === 'true'
            || $value === 'yes'
            || $value === 'enabled'
            || $value === 'on';
    }

    private function normalizeWireGuardCollection($result): array
    {
        if (!is_array($result)) {
            return [];
        }

        $items = isset($result[0]) ? $result : [$result];

        return array_values(array_filter($items, static function ($item): bool {
            if (!is_array($item) || $item === []) {
                return false;
            }

            foreach (['.id', 'name', 'public-key', 'allowed-address', 'interface', 'comment'] as $key) {
                if (!empty($item[$key])) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function getConfiguredWireGuardInterfaceName(): string
    {
        $config = getConfig('mikrotik') ?? [];
        $name = trim((string)($config['wireguard_interface'] ?? 'wireguard1'));

        return $name !== '' ? $name : 'wireguard1';
    }

    private function getConfiguredWireGuardPort(): int
    {
        $config = getConfig('mikrotik') ?? [];
        $port = (int)($config['wireguard_port'] ?? 13231);
        return ($port >= 1 && $port <= 65535) ? $port : 13231;
    }

    private function quoteRouterValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
    }

    private function normalizeIpv4Long(string $ip): ?int
    {
        $long = ip2long($ip);
        if ($long === false) {
            return null;
        }

        if ($long < 0) {
            $long += 4294967296;
        }

        return (int)$long;
    }

    private function longToIpv4(int $long): string
    {
        if ($long > 2147483647) {
            $long -= 4294967296;
        }

        return (string)long2ip($long);
    }

    private function normalizeWireGuardKeepaliveValue($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d+)\s*s$/i', $value, $matches)) {
            return $matches[1];
        }

        if (ctype_digit($value)) {
            return $value;
        }

        return preg_replace('/[^0-9]/', '', $value);
    }

    private function parseWireGuardIpv4Cidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if ($cidr === '' || !preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/', $cidr, $matches)) {
            return null;
        }

        $ip = $matches[1];
        $prefix = (int)$matches[2];
        if ($prefix < 0 || $prefix > 32 || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $ip_long = $this->normalizeIpv4Long($ip);
        if ($ip_long === null) {
            return null;
        }

        $mask = $prefix === 0
            ? 0
            : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $ip_long & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        return [
            'cidr' => $cidr,
            'ip' => $ip,
            'prefix' => $prefix,
            'ip_long' => $ip_long,
            'network' => (int)$network,
            'broadcast' => (int)$broadcast,
        ];
    }

    private function isIpv4InCidr(string $ip, array $cidr): bool
    {
        $ip_long = $this->normalizeIpv4Long($ip);
        if ($ip_long === null) {
            return false;
        }

        return $ip_long >= $cidr['network'] && $ip_long <= $cidr['broadcast'];
    }

    private function buildWireGuardEndpoint(string $host, int $port): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        return $host . ':' . $port;
    }

    private function formatRouterScriptValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_string($value) && strtolower($value) === 'auto') {
            return 'auto';
        }

        return $this->quoteRouterValue((string)$value);
    }

    private function buildRouterScriptArguments(array $payload): string
    {
        $parts = [];
        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            $parts[] = $key . '=' . $this->formatRouterScriptValue($value);
        }

        return implode(' ', $parts);
    }

    private function applyWireGuardPeerWrite(string $method, string $path, array $payload)
    {
        try {
            return $this->makeRequest($path, $method, $payload);
        } catch (Exception $e) {
            if (array_key_exists('name', $payload) && stripos($e->getMessage(), 'name') !== false) {
                unset($payload['name']);
                return $this->makeRequest($path, $method, $payload);
            }

            throw $e;
        }
    }

    public function getWireGuardInterfaces(): array
    {
        return $this->normalizeWireGuardCollection($this->makeRequest('/interface/wireguard'));
    }

    public function getWireGuardInterface($name = null): ?array
    {
        $target_name = trim((string)($name ?: $this->getConfiguredWireGuardInterfaceName()));

        foreach ($this->getWireGuardInterfaces() as $interface) {
            if (($interface['name'] ?? '') === $target_name) {
                return $interface;
            }
        }

        return null;
    }

    public function getWireGuardInterfaceStatus($name = null): bool
    {
        try {
            $interface = $this->getWireGuardInterface($name);
        } catch (Exception $e) {
            error_log('WireGuard status error: ' . $e->getMessage());
            return false;
        }

        if (!$interface) {
            return false;
        }

        return !$this->normalizeRouterBoolean($interface['disabled'] ?? false);
    }

    public function createOrUpdateWireGuardInterface(array $options = []): array
    {
        $config = getConfig('mikrotik') ?? [];
        $name = trim((string)($options['name'] ?? $config['wireguard_interface'] ?? 'wireguard1'));
        $listen_port = (int)($options['listen_port'] ?? $config['wireguard_port'] ?? 13231);
        $mtu = (int)($options['mtu'] ?? $config['wireguard_mtu'] ?? 1420);
        $comment = trim((string)($options['comment'] ?? 'Managed by MikReMan'));

        if ($name === '') {
            throw new Exception('WireGuard interface name is required');
        }

        if ($listen_port < 1 || $listen_port > 65535) {
            throw new Exception('WireGuard listen port must be between 1 and 65535');
        }

        if ($mtu < 1280 || $mtu > 65535) {
            throw new Exception('WireGuard MTU must be between 1280 and 65535');
        }

        $existing = $this->getWireGuardInterface($name);
        $quoted_name = $this->quoteRouterValue($name);
        $quoted_comment = $this->quoteRouterValue($comment);

        if ($existing) {
            $script = sprintf(
                '/interface wireguard set [find where name=%s] listen-port=%d mtu=%d disabled=no comment=%s',
                $quoted_name,
                $listen_port,
                $mtu,
                $quoted_comment
            );
        } else {
            $script = sprintf(
                '/interface wireguard add name=%s listen-port=%d mtu=%d disabled=no comment=%s',
                $quoted_name,
                $listen_port,
                $mtu,
                $quoted_comment
            );
        }

        $this->makeRequest('/execute', 'POST', [
            'script' => $script
        ]);

        $this->ensureWireGuardServerAddress([
            'interface' => $name,
            'address' => $options['server_address'] ?? ($config['wireguard_server_address'] ?? ''),
        ]);

        $interface = $this->getWireGuardInterface($name);

        if (!$interface) {
            throw new Exception('WireGuard interface was not found after provisioning');
        }

        return $interface;
    }

    public function toggleWireGuardInterface($enable = true, $name = null): array
    {
        $target_name = trim((string)($name ?: $this->getConfiguredWireGuardInterfaceName()));
        $interface = $this->getWireGuardInterface($target_name);

        if (!$interface) {
            throw new Exception('WireGuard interface not found: ' . $target_name);
        }

        $script = sprintf(
            '/interface wireguard set [find where name=%s] disabled=%s',
            $this->quoteRouterValue($target_name),
            $enable ? 'no' : 'yes'
        );

        $this->makeRequest('/execute', 'POST', [
            'script' => $script
        ]);

        $updated = $this->getWireGuardInterface($target_name);

        if (!$updated) {
            throw new Exception('WireGuard interface state could not be verified');
        }

        return $updated;
    }

    public function getWireGuardPeers(): array
    {
        return $this->normalizeWireGuardCollection($this->makeRequest('/interface/wireguard/peers'));
    }

    public function getWireGuardPeer(string $peer_id): ?array
    {
        foreach ($this->getWireGuardPeers() as $peer) {
            if (($peer['.id'] ?? '') === $peer_id) {
                return $peer;
            }
        }

        return null;
    }

    public function getWireGuardIPAddresses(?string $interface_name = null): array
    {
        $addresses = $this->normalizeWireGuardCollection($this->makeRequest('/ip/address'));

        if ($interface_name === null || trim($interface_name) === '') {
            return $addresses;
        }

        return array_values(array_filter($addresses, static function (array $address) use ($interface_name): bool {
            return ($address['interface'] ?? '') === $interface_name;
        }));
    }

    public function getWireGuardServerAddress(?string $interface_name = null): ?array
    {
        $target = trim((string)($interface_name ?: $this->getConfiguredWireGuardInterfaceName()));

        foreach ($this->getWireGuardIPAddresses($target) as $address) {
            if (!empty($address['address']) && strpos((string)$address['address'], '/') !== false) {
                return $address;
            }
        }

        return null;
    }

    public function ensureWireGuardServerAddress(array $options = []): ?array
    {
        $config = getConfig('mikrotik') ?? [];
        $interface_name = trim((string)($options['interface'] ?? $options['name'] ?? $config['wireguard_interface'] ?? 'wireguard1'));
        $target_address = trim((string)($options['address'] ?? $config['wireguard_server_address'] ?? ''));

        if ($interface_name === '') {
            throw new Exception('WireGuard interface name is required for IP address provisioning');
        }

        $existing = $this->getWireGuardServerAddress($interface_name);
        if ($existing) {
            if ($target_address === '' || ($existing['address'] ?? '') === $target_address) {
                return $existing;
            }

            return $existing;
        }

        if ($target_address === '') {
            return null;
        }

        if ($this->parseWireGuardIpv4Cidr($target_address) === null) {
            throw new Exception('WireGuard server address must be a valid IPv4 CIDR value');
        }

        $created = $this->makeRequest('/ip/address', 'PUT', [
            'address' => $target_address,
            'interface' => $interface_name,
            'comment' => 'Managed by MikReMan WireGuard'
        ]);

        if (is_array($created) && !empty($created['.id'])) {
            foreach ($this->getWireGuardIPAddresses($interface_name) as $address) {
                if (($address['.id'] ?? '') === $created['.id']) {
                    return $address;
                }
            }
        }

        return $this->getWireGuardServerAddress($interface_name);
    }

    public function getNextAvailableWireGuardClientAddress(?string $interface_name = null, ?string $server_address = null): string
    {
        $target = trim((string)($interface_name ?: $this->getConfiguredWireGuardInterfaceName()));
        $server_entry = null;

        if ($server_address !== null && trim($server_address) !== '') {
            $server_entry = ['address' => trim($server_address)];
        } else {
            $server_entry = $this->ensureWireGuardServerAddress(['interface' => $target]);
        }

        if (!$server_entry || empty($server_entry['address'])) {
            throw new Exception('Configure a WireGuard server address before creating peers');
        }

        $cidr = $this->parseWireGuardIpv4Cidr((string)$server_entry['address']);
        if ($cidr === null) {
            throw new Exception('WireGuard server address must use an IPv4 CIDR subnet');
        }

        if ($cidr['prefix'] > 30) {
            throw new Exception('WireGuard server subnet is too small for client allocation');
        }

        $used = [
            $cidr['ip'] => true,
        ];

        foreach ($this->getWireGuardPeers() as $peer) {
            if (($peer['interface'] ?? '') !== $target) {
                continue;
            }

            $allowed_addresses = array_map('trim', explode(',', (string)($peer['allowed-address'] ?? '')));
            foreach ($allowed_addresses as $allowed_address) {
                $peer_cidr = $this->parseWireGuardIpv4Cidr($allowed_address);
                if ($peer_cidr && $this->isIpv4InCidr($peer_cidr['ip'], $cidr)) {
                    $used[$peer_cidr['ip']] = true;
                }
            }
        }

        for ($candidate = $cidr['network'] + 1; $candidate < $cidr['broadcast']; $candidate++) {
            $ip = $this->longToIpv4($candidate);
            if (!isset($used[$ip])) {
                return $ip . '/32';
            }
        }

        throw new Exception('No available WireGuard client address remains in the configured subnet');
    }

    public function createWireGuardPeer(array $options = []): array
    {
        $config = getConfig('mikrotik') ?? [];
        $interface_name = trim((string)($options['interface'] ?? $config['wireguard_interface'] ?? 'wireguard1'));

        $this->createOrUpdateWireGuardInterface([
            'name' => $interface_name,
            'listen_port' => $options['listen_port'] ?? ($config['wireguard_port'] ?? 13231),
            'mtu' => $options['mtu'] ?? ($config['wireguard_mtu'] ?? 1420),
            'server_address' => $options['server_address'] ?? ($config['wireguard_server_address'] ?? ''),
        ]);

        $allowed_address = trim((string)($options['allowed_address'] ?? ''));
        if ($allowed_address === '') {
            $allowed_address = $this->getNextAvailableWireGuardClientAddress($interface_name, $options['server_address'] ?? null);
        }

        if ($this->parseWireGuardIpv4Cidr($allowed_address) === null) {
            throw new Exception('Allowed address must be a valid IPv4 CIDR value');
        }

        $keepalive = (int)($options['persistent_keepalive'] ?? $config['wireguard_keepalive'] ?? 25);
        $comment = trim((string)($options['comment'] ?? ''));
        $name = trim((string)($options['name'] ?? ''));
        $payload = [
            'interface' => $interface_name,
            'allowed-address' => $allowed_address,
            'private-key' => (string)($options['private_key'] ?? 'auto'),
            'persistent-keepalive' => $keepalive,
        ];

        if ($comment !== '') {
            $payload['comment'] = $comment;
        }

        if ($name !== '') {
            $payload['name'] = $name;
        }

        if (!empty($options['preshared_key'])) {
            $payload['preshared-key'] = (string)$options['preshared_key'];
        }

        $script = '/interface wireguard peers add ' . $this->buildRouterScriptArguments($payload);
        $result = $this->runScript($script);

        if (is_array($result) && !empty($result['ret'])) {
            $peer = $this->getWireGuardPeer((string)$result['ret']);
            if ($peer) {
                if (!empty($options['disabled'])) {
                    return $this->toggleWireGuardPeer((string)$peer['.id'], false);
                }

                return $peer;
            }
        }

        foreach (array_reverse($this->getWireGuardPeers()) as $peer) {
            if (($peer['interface'] ?? '') !== $interface_name) {
                continue;
            }

            if (($peer['allowed-address'] ?? '') !== $allowed_address) {
                continue;
            }

            if ($comment !== '' && ($peer['comment'] ?? '') !== $comment) {
                continue;
            }

            if (!empty($options['disabled']) && !empty($peer['.id'])) {
                return $this->toggleWireGuardPeer((string)$peer['.id'], false);
            }

            return $peer;
        }

        throw new Exception('WireGuard peer was not found after creation');
    }

    public function updateWireGuardPeer(string $peer_id, array $options = []): array
    {
        $existing = $this->getWireGuardPeer($peer_id);
        if (!$existing) {
            throw new Exception('WireGuard peer not found');
        }

        $config = getConfig('mikrotik') ?? [];
        $interface_name = trim((string)($options['interface'] ?? $existing['interface'] ?? $config['wireguard_interface'] ?? 'wireguard1'));
        $payload = [];

        if (array_key_exists('comment', $options)) {
            $payload['comment'] = trim((string)$options['comment']);
        }

        if (array_key_exists('name', $options)) {
            $payload['name'] = trim((string)$options['name']);
        }

        if (array_key_exists('allowed_address', $options)) {
            $allowed_address = trim((string)$options['allowed_address']);
            if ($allowed_address === '') {
                $allowed_address = $this->getNextAvailableWireGuardClientAddress($interface_name, $options['server_address'] ?? null);
            }

            if ($this->parseWireGuardIpv4Cidr($allowed_address) === null) {
                throw new Exception('Allowed address must be a valid IPv4 CIDR value');
            }

            $payload['allowed-address'] = $allowed_address;
        }

        if (array_key_exists('interface', $options)) {
            $payload['interface'] = $interface_name;
        }

        if (array_key_exists('disabled', $options)) {
            $payload['disabled'] = !empty($options['disabled']);
        }

        if (array_key_exists('persistent_keepalive', $options)) {
            $payload['persistent-keepalive'] = (int)$options['persistent_keepalive'];
        }

        if (!empty($options['regenerate_private_key'])) {
            $payload['private-key'] = 'auto';
        }

        if (!empty($options['preshared_key'])) {
            $payload['preshared-key'] = (string)$options['preshared_key'];
        }

        if (empty($payload)) {
            throw new Exception('No WireGuard peer fields were provided for update');
        }

        $script = '/interface wireguard peers set [find where .id=' . $peer_id . '] ' . $this->buildRouterScriptArguments($payload);
        $this->runScript($script);

        $updated = $this->getWireGuardPeer($peer_id);
        if (!$updated) {
            throw new Exception('WireGuard peer could not be verified after update');
        }

        return $updated;
    }

    public function toggleWireGuardPeer(string $peer_id, bool $enable = true): array
    {
        return $this->updateWireGuardPeer($peer_id, [
            'disabled' => !$enable,
        ]);
    }

    public function deleteWireGuardPeer(string $peer_id): bool
    {
        return (bool)$this->makeRequest('/interface/wireguard/peers/' . $peer_id, 'DELETE');
    }

    public function buildWireGuardClientConfig(array $peer, array $options = []): string
    {
        $interface_name = trim((string)($peer['interface'] ?? $options['interface'] ?? $this->getConfiguredWireGuardInterfaceName()));
        $interface = $options['interface_data'] ?? $this->getWireGuardInterface($interface_name);
        if (!$interface) {
            throw new Exception('WireGuard interface not found while generating client config');
        }

        $private_key = trim((string)($peer['private-key'] ?? ''));
        $server_public_key = trim((string)($interface['public-key'] ?? ''));
        if ($private_key === '' || $server_public_key === '') {
            throw new Exception('WireGuard keys are incomplete for client config generation');
        }

        $config = getConfig('mikrotik') ?? [];
        $client_address = trim((string)($options['client_address'] ?? $peer['allowed-address'] ?? ''));
        $client_dns = trim((string)($options['client_dns'] ?? $config['wireguard_client_dns'] ?? ''));
        $endpoint = $this->buildWireGuardEndpoint(
            trim((string)($options['endpoint_host'] ?? $config['wireguard_host'] ?? $config['host'] ?? '')),
            (int)($options['endpoint_port'] ?? $config['wireguard_port'] ?? $this->getConfiguredWireGuardPort())
        );
        $allowed_ips = trim((string)($options['allowed_ips'] ?? $config['wireguard_allowed_ips'] ?? '0.0.0.0/0, ::/0'));
        $keepalive = $this->normalizeWireGuardKeepaliveValue($peer['persistent-keepalive'] ?? $config['wireguard_keepalive'] ?? '25');
        $mtu = trim((string)($interface['mtu'] ?? $config['wireguard_mtu'] ?? '1420'));
        $preshared_key = trim((string)($peer['preshared-key'] ?? ''));
        $suggested_name = trim((string)($options['suggested_name'] ?? ''));

        $lines = [];
        if ($suggested_name !== '') {
            $lines[] = '# Suggested Name: ' . $suggested_name;
            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '[Interface]',
            'PrivateKey = ' . $private_key,
            'Address = ' . $client_address,
        ]);

        if ($client_dns !== '') {
            $lines[] = 'DNS = ' . $client_dns;
        }

        if ($mtu !== '') {
            $lines[] = 'MTU = ' . $mtu;
        }

        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = ' . $server_public_key;

        if ($preshared_key !== '') {
            $lines[] = 'PresharedKey = ' . $preshared_key;
        }

        if ($endpoint !== '') {
            $lines[] = 'Endpoint = ' . $endpoint;
        }

        $lines[] = 'AllowedIPs = ' . $allowed_ips;

        if ($keepalive !== '' && $keepalive !== '0') {
            $lines[] = 'PersistentKeepalive = ' . $keepalive;
        }

        return implode("\n", $lines);
    }
}
