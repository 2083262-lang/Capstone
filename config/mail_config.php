<?php
// Mail configuration (keep this out of version control if possible)
// Lock to STARTTLS on port 587

define('MAIL_SMTP_HOST',      'smtp.gmail.com');
define('MAIL_SMTP_PORT',      587);
define('MAIL_SMTP_SECURE',    'tls');

define('MAIL_SMTP_USERNAME',  'real.estate.system.noreply@gmail.com');
// Gmail App Password (no spaces)
define('MAIL_SMTP_PASSWORD',  'bcbhsvmxwkxmlqyn');

define('MAIL_FROM_EMAIL',     'real.estate.system.noreply@gmail.com');
define('MAIL_FROM_NAME',      'Real Estate System');

// Production defaults
// Disable verbose SMTP debug to clients; optional server-side log path
// TEMP: enable debug logging to logs/mail.log while we diagnose delivery
define('MAIL_DEBUG_ENABLED',  true);
define('MAIL_LOG_FILE',       __DIR__ . '/../logs/mail.log');
