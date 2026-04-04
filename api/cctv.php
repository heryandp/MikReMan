<?php
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/locks.php';
require_once '../includes/go2rtc.php';

requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isPreviewRequest = $method === 'GET' && $action === 'preview_mjpeg';

if ($isPreviewRequest) {
    try {
        if (!validateCctvCsrfTokenRequest()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Invalid CSRF token';
            exit;
        }

        $name = trim((string)($_GET['name'] ?? ''));
        if ($name === '') {
            throw new Exception('Stream name is required');
        }

        go2rtcProxyMjpegStream($name, getConfig('mikrotik') ?: []);
        exit;
    } catch (Throwable $e) {
        if (!headers_sent()) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Preview unavailable: ' . $e->getMessage();
        exit;
    }
}

header('Content-Type: application/json');

if (!validateCctvCsrfTokenRequest()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid CSRF token'
    ]);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            handleCctvGetRequest($action);
            break;

        case 'POST':
            handleCctvPostRequest();
            break;

        default:
            throw new Exception('Method not allowed');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function validateCctvCsrfTokenRequest(): bool
{
    $csrfHeader = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $csrfQuery = trim((string)($_GET['csrf_token'] ?? $_POST['csrf_token'] ?? ''));
    $csrfToken = $_SESSION['csrf_token'] ?? '';

    if (empty($csrfToken)) {
        return false;
    }

    if ($csrfHeader !== '' && hash_equals($csrfToken, $csrfHeader)) {
        return true;
    }

    return $csrfQuery !== '' && hash_equals($csrfToken, $csrfQuery);
}

function handleCctvGetRequest(string $action): void
{
    switch ($action) {
        case 'overview':
            $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);
            echo json_encode([
                'success' => true,
                'data' => $overview
            ]);
            return;

        case 'get_stream':
            $name = trim((string)($_GET['name'] ?? ''));
            if ($name === '') {
                throw new Exception('Stream name is required');
            }

            $stream = go2rtcGetStream($name, getConfig('mikrotik') ?: []);
            if ($stream === null) {
                throw new Exception('Stream not found');
            }

            echo json_encode([
                'success' => true,
                'data' => $stream
            ]);
            return;

        default:
            throw new Exception('Invalid action');
    }
}

function handleCctvPostRequest(): void
{
    $input = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input');
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'save_stream':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $stream = go2rtcSaveStream(
                    (string)($input['name'] ?? ''),
                    (string)($input['src'] ?? ''),
                    (string)($input['old_name'] ?? ''),
                    getConfig('mikrotik') ?: []
                );
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'Stream saved successfully',
                    'data' => $stream,
                    'overview' => $overview
                ]);
            }, 15);
            return;

        case 'delete_stream':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $name = (string)($input['name'] ?? '');
                $result = go2rtcDeleteStream($name, getConfig('mikrotik') ?: []);
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                $message = 'Stream deleted successfully';
                $removedPublishAliases = is_array($result['removed_publish_aliases'] ?? null) ? $result['removed_publish_aliases'] : [];
                if (!empty($removedPublishAliases)) {
                    $message = 'Source alias deleted and dependent YouTube publish aliases were removed';
                }

                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'data' => $result,
                    'overview' => $overview
                ]);
            }, 15);
            return;

        case 'save_youtube_restream':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $restream = go2rtcSaveYoutubeRestream(
                    (string)($input['source_name'] ?? ''),
                    (string)($input['alias'] ?? ''),
                    (string)($input['ingest_url'] ?? ''),
                    (string)($input['stream_key'] ?? ''),
                    (string)($input['source_expression'] ?? ''),
                    getConfig('mikrotik') ?: []
                );
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'YouTube restream saved successfully',
                    'data' => $restream,
                    'overview' => $overview
                ]);
            }, 20);
            return;

        case 'delete_youtube_restream':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $alias = (string)($input['alias'] ?? '');
                go2rtcDeleteYoutubeRestream($alias, getConfig('mikrotik') ?: []);
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'YouTube restream removed successfully',
                    'overview' => $overview
                ]);
            }, 20);
            return;

        case 'pause_publish':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $alias = (string)($input['alias'] ?? '');
                $result = go2rtcSetPublishEnabled($alias, false, getConfig('mikrotik') ?: []);
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'Publish output paused successfully',
                    'data' => $result,
                    'overview' => $overview
                ]);
            }, 20);
            return;

        case 'resume_publish':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $alias = (string)($input['alias'] ?? '');
                $result = go2rtcSetPublishEnabled($alias, true, getConfig('mikrotik') ?: []);
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'Publish output resumed successfully',
                    'data' => $result,
                    'overview' => $overview
                ]);
            }, 20);
            return;

        case 'save_mosaic_restream':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $restream = go2rtcSaveMosaicRestream(
                    (string)($input['alias'] ?? ''),
                    (int)($input['layout'] ?? 0),
                    is_array($input['sources'] ?? null) ? $input['sources'] : [],
                    (string)($input['audio_mode'] ?? 'silent'),
                    (string)($input['ingest_url'] ?? ''),
                    (string)($input['stream_key'] ?? ''),
                    getConfig('mikrotik') ?: []
                );
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'Mosaic restream saved successfully',
                    'data' => $restream,
                    'overview' => $overview
                ]);
            }, 30);
            return;

        case 'delete_mosaic_restream':
            withAppLock('go2rtc-stream-mutation', function () use ($input) {
                $alias = (string)($input['alias'] ?? '');
                go2rtcDeleteMosaicRestream($alias, getConfig('mikrotik') ?: []);
                $overview = go2rtcGetOverview(getConfig('mikrotik') ?: []);

                echo json_encode([
                    'success' => true,
                    'message' => 'Mosaic restream removed successfully',
                    'overview' => $overview
                ]);
            }, 30);
            return;

        default:
            throw new Exception('Invalid action');
    }
}
