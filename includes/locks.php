<?php

function getAppLockDirectory(): string
{
    $directory = dirname(__DIR__) . '/runtime/locks';

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create lock directory: ' . $directory);
    }

    return $directory;
}

function buildAppLockPath(string $name): string
{
    $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'app-lock';
    return getAppLockDirectory() . '/' . $safe_name . '.lock';
}

function withAppLock(string $name, callable $callback, int $timeoutSeconds = 15)
{
    $path = buildAppLockPath($name);
    $handle = fopen($path, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Failed to open lock file: ' . $path);
    }

    $locked = false;
    $deadline = microtime(true) + max(1, $timeoutSeconds);

    try {
        do {
            $locked = flock($handle, LOCK_EX | LOCK_NB);
            if ($locked) {
                break;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        if (!$locked) {
            throw new RuntimeException('Resource is busy. Please try again in a moment.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode([
            'pid' => getmypid(),
            'locked_at' => date(DATE_ATOM),
            'name' => $name,
        ], JSON_UNESCAPED_SLASHES));
        fflush($handle);

        return $callback();
    } finally {
        if ($locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}
