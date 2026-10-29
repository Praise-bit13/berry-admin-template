<?php
declare(strict_types=1);

function env_value(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

define('DB_HOST', env_value('FCWM_DB_HOST', '127.0.0.1'));
define('DB_PORT', env_value('FCWM_DB_PORT', '3306'));
define('DB_NAME', env_value('FCWM_DB_NAME', 'fcwm_complaints'));
define('DB_USER', env_value('FCWM_DB_USER', 'root'));
define('DB_PASSWORD', env_value('FCWM_DB_PASSWORD', ''));
define('MAIL_ENABLED', filter_var(env_value('FCWM_MAIL_ENABLED', '1'), FILTER_VALIDATE_BOOL));
define('MAIL_FROM', env_value('FCWM_MAIL_FROM', 'noreply@fcwm.edu.ng'));
define('MAIL_FROM_NAME', env_value('FCWM_MAIL_FROM_NAME', 'FCWM Student Support'));
