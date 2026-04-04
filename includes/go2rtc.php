<?php

require_once __DIR__ . '/config.php';
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
            if ($currentName !== null) {
                $entries[$currentName] = $currentLines;
            }

            $currentName = trim((string)($matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3])));
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

function go2rtcBuildYoutubeStreamEntry(string $alias, string $sourceName): array
{
    return [
        '  ' . $alias . ':',
        '    - ' . go2rtcQuoteYamlString('ffmpeg:' . $sourceName . '#video=h264#audio=aac'),
    ];
}

function go2rtcBuildYoutubePublishEntry(string $alias, string $destination): array
{
    return [
        '  ' . $alias . ':',
        '    - ' . go2rtcQuoteYamlString($destination),
    ];
}

function go2rtcGetYoutubeRestreamsFromConfig(string $configText): array
{
    $document = go2rtcParseTopLevelBlocks($configText);
    $publishBlock = go2rtcParseNamedEntriesBlock($document['blocks']['publish'] ?? []);
    $streamsBlock = go2rtcParseNamedEntriesBlock($document['blocks']['streams'] ?? []);
    $restreams = [];

    foreach ($publishBlock['entries'] as $alias => $entryLines) {
        $destination = go2rtcExtractEntryPrimaryValue($entryLines);
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
        ];
    }

    usort($restreams, static function (array $left, array $right): int {
        return strcasecmp($left['alias'] ?? '', $right['alias'] ?? '');
    });

    return $restreams;
}

function go2rtcSaveYoutubeRestream(string $sourceName, string $alias, string $ingestUrl, string $streamKey, ?array $mikrotikConfig = null): array
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
    $destination = $ingestUrl . '/' . ltrim($streamKey, '/');

    $publishBlock['entries'][$alias] = go2rtcBuildYoutubePublishEntry($alias, $destination);
    $streamsBlock['entries'][$alias] = go2rtcBuildYoutubeStreamEntry($alias, $sourceName);

    if (!in_array('streams', $document['order'], true)) {
        $document['order'][] = 'streams';
    }

    if (!in_array('publish', $document['order'], true)) {
        $document['order'][] = 'publish';
    }

    $document['blocks']['streams'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('streams', $streamsBlock['entries'], $streamsBlock['orphan']), "\n"));
    $document['blocks']['publish'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('publish', $publishBlock['entries'], $publishBlock['orphan']), "\n"));

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);

    return [
        'alias' => $alias,
        'destination' => $destination,
        'source_name' => $sourceName,
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

    unset($publishBlock['entries'][$alias], $streamsBlock['entries'][$alias]);

    if (isset($document['blocks']['publish'])) {
        $document['blocks']['publish'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('publish', $publishBlock['entries'], $publishBlock['orphan']), "\n"));
    }

    if (isset($document['blocks']['streams'])) {
        $document['blocks']['streams'] = explode("\n", rtrim(go2rtcBuildNamedEntriesBlock('streams', $streamsBlock['entries'], $streamsBlock['orphan']), "\n"));
    }

    go2rtcSaveConfigText(go2rtcBuildYamlFromBlocks($document), $mikrotikConfig);
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

    return [
        'name' => $name,
        'source_url' => $sourceUrl,
        'relay_url' => 'rtsp://' . $connection['rtsp_host'] . ':' . $connection['rtsp_port'] . '/' . $name,
        'producer_count' => count($producers),
        'consumer_count' => count($consumers),
        'online' => count($producers) > 0,
        'summary' => $streamInfo,
    ];
}

function go2rtcGetOverview(?array $mikrotikConfig = null): array
{
    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $streamsResponse = go2rtcRequest('/api/streams', ['base_urls' => $connection['candidates']]);
    $configResponse = go2rtcRequest('/api/config', ['base_urls' => $connection['candidates']]);
    $serviceInfoResponse = go2rtcRequest('/api', ['base_urls' => $connection['candidates']]);

    $streamsRaw = go2rtcDecodeJsonResponse($streamsResponse);
    $streams = [];
    foreach ($streamsRaw as $name => $streamInfo) {
        if (!is_string($name) || trim($name) === '' || !is_array($streamInfo)) {
            continue;
        }

        $streams[] = go2rtcMapStreamRow($name, $streamInfo, $connection);
    }

    usort($streams, static function (array $left, array $right): int {
        return strcasecmp($left['name'] ?? '', $right['name'] ?? '');
    });

    $serviceInfo = go2rtcDecodeJsonResponse($serviceInfoResponse);

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
        'stream_count' => count($streams),
        'streams' => $streams,
        'config_text' => (string)($configResponse['body'] ?? ''),
        'youtube_restreams' => go2rtcGetYoutubeRestreamsFromConfig((string)($configResponse['body'] ?? '')),
        'error' => $streamsResponse['error'] ?? ($configResponse['error'] ?? ''),
    ];
}

function go2rtcGetStream(string $name, ?array $mikrotikConfig = null): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $response = go2rtcRequest('/api/streams', [
        'base_urls' => $connection['candidates'],
        'query' => ['src' => $name],
    ]);

    if (empty($response['ok'])) {
        return null;
    }

    $decoded = go2rtcDecodeJsonResponse($response);
    if (empty($decoded)) {
        return null;
    }

    return go2rtcMapStreamRow($name, $decoded, $connection);
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

    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $saveResponse = go2rtcRequest('/api/streams', [
        'method' => 'PUT',
        'base_urls' => $connection['candidates'],
        'query' => [
            'name' => $name,
            'src' => $src,
        ],
    ]);

    if (empty($saveResponse['ok'])) {
        throw new RuntimeException($saveResponse['error'] ?? 'Failed to save stream');
    }

    $warning = '';
    if ($oldName !== '' && $oldName !== $name) {
        $deleteResponse = go2rtcRequest('/api/streams', [
            'method' => 'DELETE',
            'base_urls' => $connection['candidates'],
            'query' => ['src' => $oldName],
        ]);

        if (empty($deleteResponse['ok'])) {
            $warning = 'New stream saved, but the old alias could not be removed.';
        }
    }

    $stream = go2rtcGetStream($name, $mikrotikConfig);
    if ($stream === null) {
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

function go2rtcDeleteStream(string $name, ?array $mikrotikConfig = null): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Stream name is required');
    }

    $connection = go2rtcGetConnectionDetails($mikrotikConfig);
    $response = go2rtcRequest('/api/streams', [
        'method' => 'DELETE',
        'base_urls' => $connection['candidates'],
        'query' => ['src' => $name],
    ]);

    if (empty($response['ok'])) {
        throw new RuntimeException($response['error'] ?? 'Failed to delete stream');
    }
}
