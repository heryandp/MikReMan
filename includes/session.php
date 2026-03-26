<?php

function isSecureRequest() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    return false;
}

function startSecureSession() {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', isSecureRequest() ? '1' : '0');
    ini_set('session.use_strict_mode', '1');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}
?>
