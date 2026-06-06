<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// Shared bootstrap for all pages.
// - Starts a session (used for login + CSRF)
// - Loads helper + auth utilities
if (session_status() === PHP_SESSION_NONE) {
	$secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

	// Must be called before session_start().
	if (PHP_VERSION_ID >= 70300) {
		session_set_cookie_params([
			'lifetime' => 0,
			'path' => '/',
			'secure' => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
	}

	session_start();
}
