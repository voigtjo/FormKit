<?php
if (!defined('ABSPATH')) exit;

function formkit_collect_and_validate(string $slug): array {
    $data = [];
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['action','form_slug','formkit_nonce','_wp_http_referer'], true)) continue;
        $data[$k] = is_array($v) ? array_map('sanitize_text_field', wp_unslash($v)) : sanitize_text_field(wp_unslash($v));
    }
    $tpl  = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '.mde';
    $rules = FormKit\Core\Validator::extractRules(file_get_contents($tpl) ?: '');
    $errors = FormKit\Core\Validator::validate($data, $rules);
    return [$data, $errors];
}

function formkit_store_and_mail(string $slug, array $data): void {
    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $email = isset($data['email']) && is_email($data['email']) ? sanitize_email($data['email']) : null;

    $wpdb->insert($table, [
        'form_slug'  => $slug,
        'status'     => 'pending',
        'data_json'  => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        'email'      => $email,
        'created_at' => current_time('mysql'),
        'ip_hash'    => ($ip ? hash('sha256', $ip) : null),
        'user_agent' => $ua,
    ], ['%s','%s','%s','%s','%s','%s','%s']);

    $admin_to = apply_filters('formkit_admin_email', get_option('admin_email'), $slug, $data);
    if (!is_email($admin_to)) {
        $admin_to = get_option('admin_email');
    }
    $subject  = apply_filters('formkit_admin_subject', sprintf('[FormKit] Neue Einsendung: %s', $slug), $slug, $data);

    $emailTpl = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '-email.mde';
    $headers  = apply_filters('formkit_admin_headers', ['Content-Type: text/html; charset=UTF-8'], $slug, $data);

    if (is_file($emailTpl)) {
        $template = FormKit\Core\Parser::loadTemplate($emailTpl, FORMKIT_PARTIALS_DIR);
        $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
        $html = $ev->render($template, ['data' => $data, 'now' => date('c')]);
        $ok = wp_mail($admin_to, $subject, $html, $headers);
        if (!$ok) error_log('FormKit: wp_mail() an Admin fehlgeschlagen (HTML). Empfänger='.$admin_to.' Betreff='.$subject);
    } else {
        $ok = wp_mail(
            $admin_to,
            $subject,
            "Neue Einsendung:\n\n" . print_r($data, true),
            apply_filters('formkit_admin_headers_plain', ['Content-Type: text/plain; charset=UTF-8'], $slug, $data)
        );
        if (!$ok) error_log('FormKit: wp_mail() an Admin fehlgeschlagen (Plain). Empfänger='.$admin_to.' Betreff='.$subject);
    }
}

function formkit_export_csv() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=formkit-submissions.csv');
    $out = fopen('php://output','w');
    fputcsv($out, ['id','form_slug','status','email','created_at','data_json']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'],$r['form_slug'],$r['status'],$r['email'],$r['created_at'],$r['data_json']]);
    }
    fclose($out);
    exit;
}
