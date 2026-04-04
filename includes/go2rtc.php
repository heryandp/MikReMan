<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cctv_usage.php';
require_once __DIR__ . '/ui.php';

function go2rtcNormalizeRequestHost($host): string
{
    $host = trim((string)$host);
    if ($host === '') {
        return 'localhost';
    }

    if (preg_match('/^\[(.*)\](?::\d+)?$/', $host, $matches)) {
        return $matches[1];
    }

    if (substr_count($host, ':') === 1) {
        [$host] = explode(':', $host, 2);
    }

    return $host;
}

function go2rtcNormalizeBaseUrl($url): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }

    return rtrim($url, '/');
}

function go2rtcEnsureTrailingSlash(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    return rtrim($url, '/') . '/';
}

function go2rtcBuildCandidates(array $mikrotikConfig): array
{
    $configuredApiUrl = go2rtcNormalizeBaseUrl($mikrotikConfig['go2rtc_api_url'] ?? '');
    if ($configuredApiUrl !== '') {
        return [$configuredApiUrl];
    }

    $publicHost = go2rtcNormalizeRequestHost(getPublicRequestHost());

    return [
        'http://172.17.0.1:1984',
        'http://host.docker.internal:1984',
        'http://' . $publicHost . ':1984',
        'http://127.0.0.1:1984',
    ];
}

function go2rtcGetConnectionDetails(?array $mikrotikConfig = null): array
{
    $mikrotikConfig = $mikrotikConfig ?? (getConfig('mikrotik') ?: []);
    $publicHost = go2rtcNormalizeRequestHost(getPublicRequestHost());
    $candidates = go2rtcBuildCandidates($mikrotikConfig);
    $configuredApiUrl = go2rtcNormalizeBaseUrl($mikrotikConfig['go2rtc_api_url'] ?? '');
    $apiTarget = $candidates[0] ?? '';
    $defaultWebBase = $configuredApiUrl !== '' ? $configuredApiUrl : ('http://' . $publicHost . ':1984');
    $webUrl = go2rtcEnsureTrailingSlash(
        go2rtcNormalizeBaseUrl($mikrotikConfig['go2rtc_web_url'] ?? '') ?: $defaultWebBase
    );
    $rtspHost = trim((string)($mikrotikConfig['go2rtc_rtsp_host'] ?? '')) ?: $publicHost;
    $rtspPort = trim((string)($mikrotikConfig['go2rtc_rtsp_port'] ?? '')) ?: '8554';
    $webrtcUrl = go2rtcEnsureTrailingSlash(
        go2rtcNormalizeBaseUrl($mikrotikConfig['go2rtc_webrtc_url'] ?? '') ?: $webUrl
    );

    return [
        'candidates' => $candidates,
        'configured_api_url' => $configuredApiUrl,
        'api_target' => $apiTarget,
        'web_url' => $webUrl,
        'rtsp_host' => $rtspHost,
        'rtsp_port' => $rtspPort,
        'rtsp_url_template' => 'rtsp://' . $rtspHost . ':' . $rtspPort . '/{stream_name}',
        'webrtc_url' => $webrtcUrl,
        'endpoint_mode' => $configuredApiUrl !== '' ? 'Manual' : 'Auto',
    ];
}

function go2rtcRequest(string $path, array $options = []): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'base_url' => '',
            'body' => '',
            'status' => 0,
            'error' => 'PHP cURL extension is not available',
        ];
    }

    $method = strtoupper((string)($options['method'] ?? 'GET'));
    $query = is_array($options['query'] ?? null) ? $options['query'] : [];
    $body = $options['body'] ?? null;
    $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
    $baseUrls = array_values(array_filter(array_map(
        'go2rtcNormalizeBaseUrl',
        $options['base_urls'] ?? go2rtcGetConnectionDetails()['candidates']
    )));

    if (empty($baseUrls)) {
        return [
            'ok' => false,
            'base_url' => '',
            'body' => '',
            'status' => 0,
            'error' => 'go2rtc API URL is not configured',
        ];
    }

    $lastError = 'go2rtc is unreachable';

    foreach ($baseUrls as $baseUrl) {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $curl = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $curlOptions);
        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($responseBody === false || $status >= 400 || $status === 0) {
            $lastError = $error !== '' ? $error : ('HTTP ' . $status);
            continue;
        }

        return [
            'ok' => true,
            'base_url' => $baseUrl,
            'body' => (string)$responseBody,
            'status' => $status,
        ];
    }

    return [
        'ok' => false,
        'base_url' => '',
        'body' => '',
        'status' => 0,
        'error' => $lastError,
    ];
}

function go2rtcDecodeJsonResponse(array $response): array
{
    if (empty($response['ok'])) {
        return [];
    }

    $decoded = json_decode((string)($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function go2rtcGetConfigText(?array $mikrotikConfig = null): string
{
    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $response = go2rtcRequest('/api/config', ['base_urls' => $connection['candidates']]);

    if (empty($response['ok'])) {
        throw new RuntimeException($response['error'] ?? 'Failed to load go2rtc config');
    }

    return (string)($response['body'] ?? '');
}

function go2rtcSaveConfigText(string $yaml, ?array $mikrotikConfig = null): void
{
    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $saveResponse = go2rtcRequest('/api/config', [
        'method' => 'POST',
        'base_urls' => $connection['candidates'],
        'body' => $yaml,
        'headers' => ['Content-Type: text/plain; charset=utf-8'],
    ]);

    if (empty($saveResponse['ok'])) {
        throw new RuntimeException($saveResponse['error'] ?? 'Failed to save go2rtc config');
    }

    $restartResponse = go2rtcRequest('/api/restart', [
        'method' => 'POST',
        'base_urls' => $connection['candidates'],
    ]);

    if (empty($restartResponse['ok'])) {
        throw new RuntimeException($restartResponse['error'] ?? 'go2rtc config saved but restart failed');
    }
}

function go2rtcProxyMjpegStream(string $name, ?array $mikrotikConfig = null): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Stream name is required');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is not available');
    }

    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $baseUrls = $connection['candidates'] ?? [];
    $lastError = 'go2rtc preview is unreachable';

    @set_time_limit(0);
    ignore_user_abort(true);

    foreach ($baseUrls as $baseUrl) {
        $url = rtrim((string)$baseUrl, '/') . '/api/stream.mjpeg?src=' . rawurlencode($name);
        $statusCode = 0;
        $contentType = 'multipart/x-mixed-replace; boundary=frame';
        $headersSent = false;

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_BUFFERSIZE => 8192,
            CURLOPT_HTTPHEADER => ['Accept: multipart/x-mixed-replace'],
            CURLOPT_HEADERFUNCTION => static function ($ch, $headerLine) use (&$statusCode, &$contentType, &$headersSent) {
                $trimmed = trim($headerLine);

                if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $trimmed, $matches)) {
                    $statusCode = (int)$matches[1];
                } elseif (stripos($trimmed, 'Content-Type:') === 0) {
                    $contentType = trim(substr($trimmed, strlen('Content-Type:')));
                } elseif ($trimmed === '' && !$headersSent && $statusCode < 400) {
                    http_response_code($statusCode > 0 ? $statusCode : 200);
                    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'multipart/x-mixed-replace; boundary=frame'));
                    header('Cache-Control: no-store, no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    header('X-Accel-Buffering: no');
                    $headersSent = true;
                    @ob_flush();
                    flush();
                }

                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$headersSent, &$statusCode, &$contentType) {
                if (!$headersSent) {
                    if ($statusCode >= 400) {
                        return 0;
                    }

                    http_response_code($statusCode > 0 ? $statusCode : 200);
                    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'multipart/x-mixed-replace; boundary=frame'));
                    header('Cache-Control: no-store, no-cache, must-revalidate');
                    header('Pragma: no-cache');
                    header('X-Accel-Buffering: no');
                    $headersSent = true;
                }

                echo $chunk;
                @ob_flush();
                flush();

                return strlen($chunk);
            },
        ]);

        $result = curl_exec($curl);
        $error = curl_error($curl);
        $curlStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($result !== false && $headersSent && $curlStatus < 400) {
            return;
        }

        $lastError = $error !== '' ? $error : ('HTTP ' . ($curlStatus > 0 ? $curlStatus : $statusCode));
    }

    throw new RuntimeException($lastError);
}

function go2rtcParseTopLevelBlocks(string $yaml): array
{
    $lines = preg_split('/\r\n|\n|\r/', $yaml);
    $prefix = [];
    $blocks = [];
    $order = [];
    $current = null;

    foreach ($lines as $line) {
        if (preg_match('/^([A-Za-z0-9_-]+):(.*)$/', $line, $matches) && !preg_match('/^[ \t]/', $line)) {
            $current = $matches[1];
            if (!isset($blocks[$current])) {
                $blocks[$current] = [];
                $order[] = $current;
            }
        }

        if ($current === null) {
            $prefix[] = $line;
            continue;
        }

        $blocks[$current][] = $line;
    }

    return [
        'prefix' => $prefix,
        'order' => $order,
        'blocks' => $blocks,
    ];
}

function go2rtcParseNamedEntriesBlock(array $blockLines): array
{
    if (empty($blockLines)) {
        return ['heading' => '', 'entries' => [], 'orphan' => []];
    }

    $heading = array_shift($blockLines);
    $entries = [];
    $orphan = [];
    $currentName = null;
    $currentLines = [];

    foreach ($blockLines as $line) {
        if (preg_match('/^  (?:"([^"]+)"|\'([^\']+)\'|([^:]+)):(.*)$/', $line, $matches)) {
            $candidateName = trim((string)($matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3])));
            if (preg_match('/^-+\s*/', $candidateName) === 1) {
                if ($currentName === null) {
                    $orphan[] = $line;
                } else {
                    $currentLines[] = $line;
                }
                continue;
            }

            if ($currentName !== null) {
                $entries[$currentName] = $currentLines;
            }

            $currentName = $candidateName;
            $currentLines = [$line];
            continue;
        }

        if ($currentName === null) {
            $orphan[] = $line;
            continue;
        }

        $currentLines[] = $line;
    }

    if ($currentName !== null) {
        $entries[$currentName] = $currentLines;
    }

    return [
        'heading' => $heading,
        'entries' => $entries,
        'orphan' => $orphan,
    ];
}

function go2rtcBuildNamedEntriesBlock(string $name, array $entries, array $orphan = []): string
{
    $lines = [$name . ':'];
    foreach ($orphan as $line) {
        $lines[] = rtrim((string)$line, "\r\n");
    }

    foreach ($entries as $entryLines) {
        foreach ($entryLines as $line) {
            $lines[] = rtrim((string)$line, "\r\n");
        }
    }

    return rtrim(implode("\n", $lines)) . "\n";
}

function go2rtcBuildYamlFromBlocks(array $document): string
{
    $chunks = [];
    $prefix = $document['prefix'] ?? [];
    if (!empty($prefix)) {
        $chunks[] = rtrim(implode("\n", $prefix), "\n");
    }

    foreach ($document['order'] ?? [] as $name) {
        if (!isset($document['blocks'][$name])) {
            continue;
        }
        $chunks[] = rtrim(implode("\n", $document['blocks'][$name]), "\n");
    }

    return implode("\n", array_filter($chunks, static fn($part) => $part !== '')) . "\n";
}

function go2rtcUnquoteYamlScalar(string $value): string
{
    $value = trim($value);
    $length = strlen($value);
    if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
        return stripcslashes(substr($value, 1, -1));
    }

    if ($length >= 2 && $value[0] === '\'' && $value[$length - 1] === '\'') {
        return str_replace("''", "'", substr($value, 1, -1));
    }

    return $value;
}

function go2rtcExtractEntryPrimaryValue(array $entryLines): string
{
    if (empty($entryLines)) {
        return '';
    }

    $first = (string)$entryLines[0];
    if (preg_match('/^  (?:"[^"]+"|\'[^\']+\'|[^:]+):\s*(.+?)\s*$/', $first, $matches) && trim($matches[1]) !== '') {
        return go2rtcUnquoteYamlScalar($matches[1]);
    }

    foreach ($entryLines as $line) {
        if (preg_match('/^\s*-\s*(.+?)\s*$/', (string)$line, $matches)) {
            return go2rtcUnquoteYamlScalar($matches[1]);
        }
    }

    return '';
}

function go2rtcQuoteYamlString(string $value): string
{
    return '"' . addcslashes($value, "\\\"") . '"';
}

function go2rtcBuildSourceStreamEntry(string $alias, string $sourceExpression): array
{
    return [
        '  ' . $alias . ':',
        '    - ' . go2rtcQuoteYamlString($sourceExpression),
    ];
}

function go2rtcBuildYoutubeSourceExpression(string $sourceName): string
{
    return 'ffmpeg:' . $sourceName . '#raw=-c:v libx264 -preset veryfast -tune zerolatency -pix_fmt yuv420p -g 20 -keyint_min 20 -sc_threshold 0 -profile:v high -level:v 4.1 -b:v 1400k -maxrate 1400k -bufsize 2800k -c:a aac -ar 48000 -b:a 128k -ac 2';
}

function go2rtcBuildYoutubeStreamEntry(string $alias, string $sourceName, ?string $sourceExpression = null): array
{
    $expression = trim((string)$sourceExpression);
    if ($expression === '') {
        $expression = go2rtcBuildYoutubeSourceExpression($sourceName);
    }

    return go2rtcBuildSourceStreamEntry($alias, $expression);
}

function go2rtcBuildYoutubePublishEntry(string $alias, string $destination): array
{
    return [
        '  ' . $alias . ':',
        '    - ' . go2rtcQuoteYamlString($destination),
    ];
}

function go2rtcBuildMetadataEntry(string $alias, array $payload): array
{
    return [
        '  ' . $alias . ': ' . go2rtcQuoteYamlString(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
    ];
}

function go2rtcDecodeMetadataEntry(array $entryLines): array
{
    $raw = go2rtcExtractEntryPrimaryValue($entryLines);
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function go2rtcBuildPublishStatePayload(string $type, string $destination, bool $enabled = true): array
{
    return [
        'type' => trim($type),
        'destination' => trim($destination),
        'enabled' => $enabled,
    ];
}

function go2rtcInferPublishType(string $alias, array $mosaicMetaEntries, string $fallback = 'youtube'): string
{
    return isset($mosaicMetaEntries[$alias]) ? 'mosaic' : $fallback;
}

function go2rtcResolvePublishState(
    string $alias,
    array $publishEntries,
    array $stateEntries,
    array $mosaicMetaEntries,
    string $fallbackType = 'youtube'
): array {
    $statePayload = go2rtcDecodeMetadataEntry($stateEntries[$alias] ?? []);
    $destination = trim((string)($statePayload['destination'] ?? ''));
    if ($destination === '') {
        $destination = go2rtcExtractEntryPrimaryValue($publishEntries[$alias] ?? []);
    }

    $type = trim((string)($statePayload['type'] ?? ''));
    if ($type === '') {
        $type = go2rtcInferPublishType($alias, $mosaicMetaEntries, $fallbackType);
    }

    $enabled = array_key_exists('enabled', $statePayload)
        ? (bool)$statePayload['enabled']
        : isset($publishEntries[$alias]);

    return [
        'type' => $type,
        'destination' => $destination,
        'enabled' => $enabled,
    ];
}

function go2rtcPersistNamedBlock(array &$document, string $blockName, array $entries, array $orphan = []): void
{
    if (!in_array($blockName, $document['order'], true)) {
        $document['order'][] = $blockName;
    }

    $document['blocks'][$blockName] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock($blockName, $entries, $orphan), "\n"));
}

function go2rtcGetConfiguredStreamEntries(string $configText): array
{
    $document = go2rtcParseTopLevelBlocks($configText);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $entries = [];

    foreach ($streamsBlock['entries'] as $alias => $entryLines) {
        $entries[$alias] = [
            'alias' => $alias,
            'source_expression' => go2rtcExtractEntryPrimaryValue($entryLines),
            'entry_lines' => $entryLines,
        ];
    }

    return $entries;
}

function go2rtcUpdateEntryPrimaryValue(array $entryLines, string $alias, string $value): array
{
    return go2rtcBuildSourceStreamEntry($alias, $value);
}

function go2rtcIsYoutubeStreamExpression(string $sourceExpression): bool
{
    return stripos($sourceExpression, 'ffmpeg:') === 0;
}

function go2rtcSourceExpressionReferencesAlias(string $sourceExpression, string $sourceAlias): bool
{
    $sourceExpression = trim($sourceExpression);
    $sourceAlias = trim($sourceAlias);
    if ($sourceExpression === '' || $sourceAlias === '') {
        return false;
    }

    return preg_match('/^ffmpeg:' . preg_quote($sourceAlias, '/') . '(?=#|$)/i', $sourceExpression) === 1;
}

function go2rtcReplaceReferencedAlias(string $sourceExpression, string $oldAlias, string $newAlias): string
{
    return (string)preg_replace(
        '/^ffmpeg:' . preg_quote($oldAlias, '/') . '(?=#|$)/i',
        'ffmpeg:' . $newAlias,
        $sourceExpression,
        1
    );
}

function go2rtcGetYoutubeRestreamsFromConfig(string $configText): array
{
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $mosaicMetaBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_mosaic'] ?? []);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? []);
    $restreams = [];
    $aliases = array_values(array_unique(array_merge(
        array_keys($publishBlock['entries']),
        array_keys($publishStateBlock['entries'])
    )));

    foreach ($aliases as $alias) {
        $state = go2rtcResolvePublishState(
            $alias,
            $publishBlock['entries'],
            $publishStateBlock['entries'],
            $mosaicMetaBlock['entries'],
            'youtube'
        );
        if (($state['type'] ?? 'youtube') !== 'youtube') {
            continue;
        }

        $destination = trim((string)($state['destination'] ?? ''));
        if ($destination === '' || stripos($destination, 'youtube') === false) {
            continue;
        }

        $sourceExpression = go2rtcExtractEntryPrimaryValue($streamsBlock['entries'][$alias] ?? []);
        $sourceName = '';
        if (preg_match('/^ffmpeg:([^#]+)/', $sourceExpression, $matches)) {
            $sourceName = trim((string)$matches[1]);
        }

        $restreams[] = [
            'alias' => $alias,
            'destination' => $destination,
            'source_name' => $sourceName,
            'source_expression' => $sourceExpression,
            'enabled' => (bool)($state['enabled'] ?? isset($publishBlock['entries'][$alias])),
        ];
    }

    usort($restreams, static function (array $left, array $right): int {
        return strcasecmp($left['alias'] ?? '', $right['alias'] ?? '');
    });

    return $restreams;
}

function go2rtcBuildMosaicRelayUrl(string $sourceName): string
{
    return 'rtsp://127.0.0.1:8554/' . rawurlencode($sourceName);
}

function go2rtcBuildMosaicSourceExpression(string $alias, int $layout, array $sources, string $audioMode = 'silent'): string
{
    $layout = (int)$layout;
    if (!in_array($layout, [2, 4], true)) {
        throw new InvalidArgumentException('Only 2-panel and 4-panel layouts are supported');
    }

    $sources = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $sources)));
    $requiredSources = $layout === 2 ? 2 : 4;
    if (count($sources) < $requiredSources) {
        throw new InvalidArgumentException('Not enough source aliases selected for the mosaic layout');
    }

    $sources = array_slice($sources, 0, $requiredSources);
    $audioMode = $audioMode === 'panel1' ? 'panel1' : 'silent';

    $commandParts = [
        'exec:ffmpeg',
        '-hide_banner',
        '-loglevel', 'error',
    ];

    foreach ($sources as $sourceName) {
        $commandParts[] = '-re';
        $commandParts[] = '-rtsp_transport';
        $commandParts[] = 'tcp';
        $commandParts[] = '-i';
        $commandParts[] = go2rtcBuildMosaicRelayUrl($sourceName);
    }

    $silentInputIndex = null;
    if ($audioMode === 'silent') {
        $silentInputIndex = count($sources);
        $commandParts[] = '-f';
        $commandParts[] = 'lavfi';
        $commandParts[] = '-i';
        $commandParts[] = '"anullsrc=channel_layout=stereo:sample_rate=48000"';
    }

    $filterSegments = [];
    if ($layout === 2) {
        $panelWidth = 320;
        $panelHeight = 360;
        $outputBitrate = '800k';
        $outputBufsize = '1600k';
        $layoutExpression = '0_0|320_0';
    } else {
        $panelWidth = 320;
        $panelHeight = 180;
        $outputBitrate = '750k';
        $outputBufsize = '1500k';
        $layoutExpression = '0_0|320_0|0_180|320_180';
    }

    foreach ($sources as $index => $sourceName) {
        $filterSegments[] = sprintf(
            '[%1$d:v]fps=10,scale=%2$d:%3$d:force_original_aspect_ratio=decrease,pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2:black[v%1$d]',
            $index,
            $panelWidth,
            $panelHeight
        );
    }

    $stackInputs = '';
    for ($index = 0; $index < count($sources); $index++) {
        $stackInputs .= '[v' . $index . ']';
    }
    $filterSegments[] = $stackInputs . 'xstack=inputs=' . count($sources) . ':layout=' . $layoutExpression . '[vout]';

    $commandParts[] = '-filter_complex';
    $commandParts[] = '"' . implode(';', $filterSegments) . '"';
    $commandParts[] = '-map';
    $commandParts[] = '[vout]';
    $commandParts[] = '-map';
    $commandParts[] = $audioMode === 'silent' ? ($silentInputIndex . ':a') : '0:a?';
    $commandParts[] = '-c:v';
    $commandParts[] = 'libx264';
    $commandParts[] = '-preset';
    $commandParts[] = 'veryfast';
    $commandParts[] = '-tune';
    $commandParts[] = 'zerolatency';
    $commandParts[] = '-pix_fmt';
    $commandParts[] = 'yuv420p';
    $commandParts[] = '-r';
    $commandParts[] = '10';
    $commandParts[] = '-g';
    $commandParts[] = '20';
    $commandParts[] = '-keyint_min';
    $commandParts[] = '20';
    $commandParts[] = '-sc_threshold';
    $commandParts[] = '0';
    $commandParts[] = '-b:v';
    $commandParts[] = $outputBitrate;
    $commandParts[] = '-maxrate';
    $commandParts[] = $outputBitrate;
    $commandParts[] = '-bufsize';
    $commandParts[] = $outputBufsize;
    $commandParts[] = '-c:a';
    $commandParts[] = 'aac';
    $commandParts[] = '-ar';
    $commandParts[] = '48000';
    $commandParts[] = '-b:a';
    $commandParts[] = '128k';
    $commandParts[] = '-ac';
    $commandParts[] = '2';
    $commandParts[] = '-f';
    $commandParts[] = 'rtsp';
    $commandParts[] = '{output}';

    return implode(' ', $commandParts);
}

function go2rtcGetMosaicRestreamsFromConfig(string $configText): array
{
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $mosaicMetaBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_mosaic'] ?? []);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? []);
    $restreams = [];

    foreach ($mosaicMetaBlock['entries'] as $alias => $entryLines) {
        $meta = go2rtcDecodeMetadataEntry($entryLines);
        if ($meta === []) {
            continue;
        }

        $state = go2rtcResolvePublishState(
            $alias,
            $publishBlock['entries'],
            $publishStateBlock['entries'],
            $mosaicMetaBlock['entries'],
            'mosaic'
        );
        $sourceExpression = go2rtcExtractEntryPrimaryValue($streamsBlock['entries'][$alias] ?? []);

        $restreams[] = [
            'alias' => $alias,
            'destination' => trim((string)($state['destination'] ?? '')),
            'layout' => (int)($meta['layout'] ?? 0),
            'audio_mode' => trim((string)($meta['audio_mode'] ?? 'panel1')),
            'sources' => array_values(array_filter(array_map(static fn($value) => trim((string)$value), is_array($meta['sources'] ?? null) ? $meta['sources'] : []))),
            'source_expression' => $sourceExpression,
            'enabled' => (bool)($state['enabled'] ?? isset($publishBlock['entries'][$alias])),
        ];
    }

    usort($restreams, static function (array $left, array $right): int {
        return strcasecmp($left['alias'] ?? '', $right['alias'] ?? '');
    });

    return $restreams;
}

function go2rtcSaveYoutubeRestream(string $sourceName, string $alias, string $ingestUrl, string $streamKey, ?string $sourceExpression = null, ?array $mikrotikConfig = null): array
{
    $sourceName = trim($sourceName);
    $alias = trim($alias);
    $ingestUrl = rtrim(trim($ingestUrl), '/');
    $streamKey = trim($streamKey);

    if ($sourceName === '') {
        throw new InvalidArgumentException('Source stream is required');
    }

    if ($alias === '') {
        throw new InvalidArgumentException('Publish alias is required');
    }

    if (strcasecmp($alias, $sourceName) === 0) {
        throw new InvalidArgumentException('Publish alias must be different from the source stream name');
    }

    if ($ingestUrl === '') {
        throw new InvalidArgumentException('YouTube ingest URL is required');
    }

    if ($streamKey === '') {
        throw new InvalidArgumentException('YouTube stream key is required');
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? ['publish:']);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? ['streams:']);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? ['mikreman_publish_state:']);
    $destination = $ingestUrl . '/' . ltrim($streamKey, '/');

    $publishBlock['entries'][$alias] = go2rtcBuildYoutubePublishEntry($alias, $destination);
    $streamsBlock['entries'][$alias] = go2rtcBuildYoutubeStreamEntry($alias, $sourceName, $sourceExpression);
    $publishStateBlock['entries'][$alias] = go2rtcBuildMetadataEntry($alias, go2rtcBuildPublishStatePayload('youtube', $destination, true));

    go2rtcPersistNamedBlock($document, 'streams', $streamsBlock['entries'], $streamsBlock['orphan']);
    go2rtcPersistNamedBlock($document, 'publish', $publishBlock['entries'], $publishBlock['orphan']);
    go2rtcPersistNamedBlock($document, 'mikreman_publish_state', $publishStateBlock['entries'], $publishStateBlock['orphan']);

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);

    return [
        'alias' => $alias,
        'destination' => $destination,
        'source_name' => $sourceName,
        'source_expression' => trim((string)$sourceExpression) !== '' ? trim((string)$sourceExpression) : go2rtcBuildYoutubeSourceExpression($sourceName),
        'enabled' => true,
    ];
}

function go2rtcSaveMosaicRestream(string $alias, int $layout, array $sources, string $audioMode, string $ingestUrl, string $streamKey, ?array $mikrotikConfig = null): array
{
    $alias = trim($alias);
    $ingestUrl = rtrim(trim($ingestUrl), '/');
    $streamKey = trim($streamKey);
    $audioMode = trim($audioMode) === 'silent' ? 'silent' : 'panel1';
    $layout = (int)$layout;
    $sources = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $sources)));

    if ($alias === '') {
        throw new InvalidArgumentException('Mosaic alias is required');
    }

    if (!in_array($layout, [2, 4], true)) {
        throw new InvalidArgumentException('Only 2-panel and 4-panel layouts are supported');
    }

    if ($ingestUrl === '') {
        throw new InvalidArgumentException('YouTube ingest URL is required');
    }

    if ($streamKey === '') {
        throw new InvalidArgumentException('YouTube stream key is required');
    }

    $requiredSources = $layout === 2 ? 2 : 4;
    if (count($sources) < $requiredSources) {
        throw new InvalidArgumentException('Please choose enough source aliases for the selected mosaic layout');
    }

    $sources = array_slice($sources, 0, $requiredSources);
    if (count(array_unique(array_map('strtolower', $sources))) !== count($sources)) {
        throw new InvalidArgumentException('Each panel source must be unique');
    }

    foreach ($sources as $sourceName) {
        if (strcasecmp($sourceName, $alias) === 0) {
            throw new InvalidArgumentException('Mosaic alias must be different from every source alias');
        }
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? ['publish:']);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? ['streams:']);
    $mosaicMetaBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_mosaic'] ?? ['mikreman_mosaic:']);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? ['mikreman_publish_state:']);
    $destination = $ingestUrl . '/' . ltrim($streamKey, '/');
    $sourceExpression = go2rtcBuildMosaicSourceExpression($alias, $layout, $sources, $audioMode);

    $publishBlock['entries'][$alias] = go2rtcBuildYoutubePublishEntry($alias, $destination);
    $streamsBlock['entries'][$alias] = go2rtcBuildSourceStreamEntry($alias, $sourceExpression);
    $mosaicMetaBlock['entries'][$alias] = go2rtcBuildMetadataEntry($alias, [
        'layout' => $layout,
        'audio_mode' => $audioMode,
        'sources' => $sources,
    ]);
    $publishStateBlock['entries'][$alias] = go2rtcBuildMetadataEntry($alias, go2rtcBuildPublishStatePayload('mosaic', $destination, true));

    go2rtcPersistNamedBlock($document, 'streams', $streamsBlock['entries'], $streamsBlock['orphan']);
    go2rtcPersistNamedBlock($document, 'publish', $publishBlock['entries'], $publishBlock['orphan']);
    go2rtcPersistNamedBlock($document, 'mikreman_mosaic', $mosaicMetaBlock['entries'], $mosaicMetaBlock['orphan']);
    go2rtcPersistNamedBlock($document, 'mikreman_publish_state', $publishStateBlock['entries'], $publishStateBlock['orphan']);

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);

    return [
        'alias' => $alias,
        'destination' => $destination,
        'layout' => $layout,
        'audio_mode' => $audioMode,
        'sources' => $sources,
        'source_expression' => $sourceExpression,
        'enabled' => true,
    ];
}

function go2rtcMapStreamRow(string $name, array $streamInfo, array $connection): array
{
    $producers = is_array($streamInfo['producers'] ?? null) ? $streamInfo['producers'] : [];
    $consumers = is_array($streamInfo['consumers'] ?? null) ? $streamInfo['consumers'] : [];
    $sourceUrl = '';

    foreach ($producers as $producer) {
        $candidateUrl = trim((string)($producer['url'] ?? ''));
        if ($candidateUrl !== '') {
            $sourceUrl = $candidateUrl;
            break;
        }
    }

    $rxBytes = sumCctvUsageBytes($producers, 'bytes_recv');
    $txBytes = sumCctvUsageBytes($consumers, 'bytes_send');

    return [
        'name' => $name,
        'source_url' => $sourceUrl,
        'relay_url' => 'rtsp://' . $connection['rtsp_host'] . ':' . $connection['rtsp_port'] . '/' . $name,
        'producer_count' => count($producers),
        'consumer_count' => count($consumers),
        'rx_bytes_total' => $rxBytes,
        'tx_bytes_total' => $txBytes,
        'online' => count($producers) > 0,
        'summary' => $streamInfo,
    ];
}

function go2rtcMapConfiguredStreamRow(string $name, string $sourceExpression, ?array $runtimeInfo, array $connection): array
{
    $runtimeInfo = is_array($runtimeInfo) ? $runtimeInfo : [];
    $baseRow = go2rtcMapStreamRow($name, $runtimeInfo, $connection);
    $baseRow['source_url'] = $sourceExpression !== '' ? $sourceExpression : $baseRow['source_url'];
    $baseRow['online'] = !empty($runtimeInfo) ? $baseRow['online'] : false;

    return $baseRow;
}

function go2rtcGetOverview(?array $mikrotikConfig = null): array
{
    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $streamsResponse = go2rtcRequest('/api/streams', ['base_urls' => $connection['candidates']]);
    $configResponse = go2rtcRequest('/api/config', ['base_urls' => $connection['candidates']]);
    $serviceInfoResponse = go2rtcRequest('/api', ['base_urls' => $connection['candidates']]);

    $streamsRaw = go2rtcDecodeJsonResponse($streamsResponse);
    $configuredStreams = go2rtcGetConfiguredStreamEntries((string)($configResponse['body'] ?? ''));
    $streams = [];

    foreach ($configuredStreams as $name => $streamConfig) {
        $runtimeInfo = $streamsRaw[$name] ?? null;
        $streams[] = go2rtcMapConfiguredStreamRow(
            $name,
            (string)($streamConfig['source_expression'] ?? ''),
            is_array($runtimeInfo) ? $runtimeInfo : null,
            $connection
        );
    }

    foreach ($streamsRaw as $name => $streamInfo) {
        if (!is_string($name) || trim($name) === '' || !is_array($streamInfo)) {
            continue;
        }

        if (isset($configuredStreams[$name])) {
            continue;
        }

        $streams[] = go2rtcMapStreamRow($name, $streamInfo, $connection);
    }

    usort($streams, static function (array $left, array $right): int {
        return strcasecmp($left['name'] ?? '', $right['name'] ?? '');
    });

    $serviceInfo = go2rtcDecodeJsonResponse($serviceInfoResponse);
    $youtubeRestreams = go2rtcGetYoutubeRestreamsFromConfig((string)($configResponse['body'] ?? ''));
    $mosaicRestreams = go2rtcGetMosaicRestreamsFromConfig((string)($configResponse['body'] ?? ''));
    $usageState = updateCctvUsageMetrics($streams, $youtubeRestreams);

    return [
        'online' => !empty($streamsResponse['ok']) || !empty($configResponse['ok']) || !empty($serviceInfoResponse['ok']),
        'details' => [
            'api_target' => $connection['api_target'],
            'api_probe' => $streamsResponse['base_url'] ?: ($configResponse['base_url'] ?? ''),
            'web_url' => $connection['web_url'],
            'rtsp_host' => $connection['rtsp_host'],
            'rtsp_port' => $connection['rtsp_port'],
            'rtsp_url_template' => $connection['rtsp_url_template'],
            'webrtc_url' => $connection['webrtc_url'],
            'endpoint_mode' => $connection['endpoint_mode'],
            'version' => trim((string)($serviceInfo['version'] ?? '')),
            'config_path' => trim((string)($serviceInfo['config_path'] ?? '')),
        ],
        'stream_count' => count($usageState['streams'] ?? $streams),
        'streams' => $usageState['streams'] ?? $streams,
        'config_text' => (string)($configResponse['body'] ?? ''),
        'youtube_restreams' => $youtubeRestreams,
        'mosaic_restreams' => $mosaicRestreams,
        'usage_summary' => $usageState['summary'] ?? [],
        'error' => $streamsResponse['error'] ?? ($configResponse['error'] ?? ''),
    ];
}

function go2rtcGetStream(string $name, ?array $mikrotikConfig = null): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $overview = go2rtcGetOverview($mikrotikConfig);
    foreach ($overview['streams'] ?? [] as $stream) {
        if (trim((string)($stream['name'] ?? '')) === $name) {
            return $stream;
        }
    }

    return null;
}

function go2rtcSaveStream(string $name, string $src, ?string $oldName = null, ?array $mikrotikConfig = null): array
{
    $name = trim($name);
    $src = trim($src);
    $oldName = trim((string)$oldName);

    if ($name === '') {
        throw new InvalidArgumentException('Stream name is required');
    }

    if ($src === '') {
        throw new InvalidArgumentException('Stream source URL is required');
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? ['streams:']);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);

    $warning = '';
    $streamsBlock['entries'][$name] = go2rtcBuildSourceStreamEntry($name, $src);

    if ($oldName !== '' && $oldName !== $name) {
        unset($streamsBlock['entries'][$oldName]);

        foreach ($streamsBlock['entries'] as $alias => $entryLines) {
            if (!isset($publishBlock['entries'][$alias])) {
                continue;
            }

            $sourceExpression = go2rtcExtractEntryPrimaryValue($entryLines);
            if (!go2rtcSourceExpressionReferencesAlias($sourceExpression, $oldName)) {
                continue;
            }

            $streamsBlock['entries'][$alias] = go2rtcUpdateEntryPrimaryValue(
                $entryLines,
                $alias,
                go2rtcReplaceReferencedAlias($sourceExpression, $oldName, $name)
            );
        }
    }

    if (!in_array('streams', $document['order'], true)) {
        $document['order'][] = 'streams';
    }

    $document['blocks']['streams'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('streams', $streamsBlock['entries'], $streamsBlock['orphan']), "\n"));
    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);

    $stream = go2rtcGetStream($name, $mikrotikConfig);
    if ($stream === null) {
        $connection = go2rtcGetConnectionDetails($mikrotikConfig);
        $stream = [
            'name' => $name,
            'source_url' => $src,
            'relay_url' => 'rtsp://' . $connection['rtsp_host'] . ':' . $connection['rtsp_port'] . '/' . $name,
            'producer_count' => 0,
            'consumer_count' => 0,
            'online' => false,
            'summary' => [],
        ];
    }

    $stream['warning'] = $warning;
    return $stream;
}

function go2rtcDeleteStream(string $name, ?array $mikrotikConfig = null): array
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Stream name is required');
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);
    $mosaicMetaBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_mosaic'] ?? []);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? []);

    $removedSource = false;
    $removedPublishAliases = [];

    if (isset($streamsBlock['entries'][$name])) {
        unset($streamsBlock['entries'][$name]);
        $removedSource = true;
    }

    foreach (array_keys($mosaicMetaBlock['entries']) as $alias) {
        $metaRaw = go2rtcExtractEntryPrimaryValue($mosaicMetaBlock['entries'][$alias] ?? []);
        $meta = json_decode($metaRaw, true);
        $metaSources = array_values(array_filter(array_map(static fn($value) => trim((string)$value), is_array($meta['sources'] ?? null) ? $meta['sources'] : [])));

        if (!in_array($name, $metaSources, true)) {
            continue;
        }

        unset($mosaicMetaBlock['entries'][$alias]);
        unset($streamsBlock['entries'][$alias]);
        if (isset($publishBlock['entries'][$alias])) {
            unset($publishBlock['entries'][$alias]);
            $removedPublishAliases[] = $alias;
        }
        unset($publishStateBlock['entries'][$alias]);
        $removedSource = true;
    }

    foreach (array_keys($streamsBlock['entries']) as $alias) {
        $sourceExpression = go2rtcExtractEntryPrimaryValue($streamsBlock['entries'][$alias] ?? []);
        if (!go2rtcSourceExpressionReferencesAlias($sourceExpression, $name)) {
            continue;
        }

        unset($streamsBlock['entries'][$alias]);
        if (isset($publishBlock['entries'][$alias])) {
            unset($publishBlock['entries'][$alias]);
            $removedPublishAliases[] = $alias;
        }
        unset($publishStateBlock['entries'][$alias]);
        $removedSource = true;
    }

    if (!$removedSource) {
        throw new RuntimeException('Source alias not found');
    }

    if (isset($document['blocks']['streams'])) {
        $document['blocks']['streams'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('streams', $streamsBlock['entries'], $streamsBlock['orphan']), "\n"));
    }

    if (isset($document['blocks']['publish'])) {
        $document['blocks']['publish'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('publish', $publishBlock['entries'], $publishBlock['orphan']), "\n"));
    }

    if (isset($document['blocks']['mikreman_mosaic'])) {
        $document['blocks']['mikreman_mosaic'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('mikreman_mosaic', $mosaicMetaBlock['entries'], $mosaicMetaBlock['orphan']), "\n"));
    }
    if (isset($document['blocks']['mikreman_publish_state'])) {
        $document['blocks']['mikreman_publish_state'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('mikreman_publish_state', $publishStateBlock['entries'], $publishStateBlock['orphan']), "\n"));
    }

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);

    return [
        'name' => $name,
        'removed_publish_aliases' => array_values(array_unique($removedPublishAliases)),
    ];
}

function go2rtcDeleteYoutubeRestream(string $alias, ?array $mikrotikConfig = null): void
{
    $alias = trim($alias);
    if ($alias === '') {
        throw new InvalidArgumentException('Publish alias is required');
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? []);

    $removed = false;
    if (isset($publishBlock['entries'][$alias])) {
        unset($publishBlock['entries'][$alias]);
        $removed = true;
    }
    if (isset($streamsBlock['entries'][$alias])) {
        unset($streamsBlock['entries'][$alias]);
        $removed = true;
    }
    if (isset($publishStateBlock['entries'][$alias])) {
        unset($publishStateBlock['entries'][$alias]);
        $removed = true;
    }

    if (!$removed) {
        throw new RuntimeException('YouTube publish alias not found');
    }

    if (isset($document['blocks']['publish'])) {
        $document['blocks']['publish'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('publish', $publishBlock['entries'], $publishBlock['orphan']), "\n"));
    }

    if (isset($document['blocks']['streams'])) {
        $document['blocks']['streams'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('streams', $streamsBlock['entries'], $streamsBlock['orphan']), "\n"));
    }
    if (isset($document['blocks']['mikreman_publish_state'])) {
        $document['blocks']['mikreman_publish_state'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('mikreman_publish_state', $publishStateBlock['entries'], $publishStateBlock['orphan']), "\n"));
    }

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);
}

function go2rtcDeleteMosaicRestream(string $alias, ?array $mikrotikConfig = null): void
{
    $alias = trim($alias);
    if ($alias === '') {
        throw new InvalidArgumentException('Mosaic alias is required');
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $mosaicMetaBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_mosaic'] ?? []);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? []);

    $removed = false;
    if (isset($publishBlock['entries'][$alias])) {
        unset($publishBlock['entries'][$alias]);
        $removed = true;
    }
    if (isset($streamsBlock['entries'][$alias])) {
        unset($streamsBlock['entries'][$alias]);
        $removed = true;
    }
    if (isset($mosaicMetaBlock['entries'][$alias])) {
        unset($mosaicMetaBlock['entries'][$alias]);
        $removed = true;
    }
    if (isset($publishStateBlock['entries'][$alias])) {
        unset($publishStateBlock['entries'][$alias]);
        $removed = true;
    }

    if (!$removed) {
        throw new RuntimeException('Mosaic output not found');
    }

    if (isset($document['blocks']['publish'])) {
        $document['blocks']['publish'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('publish', $publishBlock['entries'], $publishBlock['orphan']), "\n"));
    }
    if (isset($document['blocks']['streams'])) {
        $document['blocks']['streams'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('streams', $streamsBlock['entries'], $streamsBlock['orphan']), "\n"));
    }
    if (isset($document['blocks']['mikreman_mosaic'])) {
        $document['blocks']['mikreman_mosaic'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('mikreman_mosaic', $mosaicMetaBlock['entries'], $mosaicMetaBlock['orphan']), "\n"));
    }
    if (isset($document['blocks']['mikreman_publish_state'])) {
        $document['blocks']['mikreman_publish_state'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('mikreman_publish_state', $publishStateBlock['entries'], $publishStateBlock['orphan']), "\n"));
    }

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);
}

function go2rtcSetPublishEnabled(string $alias, bool $enabled, ?array $mikrotikConfig = null): array
{
    $alias = trim($alias);
    if ($alias === '') {
        throw new InvalidArgumentException('Publish alias is required');
    }

    $configText = go2rtcGetConfigText($mikrotikConfig);
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? ['publish:']);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $mosaicMetaBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_mosaic'] ?? []);
    $publishStateBlock = go2rtcParseNamedEntriesBlock($document['blocks']['mikreman_publish_state'] ?? ['mikreman_publish_state:']);

    if (!isset($streamsBlock['entries'][$alias])) {
        throw new RuntimeException('Publish alias not found');
    }

    $state = go2rtcResolvePublishState(
        $alias,
        $publishBlock['entries'],
        $publishStateBlock['entries'],
        $mosaicMetaBlock['entries'],
        'youtube'
    );

    $destination = trim((string)($state['destination'] ?? ''));
    if ($destination === '') {
        throw new RuntimeException('Stored publish destination is missing');
    }

    if ($enabled) {
        $publishBlock['entries'][$alias] = go2rtcBuildYoutubePublishEntry($alias, $destination);
    } else {
        unset($publishBlock['entries'][$alias]);
    }

    $publishStateBlock['entries'][$alias] = go2rtcBuildMetadataEntry($alias, go2rtcBuildPublishStatePayload(
        (string)($state['type'] ?? go2rtcInferPublishType($alias, $mosaicMetaBlock['entries'])),
        $destination,
        $enabled
    ));

    go2rtcPersistNamedBlock($document, 'publish', $publishBlock['entries'], $publishBlock['orphan']);
    go2rtcPersistNamedBlock($document, 'mikreman_publish_state', $publishStateBlock['entries'], $publishStateBlock['orphan']);
    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);

    return [
        'alias' => $alias,
        'destination' => $destination,
        'enabled' => $enabled,
        'type' => (string)($state['type'] ?? go2rtcInferPublishType($alias, $mosaicMetaBlock['entries'])),
    ];
}
