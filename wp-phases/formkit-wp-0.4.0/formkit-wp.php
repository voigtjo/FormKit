<?php
/**
 * Plugin Name: FormKit (WP) – 0.4.0
 * Description: Modularer MDE-Formular-/E-Mail-/PDF-Renderer. Shortcodes + REST + DOI + Admin-UI.
 * Version: 0.4.0
 * Author: FormKit
 * Requires PHP: 8.0
 * Requires at least: 6.4
 */
if (!defined('ABSPATH')) exit;

define('FORMKIT_WP_DIR', plugin_dir_path(__FILE__));
define('FORMKIT_WP_URL', plugin_dir_url(__FILE__));


// Autoloader (präzises PSR-4-Mapping auf unsere drei Wurzeln)
spl_autoload_register(function($class){
    $prefix = 'FormKit\\';
    if (strpos($class, $prefix) !== 0) return;

    // z.B. "Core\Support\Container"
    $rel = substr($class, strlen($prefix));
    [$top, $rest] = array_pad(explode('\\', $rel, 2), 2, '');

    // Namespace-Root -> Verzeichnis
    $map = [
        'App'       => 'includes/app',
        'Core'      => 'includes/core',
        'Renderers' => 'includes/renderers',
    ];

    if (!isset($map[$top])) return;

    $path = FORMKIT_WP_DIR . $map[$top] . '/' . str_replace('\\','/',$rest) . '.php';
    if (is_file($path)) {
        require $path;
        return;
    }

    // Fallback (falls mal eine Klasse direkt unter dem Root liegt)
    $fallback = FORMKIT_WP_DIR . $map[$top] . '/' . str_replace('\\','/',$rel) . '.php';
    if (is_file($fallback)) require $fallback;
});


require_once FORMKIT_WP_DIR . 'includes/app/Bootstrap.php';
// Composer-Autoloader (für Dompdf etc.)
$composerAutoloads = [
    FORMKIT_WP_DIR . 'vendor/autoload.php',           // bevorzugt: im Plugin
    WP_CONTENT_DIR . '/vendor/autoload.php',          // falls global unter wp-content
    ABSPATH . 'vendor/autoload.php',                  // falls Projekt-Root
];
foreach ($composerAutoloads as $autoload) {
    if (is_file($autoload)) { require_once $autoload; break; }
}

FormKit\App\Bootstrap::init();
