<?php
/**
 * Plugin Name: FormKit (WP) – 0.3
 * Description: MarkdownExtended-basierte Form- & Mail-Templates. Shortcode + REST Submit + Admin Submissions + DOI (Variante A).
 * Version: 0.3
 * Author: FormKit
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

if (!defined('ABSPATH')) exit;

define('FORMKIT_WP_DIR', plugin_dir_path(__FILE__));
define('FORMKIT_WP_URL', plugin_dir_url(__FILE__));

// --- Autoload (PSR-4 light) ---
spl_autoload_register(function ($class) {
    $prefix = 'FormKit' . chr(92);
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));
    $rel = str_replace(chr(92), '/', $rel);
    $path = FORMKIT_WP_DIR . 'includes/src/' . $rel . '.php';
    if (is_file($path)) require $path;
});

define('FORMKIT_PARTIALS_DIR', FORMKIT_WP_DIR . 'includes/partials');

// --- Frontend CSS ("Papier") ---
add_action('wp_enqueue_scripts', function () {
    $css = <<<CSS
    .formkit{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:2rem;max-width:860px;margin:2rem auto;}
    .formkit h1,.formkit h2,.formkit h3{margin-top:0}
    .formkit label{display:block;margin:.75rem 0 .25rem;font-weight:600}
    .formkit input,.formkit textarea{background:#fff;border:1px solid #dcdcdc;border-radius:8px;width:100%;padding:.6rem .7rem}
    .formkit button[type=submit]{margin-top:1rem;padding:.7rem 1.1rem;border:0;border-radius:8px;background:#0073aa;color:#fff;cursor:pointer}
    .formkit .notice{margin:0 0 1rem 0}
    @media (max-width: 600px){.formkit{padding:1.25rem;border-radius:12px}}
    CSS;
    wp_register_style('formkit-inline', false);
    wp_enqueue_style('formkit-inline');
    wp_add_inline_style('formkit-inline', $css);
});

// --- Activation: base table (dbDelta-safe) ---
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      form_slug VARCHAR(120) NOT NULL,
      status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
      data_json LONGTEXT NOT NULL,
      email VARCHAR(190) NULL,
      doi_token CHAR(64) NULL,
      doi_expires_at DATETIME NULL,
      confirmed_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      ip_hash CHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      PRIMARY KEY  (id),
      KEY form_slug_idx (form_slug),
      KEY status_idx (status),
      KEY doi_token_idx (doi_token)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('formkit_db_ver', '3');
});

// --- Runtime migration safeguard ---
add_action('plugins_loaded', function () {
    if (get_option('formkit_db_ver') !== '3') {
        global $wpdb;
        $table = $wpdb->prefix . 'formkit_submissions';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          form_slug VARCHAR(120) NOT NULL,
          status ENUM('pending','confirmed','rejected') DEFAULT 'pending',
          data_json LONGTEXT NOT NULL,
          email VARCHAR(190) NULL,
          doi_token CHAR(64) NULL,
          doi_expires_at DATETIME NULL,
          confirmed_at DATETIME NULL,
          created_at DATETIME NOT NULL,
          ip_hash CHAR(64) NULL,
          user_agent VARCHAR(255) NULL,
          PRIMARY KEY  (id),
          KEY form_slug_idx (form_slug),
          KEY status_idx (status),
          KEY doi_token_idx (doi_token)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('formkit_db_ver', '3');
    }
});

// --- Mail-Fehler tiefer loggen
add_action('wp_mail_failed', function($wp_error){
    if (is_wp_error($wp_error)) {
        error_log('FormKit wp_mail_failed: ' . $wp_error->get_error_message());
        $data = $wp_error->get_error_data();
        if ($data) error_log('FormKit wp_mail_failed data: ' . print_r($data, true));
    }
});

// --- Shortcode: renders form
add_shortcode('formkit', function ($atts) {
    $atts = shortcode_atts(['slug' => 'contact','mode' => 'web'], $atts, 'formkit');
    $slug = sanitize_title($atts['slug']);
    $tpl  = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '.mde';
    $ctx  = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '.json';
    if (!is_file($tpl)) return '<div class="formkit-error">Template not found.</div>';

    $template = FormKit\Core\Parser::loadTemplate($tpl, FORMKIT_PARTIALS_DIR);
    $context  = [];
    if (is_file($ctx)) {
        $json = file_get_contents($ctx);
        $context = json_decode($json, true);
        if (!is_array($context)) $context = [];
    }
    $context['now'] = date('c');

    $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
    $inner = $ev->render($template, $context);

    $msg = '';
    if (isset($_GET['formkit']) && $_GET['formkit'] === 'ok') {
        $msg = '<div class="notice notice-success" style="margin:1em 0;padding:.6em;border-left:4px solid #46b450;background:#f7fff7;">Danke – Anfrage wurde entgegengenommen. Bitte bestätige die E-Mail in deinem Posteingang.</div>';
    } elseif (isset($_GET['formkit']) && $_GET['formkit'] === 'confirmed') {
        $msg = '<div class="notice notice-success" style="margin:1em 0;padding:.6em;border-left:4px solid #46b450;background:#f7fff7;">Danke – deine E-Mail wurde bestätigt. Wir melden uns.</div>';
    } elseif (isset($_GET['formkit']) && $_GET['formkit'] === 'err') {
        $msg = '<div class="notice notice-error" style="margin:1em 0;padding:.6em;border-left:4px solid #dc3232;background:#fff7f7;">Es gab ein Problem. Bitte prüfe deine Eingaben.</div>';
    }

    $action = esc_url( admin_url('admin-post.php') );
    ob_start(); ?>
    <div class="formkit" data-formkit="<?php echo esc_attr($slug); ?>">
      <?php echo $msg; ?>
      <form method="post" action="<?php echo $action; ?>" class="formkit-form">
        <input type="hidden" name="action" value="formkit_submit" />
        <input type="hidden" name="form_slug" value="<?php echo esc_attr($slug); ?>" />
        <?php wp_nonce_field('formkit_submit_' . $slug, 'formkit_nonce'); ?>
        <?php echo $inner; ?>
        <button type="submit">Senden</button>
      </form>
    </div>
    <?php
    return ob_get_clean();
});

// --- Submit handlers
add_action('admin_post_nopriv_formkit_submit', 'formkit_handle_submit');
add_action('admin_post_formkit_submit',        'formkit_handle_submit');

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

function formkit_send_admin_mail(string $slug, array $data): void {
    $admin_to = apply_filters('formkit_admin_email', get_option('admin_email'), $slug, $data);
    if (!is_email($admin_to)) $admin_to = get_option('admin_email');

    // Korrekte Betreffzeile per Default
    $name = $data['name'] ?? 'Unbekannt';
    $subject_default = ($slug === 'reservation')
        ? "Neue Reservierungsanfrage von {$name}"
        : "Neue Kontakt-Anfrage von {$name}";

    $subject  = apply_filters('formkit_admin_subject', $subject_default, $slug, $data);
    $headers  = ['Content-Type: text/html; charset=UTF-8'];

    $emailTpl = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '-email.mde';
    if (is_file($emailTpl)) {
        $template = FormKit\Core\Parser::loadTemplate($emailTpl, FORMKIT_PARTIALS_DIR);
        $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
        $html = $ev->render($template, ['data'=>$data, 'now'=>date('c')]);
        wp_mail($admin_to, $subject, $html, $headers);
    } else {
        wp_mail($admin_to, $subject, "Neue Einsendung:\n\n".print_r($data, true), ['Content-Type: text/plain; charset=UTF-8']);
    }
}

function formkit_store_and_mail(string $slug, array $data): void {
    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $email = isset($data['email']) && is_email($data['email']) ? sanitize_email($data['email']) : null;

    // Insert (pending)
    $wpdb->insert($table, [
        'form_slug'  => $slug,
        'status'     => 'pending',
        'data_json'  => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        'email'      => $email,
        'created_at' => current_time('mysql'),
        'ip_hash'    => isset($_SERVER['REMOTE_ADDR']) ? hash('sha256', $_SERVER['REMOTE_ADDR']) : null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
    ]);
    $id = (int)$wpdb->insert_id;

    // DOI für alle Formulare (per Filter abschaltbar)
    $doi_enabled = apply_filters('formkit_doi_enabled', True, $slug, $data);

    if ($doi_enabled && $email) {
        // Token erzeugen & speichern
        $token = wp_generate_password(64, false, false);
        $expires = gmdate('Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS);
        $wpdb->update($table, ['doi_token'=>$token, 'doi_expires_at'=>$expires], ['id'=>$id]);

        // DOI-Mail an Nutzer
        $confirm_url = add_query_arg(['formkit_confirm'=>$token], home_url('/'));

        $tpl = FORMKIT_WP_DIR . 'includes/examples/doi-email.mde';
        $subject = apply_filters('formkit_user_subject_doi', 'Bitte bestätige deine Anfrage', $slug, $data);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if (is_file($tpl)) {
            $template = FormKit\Core\Parser::loadTemplate($tpl, FORMKIT_PARTIALS_DIR);
            $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
            $html = $ev->render($template, ['data'=>$data, 'token_url'=>$confirm_url, 'now'=>date('c')]);
            wp_mail($email, $subject, $html, $headers);
        } else {
            $msg = "Hallo,\n\nbitte bestätige über diesen Link:\n{$confirm_url}\n\nDanke!";
            wp_mail($email, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8']);
        }
        // Admin-Mail erst nach Bestätigung
        return;
    }

    // Fallback ohne DOI
    formkit_send_admin_mail($slug, $data);
}

function formkit_handle_submit() {
    $ref = wp_get_referer() ?: home_url('/');
    if (!isset($_POST['form_slug'])) return wp_safe_redirect(add_query_arg('formkit','err',$ref));
    $slug = sanitize_title(wp_unslash($_POST['form_slug']));
    if (!isset($_POST['formkit_nonce']) || !wp_verify_nonce($_POST['formkit_nonce'], 'formkit_submit_' . $slug)) {
        return wp_safe_redirect(add_query_arg('formkit','err',$ref));
    }
    [$data,$errors] = formkit_collect_and_validate($slug);
    if (!empty($errors)) return wp_safe_redirect(add_query_arg('formkit','err',$ref));

    formkit_store_and_mail($slug,$data);
    return wp_safe_redirect(add_query_arg(['formkit'=>'ok'], $ref));
}

// --- DOI Confirm via Query Param (Variante A) ---
add_action('init', function(){
    if (isset($_GET['formkit_confirm'])) {
        $token = sanitize_text_field($_GET['formkit_confirm']);
        formkit_handle_confirm($token);
    }
});

function formkit_handle_confirm(string $token): void {
    global $wpdb; $table = $wpdb->prefix . 'formkit_submissions';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE doi_token=%s LIMIT 1", $token), ARRAY_A);
    if (!$row) { wp_die('Ungültiger Bestätigungslink.'); }

    if (!empty($row['doi_expires_at']) && strtotime($row['doi_expires_at']) < time()) {
        wp_die('Bestätigungslink abgelaufen.');
    }

    // Status updaten
    $wpdb->update($table, [
        'status' => 'confirmed',
        'confirmed_at' => current_time('mysql'),
        'doi_token' => null,
        'doi_expires_at' => null,
    ], ['id' => (int)$row['id']]);

    // Admin-Mail jetzt senden
    $data = json_decode($row['data_json'], true) ?: [];
    formkit_send_admin_mail($row['form_slug'], $data);

    // Hook
    do_action('formkit_doi_confirmed', $row['form_slug'], $data);

    // Redirect
    wp_safe_redirect(add_query_arg('formkit', 'confirmed', home_url('/')));
    exit;
}

// --- Admin pages ---
require_once FORMKIT_WP_DIR . 'includes/wp/submissions.php';
