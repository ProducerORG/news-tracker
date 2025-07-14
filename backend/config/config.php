<?php
$dotenv = parse_ini_file(__DIR__ . '/.env');

define('DB_HOST', $dotenv['DB_HOST']);
define('DB_NAME', $dotenv['DB_NAME']);
define('DB_USER', $dotenv['DB_USER']);
define('DB_PASS', $dotenv['DB_PASS']);

define('SMTP_HOST', $dotenv['SMTP_HOST']);
define('SMTP_PORT', $dotenv['SMTP_PORT']);
define('SMTP_USER', $dotenv['SMTP_USER']);
define('SMTP_PASS', $dotenv['SMTP_PASS']);
define('SMTP_FROM', $dotenv['SMTP_FROM']);
define('SMTP_TO', $dotenv['SMTP_TO']);