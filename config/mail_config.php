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
define('MAIL_FROM_NAME',      'HomeEstate Realty');

// Production defaults
// Set MAIL_DEBUG_ENABLED to true ONLY when actively diagnosing delivery issues.
// In normal operation keep it false to avoid performance overhead and log noise.
define('MAIL_DEBUG_ENABLED',  false);
define('MAIL_LOG_FILE',       __DIR__ . '/../logs/mail.log');
