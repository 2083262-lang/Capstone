<?php
/**
 * Path Helper — HomeEstate Realty
 * 
 * Detects the project root automatically so asset links never break
 * regardless of which portal folder the page lives in.
 *
 * Usage in any PHP file:
 *   require_once __DIR__ . '/../config/paths.php';   // from a subfolder
 *   require_once __DIR__ . '/config/paths.php';       // from root
 *   
 *   Then in HTML:
 *   <link href="<?= ASSETS_CSS ?>bootstrap.min.css" rel="stylesheet">
 */

if (!defined('BASE_URL')) {
    // Auto-detect the base URL of the project
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Determine project root from this file's location (config/ directory)
    $docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $projectDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

    $basePath = str_replace($docRoot, '', $projectDir);
    $basePath = '/' . trim($basePath, '/') . '/';

    define('BASE_URL',     $protocol . '://' . $host . $basePath);
    define('ASSETS_CSS',   BASE_URL . 'assets/css/');
    define('ASSETS_JS',    BASE_URL . 'assets/js/');
    define('ASSETS_FONTS', BASE_URL . 'assets/fonts/');
}
