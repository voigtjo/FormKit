<?php
/**
 * Plugin Name: FormKit Overrides
 * Description: Empfänger/Betreff für FormKit-Mails überschreiben.
 */

add_filter('formkit_admin_email', function($to, $slug, $data){
    // Einzelner Empfänger:
    // return 'support@deine-domain.tld';

    // Mehrere Empfänger (Komma-separiert):
    return 'admin@joevoi.com';
}, 10, 3);

add_filter('formkit_admin_subject', function($subject, $slug, $data){
    return 'Neue Kontakt-Anfrage von ' . ($data['name'] ?? 'Unbekannt') . " – Formular: {$slug}";
}, 10, 3);

// Optional: Reply-To auf Absender setzen (falls E-Mail-Feld im Formular vorhanden)
add_filter('wp_mail', function($args){
    // Nur FormKit-Mails anfassen (am Betreff erkennen):
    if (isset($args['subject']) && is_string($args['subject']) && strpos($args['subject'], '[FormKit]') !== false) {
        // Versuch aus dem Body die Absender-Mail zu parsen – oder du setzt hier fix $replyTo
        $replyTo = null;
        if (preg_match('/E-Mail:\s*([^\s<>\(\)]+)/i', $args['message'], $m)) {
            $replyTo = $m[1];
        }
        if ($replyTo && is_email($replyTo)) {
            $headers = is_array($args['headers']) ? $args['headers'] : (array) $args['headers'];
            $headers[] = 'Reply-To: ' . $replyTo;
            $args['headers'] = $headers;
        }
    }
    return $args;
});
