<?php

class WgEasyClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private array $cookies = [];

    public function __construct(array $config)
    {
        $baseUrl = rtrim(trim((string)($config['wg_easy_url'] ?? '')), '/');
        $username = trim((string)($config['wg_easy_username'] ?? ''));
        $password = (string)($config['wg_easy_password'] ?? '');

        if ($baseUrl === '') {
            throw new Exception('wg-easy URL is not configured.');
        }

        if ($username === '' || $password === '') {
            throw new Exception('wg-easy credentials are not configured.');
        }

        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
    }

    private function updateCookies(string $headerBlob): void
    {
        foreach (preg_split("/\r\n|\n|\r/", $headerBlob) as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }

            $cookieLine = trim(substr($line, strlen('Set-Cookie:')));
            if ($cookieLine === '') {
                continue;
            }

            $parts = explode(';', $cookieLine, 2);
            [$name, $value] = array_pad(explode('=', trim($parts[0]), 2), 2, '');
            $name = trim($name);

            if ($name !== '') {
                $this->cookies[$name] = $value;
            }
        }
    }

    private function buildCookieHeader(): string
    {
        if ($this->cookies === []) {
            return '';
        }

        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    private function request(string $method, string $path, ?array $payload = null, bool $expectJson = true)
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $curl = curl_init($url);

        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $cookieHeader = $this->buildCookieHeader();
        if ($cookieHeader !== '') {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);

        if ($response === false) {
            throw new Exception('Cannot connect to wg-easy: ' . $error);
        }

        $headerBlob = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $this->updateCookies($headerBlob);

        if ($status >= 400) {
            $message = 'wg-easy request failed with HTTP ' . $status;
            if ($expectJson) {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && !empty($decoded['message'])) {
                    $message = (string)$decoded['message'];
                } elseif (is_array($decoded) && !empty($decoded['statusMessage'])) {
                    $message = (string)$decoded['statusMessage'];
                }
            } elseif (trim($body) !== '') {
                $message = trim($body);
            }

            throw new Exception($message);
        }

        if (!$expectJson) {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from wg-easy');
        }

        return $decoded;
    }

    public function login(): void
    {
        $result = $this->request('POST', '/api/session', [
            'username' => $this->username,
            'password' => $this->password,
            'remember' => false,
        ]);

        $status = (string)($result['status'] ?? '');
        if ($status !== 'success') {
            throw new Exception('wg-easy login failed.');
        }
    }

    public function createClient(string $name, ?string $expiresAt = null): int
    {
        $result = $this->request('POST', '/api/client', [
            'name' => $name,
            'expiresAt' => $expiresAt,
        ]);

        if (empty($result['success']) || !isset($result['clientId'])) {
            throw new Exception('wg-easy did not return a client id.');
        }

        return (int)$result['clientId'];
    }

    public function getClient(int $clientId): array
    {
        $result = $this->request('GET', '/api/client/' . $clientId);
        if (!is_array($result) || $result === []) {
            throw new Exception('wg-easy client response was empty.');
        }

        return $result;
    }

    public function getClientConfiguration(int $clientId): string
    {
        return (string)$this->request('GET', '/api/client/' . $clientId . '/configuration', null, false);
    }

    public function deleteClient(int $clientId): bool
    {
        $result = $this->request('DELETE', '/api/client/' . $clientId);
        return !empty($result['success']);
    }

    public function updateUserConfig(array $payload): bool
    {
        $result = $this->request('POST', '/api/admin/userconfig', $payload);
        return !empty($result['success']);
    }

    public function getUserConfig(): array
    {
        $result = $this->request('GET', '/api/admin/userconfig');
        return is_array($result) ? $result : [];
    }
}

function usesWgEasyBackend(?array $config = null): bool
{
    $config = $config ?? (getConfig('mikrotik') ?? []);
    return strtolower(trim((string)($config['wireguard_backend'] ?? 'mikrotik'))) === 'wg-easy';
}

function getWgEasyClient(?array $config = null): WgEasyClient
{
    $config = $config ?? (getConfig('mikrotik') ?? []);
    return new WgEasyClient($config);
}
