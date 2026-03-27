<?php

class QemuHostFwdManager {
    private $enabled;
    private $socket_path;
    private $binary_path;

    public function __construct(array $config = []) {
        $this->enabled = $this->normalizeBoolean($config['qemu_hostfwd_enabled'] ?? false);
        $this->socket_path = trim((string)($config['qemu_hmp_socket'] ?? ''));
        $this->binary_path = trim((string)($config['qemu_hostfwd_binary'] ?? '/usr/bin/socat'));
    }

    public function isEnabled() {
        return $this->enabled;
    }

    public function addForward($host_port, $guest_port, $protocol = 'tcp') {
        return $this->runHostFwdCommand('add', $host_port, $guest_port, $protocol);
    }

    public function removeForward($host_port, $protocol = 'tcp') {
        return $this->runHostFwdCommand('remove', $host_port, null, $protocol);
    }

    private function normalizeBoolean($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return !empty($value);
    }

    private function normalizeProtocol($protocol) {
        $protocol = strtolower(trim((string)$protocol));

        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            throw new InvalidArgumentException('Unsupported hostfwd protocol: ' . $protocol);
        }

        return $protocol;
    }

    private function normalizePort($port, $label) {
        if (!is_numeric($port)) {
            throw new InvalidArgumentException($label . ' must be numeric');
        }

        $port = (int)$port;

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException($label . ' must be between 1 and 65535');
        }

        return $port;
    }

    private function buildCommand($operation, $protocol, $host_port, $guest_port = null) {
        if ($operation === 'add') {
            return sprintf('hostfwd_add %s::%d-:%d', $protocol, $host_port, $guest_port);
        }

        return sprintf('hostfwd_remove %s::%d', $protocol, $host_port);
    }

    private function runHostFwdCommand($operation, $host_port, $guest_port, $protocol) {
        if (!$this->enabled) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'QEMU hostfwd integration is disabled'
            ];
        }

        $protocol = $this->normalizeProtocol($protocol);
        $host_port = $this->normalizePort($host_port, 'Host port');
        $guest_port = $guest_port === null ? null : $this->normalizePort($guest_port, 'Guest port');

        if ($this->socket_path === '') {
            return [
                'success' => false,
                'message' => 'QEMU HMP socket path is not configured'
            ];
        }

        if (!file_exists($this->socket_path)) {
            return [
                'success' => false,
                'message' => 'QEMU HMP socket not found: ' . $this->socket_path
            ];
        }

        $binary = $this->binary_path !== '' ? $this->binary_path : '/usr/bin/socat';

        if (!is_executable($binary)) {
            return [
                'success' => false,
                'message' => 'socat binary is not executable: ' . $binary
            ];
        }

        $command = $this->buildCommand($operation, $protocol, $host_port, $guest_port);
        $descriptor_spec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'r']
        ];

        $process = @proc_open(
            [$binary, '-', 'UNIX-CONNECT:' . $this->socket_path],
            $descriptor_spec,
            $pipes
        );

        if (!is_resource($process)) {
            return [
                'success' => false,
                'message' => 'Failed to open socat process'
            ];
        }

        fwrite($pipes[0], $command . PHP_EOL);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exit_code = proc_close($process);
        $output = trim($stdout . PHP_EOL . $stderr);

        if ($exit_code !== 0) {
            return [
                'success' => false,
                'message' => $output !== '' ? $output : 'socat exited with code ' . $exit_code
            ];
        }

        if (stripos($output, 'Error:') !== false || stripos($output, 'unknown command') !== false) {
            return [
                'success' => false,
                'message' => $output
            ];
        }

        return [
            'success' => true,
            'command' => $command,
            'output' => $output
        ];
    }
}

function getQemuHostFwdManager($config = null) {
    if ($config === null) {
        $config = getConfig('mikrotik') ?? [];
    }

    return new QemuHostFwdManager(is_array($config) ? $config : []);
}

