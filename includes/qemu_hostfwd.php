<?php

class QemuHostFwdManager {
    private $enabled;
    private $mode;
    private $socket_path;
    private $binary_path;
    private $ssh_host;
    private $ssh_port;
    private $ssh_user;
    private $ssh_private_key;
    private $ssh_known_hosts_path;
    private $ssh_binary;

    public function __construct(array $config = []) {
        $this->enabled = $this->normalizeBoolean($config['qemu_hostfwd_enabled'] ?? false);
        $this->mode = strtolower(trim((string)($config['qemu_hostfwd_mode'] ?? 'local')));
        $this->socket_path = trim((string)($config['qemu_hmp_socket'] ?? ''));
        $this->binary_path = trim((string)($config['qemu_hostfwd_binary'] ?? '/usr/bin/socat'));
        $this->ssh_host = trim((string)($config['qemu_ssh_host'] ?? ''));
        $this->ssh_port = trim((string)($config['qemu_ssh_port'] ?? '22'));
        $this->ssh_user = trim((string)($config['qemu_ssh_user'] ?? ''));
        $this->ssh_private_key = (string)($config['qemu_ssh_private_key'] ?? '');
        $this->ssh_known_hosts_path = trim((string)($config['qemu_ssh_known_hosts_path'] ?? ''));
        $this->ssh_binary = trim((string)($config['qemu_ssh_binary'] ?? '/usr/bin/ssh'));
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

    public function testAccess() {
        $result = $this->runDiagnosticCommand('info usernet');

        if (!empty($result['success'])) {
            $result['message'] = $this->mode === 'ssh'
                ? 'Remote SSH key and QEMU HMP access are working'
                : 'Local QEMU HMP socket access is working';
        }

        return $result;
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

    private function normalizePrivateKey($private_key) {
        $private_key = trim((string)$private_key);

        if ($private_key === '') {
            return '';
        }

        $private_key = str_replace(["\r\n", "\r"], "\n", $private_key);

        if (strpos($private_key, '\\n') !== false) {
            $private_key = str_replace(['\\r\\n', '\\n', '\\r'], ["\n", "\n", "\n"], $private_key);
        }

        return rtrim($private_key, "\n") . "\n";
    }

    private function runLocalCommand($command) {
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

        return $this->executeProcess(
            [$binary, '-', 'UNIX-CONNECT:' . $this->socket_path],
            $command
        );
    }

    private function buildRemoteSshCommand($key_path) {
        if ($this->ssh_host === '' || $this->ssh_user === '') {
            throw new RuntimeException('SSH host and user are required for remote hostfwd mode');
        }

        $ssh_binary = $this->ssh_binary !== '' ? $this->ssh_binary : '/usr/bin/ssh';
        if (!is_executable($ssh_binary)) {
            throw new RuntimeException('ssh binary is not executable: ' . $ssh_binary);
        }

        $remote_binary = $this->binary_path !== '' ? $this->binary_path : '/usr/bin/socat';
        $remote_target = 'UNIX-CONNECT:' . $this->socket_path;
        $destination = $this->ssh_user . '@' . $this->ssh_host;

        $command = [
            $ssh_binary,
            '-T',
            '-p',
            (string)$this->normalizePort($this->ssh_port, 'SSH port'),
            '-i',
            $key_path,
            '-o',
            'BatchMode=yes',
            '-o',
            'IdentitiesOnly=yes',
        ];

        if ($this->ssh_known_hosts_path !== '') {
            $command[] = '-o';
            $command[] = 'StrictHostKeyChecking=yes';
            $command[] = '-o';
            $command[] = 'UserKnownHostsFile=' . $this->ssh_known_hosts_path;
        } else {
            $command[] = '-o';
            $command[] = 'StrictHostKeyChecking=accept-new';
        }

        $command[] = $destination;
        $command[] = $remote_binary;
        $command[] = '-';
        $command[] = $remote_target;

        return $command;
    }

    private function runRemoteCommand($command) {
        if ($this->socket_path === '') {
            return [
                'success' => false,
                'message' => 'Remote QEMU HMP socket path is not configured'
            ];
        }

        $normalized_private_key = $this->normalizePrivateKey($this->ssh_private_key);

        if ($normalized_private_key === '') {
            return [
                'success' => false,
                'message' => 'SSH private key is not configured for remote hostfwd mode'
            ];
        }

        $key_path = tempnam(sys_get_temp_dir(), 'mikreman_ssh_');
        if ($key_path === false) {
            return [
                'success' => false,
                'message' => 'Failed to create temporary SSH key file'
            ];
        }

        try {
            if (file_put_contents($key_path, $normalized_private_key) === false) {
                throw new RuntimeException('Failed to write temporary SSH key');
            }
            chmod($key_path, 0600);

            $ssh_command = $this->buildRemoteSshCommand($key_path);
            return $this->executeProcess($ssh_command, $command);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } finally {
            if (file_exists($key_path)) {
                @unlink($key_path);
            }
        }
    }

    private function runDiagnosticCommand($command) {
        if ($this->mode === 'ssh') {
            return $this->runRemoteCommand($command);
        }

        return $this->runLocalCommand($command);
    }

    private function executeProcess(array $command, $stdin_payload) {
        $descriptor_spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = @proc_open($command, $descriptor_spec, $pipes);

        if (!is_resource($process)) {
            return [
                'success' => false,
                'message' => 'Failed to open process'
            ];
        }

        if (!isset($pipes[0]) || !is_resource($pipes[0])) {
            proc_close($process);
            return [
                'success' => false,
                'message' => 'Failed to open stdin pipe for process'
            ];
        }

        $write_result = @fwrite($pipes[0], $stdin_payload . PHP_EOL);

        if ($write_result === false) {
            fclose($pipes[0]);

            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }

            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }

            proc_close($process);
            return [
                'success' => false,
                'message' => 'Failed to write command to process stdin'
            ];
        }

        fclose($pipes[0]);

        $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }

        $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        $exit_code = proc_close($process);
        $output = trim($stdout . PHP_EOL . $stderr);

        if ($exit_code !== 0) {
            return [
                'success' => false,
                'message' => $output !== '' ? $output : 'Process exited with code ' . $exit_code
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
            'output' => $output
        ];
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
        $command = $this->buildCommand($operation, $protocol, $host_port, $guest_port);

        if ($this->mode === 'ssh') {
            $result = $this->runRemoteCommand($command);
        } else {
            $result = $this->runLocalCommand($command);
        }

        if (!empty($result['success'])) {
            $result['command'] = $command;
            $result['mode'] = $this->mode;
        }

        return $result;
    }
}

function getQemuHostFwdManager($config = null) {
    if ($config === null) {
        $config = getConfig('mikrotik') ?? [];
    }

    return new QemuHostFwdManager(is_array($config) ? $config : []);
}
