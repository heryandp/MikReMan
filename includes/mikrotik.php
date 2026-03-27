<?php

require_once __DIR__ . '/mikrotik_service_trait.php';
require_once __DIR__ . '/mikrotik_ppp_trait.php';
require_once __DIR__ . '/mikrotik_firewall_trait.php';
require_once __DIR__ . '/mikrotik_netwatch_trait.php';

class MikroTikAPI
{
    use MikroTikServiceTrait;
    use MikroTikPPPTrait;
    use MikroTikFirewallTrait;
    use MikroTikNetwatchTrait;

    private $host;
    private $username;
    private $password;
    private $port;
    private $use_ssl;
    private $base_url;
    private $timeout = 10;

    public function __construct($config = null) {
        if ($config === null) {
            $config = getConfig('mikrotik');
        }

        if (!$config) {
            throw new Exception('MikroTik configuration not found');
        }

        $this->host = $config['host'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->port = $config['port'] ?? '443';
        $this->use_ssl = $config['use_ssl'] ?? true;

        if (empty($this->host) || empty($this->username)) {
            throw new Exception('MikroTik host and username are required');
        }

        $protocol = $this->use_ssl ? 'https' : 'http';
        $this->base_url = $protocol . '://' . $this->host . ':' . $this->port . '/rest';
    }

    /**
     * Make HTTP request to MikroTik REST API
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->base_url . $endpoint;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($response === false) {
            throw new Exception('Cannot connect to MikroTik router at ' . $this->host . ':' . $this->port . ' - ' . $error);
        }

        if ($http_code >= 400) {
            $error_data = json_decode($response, true);
            $error_message = $error_data['message'] ?? 'HTTP error: ' . $http_code;
            throw new Exception($error_message);
        }

        if (empty($response)) {
            return ($method === 'DELETE' && $http_code >= 200 && $http_code < 300) ? true : null;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($method === 'DELETE' && $http_code >= 200 && $http_code < 300) {
                return true;
            }
            throw new Exception('Invalid JSON response from MikroTik');
        }

        return $decoded;
    }
}
