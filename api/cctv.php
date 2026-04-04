<?php
header('Content-Type: application/json');
require_once '../includes/session.php';
startSecureSession();

require_once '../includes/auth.php';
require_once '../includes/config.php';
require_once '../includes/locks.php';
require_once '../includes/go2rtc.php';

requireAuth();

if (!validateCctvCsrfTokenRequest()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid CSRF token'
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

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
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfToken = $_SESSION['csrf_token'] ?? '';

    return !empty($csrfToken) && hash_equals($csrfToken, $csrfHeader);
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

        default:
            throw new Exception('Invalid action');
    }
}
