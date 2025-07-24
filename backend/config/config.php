<?php
$dotenv = parse_ini_file(__DIR__ . '/.env');

define('SUPABASE_URL', $dotenv['SUPABASE_URL']);
define('SUPABASE_KEY', $dotenv['SUPABASE_KEY']);

define('SMTP_HOST', $dotenv['SMTP_HOST']);
define('SMTP_PORT', $dotenv['SMTP_PORT']);
define('SMTP_USER', $dotenv['SMTP_USER']);
define('SMTP_PASS', $dotenv['SMTP_PASS']);
define('SMTP_FROM', $dotenv['SMTP_FROM']);
define('SMTP_TO', $dotenv['SMTP_TO']);
define('GPT_KEY', $dotenv['GPT_KEY']);