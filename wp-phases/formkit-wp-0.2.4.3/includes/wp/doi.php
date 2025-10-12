<?php
if (!defined('ABSPATH')) exit;

function formkit_send_doi(string $slug, array $data): void {
    if (empty($data['email']) || !is_email($data['email'])) {
        error_log('FormKit DOI: keine gültige Absenderadresse.');
        return;
    }
    $to = sanitize_email($data['email']);

    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $token = wp_generate_password(64, false, false);
    $expires = gmdate('Y-m-d H:i:s', time() + 24*3600);

    $wpdb->insert($table, [
        'form_slug'      => $slug,
        'status'         => 'pending',
        'data_json'      => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        'email'          => $to,
        'created_at'     => current_time('mysql'),
        'doi_token'      => $token,
        'doi_expires_at' => $expires,
        'ip_hash'        => (isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', $_SERVER['REMOTE_ADDR']) : null),
        'user_agent'     => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
    ], ['%s','%s','%s','%s','%s','%s','%s','%s']);

    $confirm_url = add_query_arg('formkit_confirm', $token, home_url('/'));
    $subject = apply_filters('formkit_doi_subject', 'Bitte bestätige deine Anfrage', $slug, $data);
    $headers = apply_filters('formkit_doi_headers', ['Content-Type: text/html; charset=UTF-8'], $slug, $data);

    $tpl = FORMKIT_WP_DIR . 'includes/examples/doi-email.mde';
    if (is_file($tpl)) {
        $template = FormKit\Core\Parser::loadTemplate($tpl, FORMKIT_PARTIALS_DIR);
        $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
        $html = $ev->render($template, ['data' => $data, 'confirm_url' => $confirm_url, 'now' => date('c')]);
        $ok = wp_mail($to, $subject, $html, $headers);
        if (!$ok) error_log('FormKit DOI: wp_mail() fehlgeschlagen an ' . $to);
    } else {
        $msg = "Bitte bestätige deine Anfrage:\n\n{$confirm_url}\n\n";
        $ok = wp_mail($to, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8']);
        if (!$ok) error_log('FormKit DOI: wp_mail() (Plain) fehlgeschlagen an ' . $to);
    }
}

add_action('template_redirect', function () {
    if (!isset($_GET['formkit_confirm'])) return;
    $token = sanitize_text_field($_GET['formkit_confirm']);

    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE doi_token=%s LIMIT 1", $token), ARRAY_A);

    if (!$row) {
        wp_die('Ungültiger Bestätigungslink.');
    }
    if (!empty($row['doi_expires_at']) && strtotime($row['doi_expires_at']) < time()) {
        wp_die('Der Bestätigungslink ist abgelaufen.');
    }

    $wpdb->update($table, [
        'status'       => 'confirmed',
        'confirmed_at' => current_time('mysql'),
        'doi_token'    => null,
    ], ['id' => $row['id']], ['%s','%s','%s'], ['%d']);

    $data = json_decode($row['data_json'] ?? '[]', true) ?: [];
    formkit_store_and_mail($row['form_slug'], $data);

    $url = add_query_arg('formkit', 'confirmed', home_url('/'));
    wp_safe_redirect($url);
    exit;
});
