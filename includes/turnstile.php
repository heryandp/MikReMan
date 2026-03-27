<?php

function getTurnstileRequestHost(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    $host = trim($host);

    if ($host === '') {
        return '';
    }

    if ($host[0] === '[') {
        $end = strpos($host, ']');
        if ($end !== false) {
            return substr($host, 1, $end - 1);
        }
    }

    $parts = explode(':', $host, 2);
    return strtolower(trim($parts[0]));
}

function shouldBypassTurnstileForLocalDev(): bool
{
    $host = getTurnstileRequestHost();

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function getTurnstileConfig(): array
{
    $config = getConfig('cloudflare');

    return is_array($config) ? $config : [];
}

function isTurnstileEnabledFor(string $context): bool
{
    if (shouldBypassTurnstileForLocalDev()) {
        return false;
    }

    $config = getTurnstileConfig();
    $siteKey = trim((string) ($config['turnstile_site_key'] ?? ''));
    $secretKey = trim((string) ($config['turnstile_secret_key'] ?? ''));

    if (empty($config['turnstile_enabled']) || $siteKey === '' || $secretKey === '') {
        return false;
    }

    if ($context === 'login') {
        return !empty($config['turnstile_login_enabled']);
    }

    if ($context === 'order') {
        return !empty($config['turnstile_order_enabled']);
    }

    return false;
}

function getTurnstileSiteKey(): string
{
    $config = getTurnstileConfig();
    return trim((string) ($config['turnstile_site_key'] ?? ''));
}

function renderTurnstileAssets(): void
{
    if (shouldBypassTurnstileForLocalDev()) {
        return;
    }

    ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php
}

function renderTurnstileWidget(string $context, string $theme = 'auto'): void
{
    if (!isTurnstileEnabledFor($context)) {
        return;
    }

    $siteKey = getTurnstileSiteKey();
    if ($siteKey === '') {
        return;
    }
    ?>
    <div class="field">
        <div class="control">
            <div class="cf-turnstile"
                 data-sitekey="<?php echo htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8'); ?>"
                 data-theme="<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?>"
                 data-action="<?php echo htmlspecialchars($context, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>
    </div>
    <?php
}

function validateTurnstileToken(?string $token, ?string $remoteIp, string $expectedAction): array
{
    if (shouldBypassTurnstileForLocalDev()) {
        return [
            'success' => true,
            'message' => 'Turnstile bypassed for local development.'
        ];
    }

    $config = getTurnstileConfig();
    $secretKey = trim((string) ($config['turnstile_secret_key'] ?? ''));

    if ($secretKey === '') {
        return [
            'success' => false,
            'message' => 'Turnstile secret key is not configured.'
        ];
    }

    $token = trim((string) $token);
    if ($token === '') {
        return [
            'success' => false,
            'message' => 'Please complete the security check.'
        ];
    }

    $payload = [
        'secret' => $secretKey,
        'response' => $token,
    ];

    $remoteIp = trim((string) $remoteIp);
    if ($remoteIp !== '') {
        $payload['remoteip'] = $remoteIp;
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return [
            'success' => false,
            'message' => 'Unable to verify the security check right now.'
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || $statusCode < 200 || $statusCode >= 300) {
        return [
            'success' => false,
            'message' => 'Invalid response from Turnstile verification.'
        ];
    }

    if (empty($decoded['success'])) {
        $codes = $decoded['error-codes'] ?? [];
        $suffix = is_array($codes) && !empty($codes) ? ' (' . implode(', ', $codes) . ')' : '';
        return [
            'success' => false,
            'message' => 'Security check verification failed' . $suffix . '.'
        ];
    }

    if ($expectedAction !== '' && isset($decoded['action']) && $decoded['action'] !== $expectedAction) {
        return [
            'success' => false,
            'message' => 'Security check action did not match the request.'
        ];
    }

    return [
        'success' => true,
        'message' => 'Turnstile verification passed.'
    ];
}
