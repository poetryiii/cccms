<?php

declare(strict_types=1);

/**
 * Common messages (backend).
 *
 * Key convention: `{namespace}.{key}`, where the namespace maps to a file in this directory.
 * Missing keys fall back to zh-CN; if both locales lack the key, the key itself is returned
 * and a warning is logged.
 */
return [
    'not_logged_in'          => 'Not signed in',
    'session_expired'        => 'Your session has expired, please sign in again',
    'invalid_credentials'    => 'Invalid credentials or the user no longer exists',
    'unauthorized'           => 'Not signed in or the session has expired',
    'no_permission'          => 'Permission denied: {slug}',
    'permission_not_declared' => 'Endpoint does not declare a permission: {target}',
];
