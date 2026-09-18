<?php

declare(strict_types=1);

/** Authentication messages. */
return [
    'missing_credentials' => 'Please enter your username and password',
    'captcha_invalid'     => 'The captcha is incorrect or has expired',
    'captcha_service_down' => 'Captcha service is unavailable, please contact the administrator',
    'bad_credentials'     => 'Incorrect username or password',
    'attempt_tip'         => ', {count} attempts remaining',
    'account_disabled'    => 'This account has been disabled',
    'role_abnormal'       => 'Account role is abnormal, please contact the administrator',
    'too_many_attempts'   => 'Too many failed sign-in attempts, please try again in {minutes} minute(s)',
    'login_success'       => 'Signed in successfully',
    'maintenance'         => 'The system is under maintenance, please try again later',
];
