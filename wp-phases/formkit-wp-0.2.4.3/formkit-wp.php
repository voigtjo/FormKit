<?php
/**
 * Plugin Name: FormKit (WP) – Phase 0.2.4.3
 * Description: MarkdownExtended-basierte Form- & Mail-Templates. Shortcode + REST Submit + Admin Submissions + DOI (MVP).
 * Version: 0.2.4.3
 * Author: FormKit
 * Requires PHP: 7.4
 * Requires at least: 6.4
 */

if (!defined('ABSPATH')) exit;

define('FORMKIT_WP_DIR', plugin_dir_path(__FILE__));
define('FORMKIT_WP_URL', plugin_dir_url(__FILE__));

// --- Autoload (PSR-4 light) ---
spl_autoload_register(function ($class) {
    $prefix = 'FormKit' . chr(92); // "FormKit\" ohne Backslash-Literal
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));
    $rel = str_replace(chr(92), '/', $rel);
    $path = FORMKIT_WP_DIR . 'includes/src/' . $rel . '.php';
    if (is_file($path)) require $path;
});

define('FORMKIT_PARTIALS_DIR', FORMKIT_WP_DIR . 'includes/partials');

// Core includes (cleanup split)
require_once FORMKIT_WP_DIR . 'includes/wp/submissions.php';
require_once FORMKIT_WP_DIR . 'includes/wp/doi.php';

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

// --- Activation: DB table (dbDelta-safe) + DOI columns ---
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
      created_at DATETIME NOT NULL,
      ip_hash CHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      doi_token CHAR(64) NULL,
      doi_expires_at DATETIME NULL,
      confirmed_at DATETIME NULL,
      PRIMARY KEY  (id),
      KEY form_slug_idx (form_slug),
      KEY status_idx (status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Post-migration: falls Tabelle schon existierte, fehlende DOI-Spalten ergänzen
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    $need = [];
    if (!in_array('doi_token', $cols, true))       $need[] = "ADD COLUMN doi_token CHAR(64) NULL";
    if (!in_array('doi_expires_at', $cols, true))  $need[] = "ADD COLUMN doi_expires_at DATETIME NULL";
    if (!in_array('confirmed_at', $cols, true))    $need[] = "ADD COLUMN confirmed_at DATETIME NULL";
    if (!empty($need)) {
        $alter = "ALTER TABLE {$table} " . implode(', ', $need);
        $wpdb->query($alter);
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

// --- Shortcode: renders form (Frontend ohne Demo-JSON) ---
add_shortcode('formkit', function ($atts) {
    $atts = shortcode_atts(['slug' => 'contact','mode' => 'web'], $atts, 'formkit');
    $slug = sanitize_title($atts['slug']);
    $tpl  = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '.mde';
    if (!is_file($tpl)) return '<div class="formkit-error">Template not found.</div>';

    $template = FormKit\Core\Parser::loadTemplate($tpl, FORMKIT_PARTIALS_DIR);

    // Frontend: kein Demo-JSON laden
    $context  = [];
    $context['now'] = date('c');

    $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
    $inner = $ev->render($template, $context);

    $msg = '';
    if (isset($_GET['formkit']) && $_GET['formkit'] === 'ok') {
        $msg = '<div class="notice notice-success" style="margin:1em 0;padding:.6em;border-left:4px solid #46b450;background:#f7fff7;">Danke – Nachricht wurde gesendet.</div>';
        if (!empty($_GET['fk'])) {
            $fk = sanitize_text_field($_GET['fk']);
            $flash = get_transient('formkit_flash_' . $fk);
            if (is_array($flash)) {
                delete_transient('formkit_flash_' . $fk);
                $summary = '<ul style="margin:.5em 0 0 1em;">';
                foreach ($flash as $k => $v) {
                    if (is_array($v)) $v = implode(', ', array_map('esc_html', $v));
                    $summary .= '<li><strong>' . esc_html($k) . ':</strong> ' . esc_html((string)$v) . '</li>';
                }
                $summary .= '</ul>';
                $msg .= '<div class="notice" style="margin:.6em 0;padding:.6em;background:#f8f8f8;border-left:4px solid #ccd0d4;"><strong>Empfangsbestätigung:</strong> Wir haben folgende Daten erhalten:' . $summary . '</div>';
            }
        }
    } elseif (isset($_GET['formkit']) && $_GET['formkit'] === 'err') {
        $msg = '<div class="notice notice-error" style="margin:1em 0;padding:.6em;border-left:4px solid #dc3232;background:#fff7f7;">Es gab ein Problem. Bitte prüfe deine Eingaben.</div>';
    } elseif (isset($_GET['formkit']) && $_GET['formkit'] === 'confirmed') {
        $msg = '<div class="notice notice-success" style="margin:1em 0;padding:.6em;border-left:4px solid #46b450;background:#f7fff7;">Danke – Deine E-Mail wurde bestätigt.</div>';
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

function formkit_handle_submit() {
    $ref = wp_get_referer() ?: home_url('/');
    if (!isset($_POST['form_slug'])) return wp_safe_redirect(add_query_arg('formkit','err',$ref));
    $slug = sanitize_title(wp_unslash($_POST['form_slug']));
    if (!isset($_POST['formkit_nonce']) || !wp_verify_nonce($_POST['formkit_nonce'], 'formkit_submit_' . $slug)) {
        return wp_safe_redirect(add_query_arg('formkit','err',$ref));
    }
    [$data,$errors] = formkit_collect_and_validate($slug);
    if (!empty($errors)) return wp_safe_redirect(add_query_arg('formkit','err',$ref));

    $key = wp_generate_uuid4();
    set_transient('formkit_flash_' . $key, $data, 2 * MINUTE_IN_SECONDS);

    // DOI aktiv?
    $doi_enabled = apply_filters('formkit_doi_enabled', false, $slug, $data);
    if ($doi_enabled) {
        formkit_send_doi($slug, $data);
    } else {
        formkit_store_and_mail($slug, $data);
    }
    return wp_safe_redirect(add_query_arg(['formkit'=>'ok','fk'=>$key], $ref));
}

// --- REST (Preview + Submit) ---
add_action('rest_api_init', function () {
    register_rest_route('formkit/v1', '/preview', [
        'methods'  => 'GET',
        'callback' => function (WP_REST_Request $req) {
            $slug = sanitize_title($req->get_param('slug') ?: 'contact');
            $tpl  = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '.mde';
            $ctx  = FORMKIT_WP_DIR . 'includes/examples/' . $slug . '.json';
            if (!is_file($tpl)) return new WP_Error('formkit_not_found', 'Template not found', ['status' => 404]);
            $template = FormKit\Core\Parser::loadTemplate($tpl, FORMKIT_PARTIALS_DIR);
            $context  = [];
            if (is_file($ctx)) {
                $json = file_get_contents($ctx);
                $context = json_decode($json, true);
                if (!is_array($context)) $context = [];
            }
            $context['now'] = date('c');
            $ev = new FormKit\Core\Evaluator(new FormKit\Core\Filters());
            $html = $ev->render($template, $context);
            return new WP_REST_Response(['html' => $html], 200);
        },
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('formkit/v1', '/submit/(?P<slug>[a-z0-9\-_]+)', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $req) {
            $slug = sanitize_title($req['slug']);
            $params = $req->get_json_params();
            if (!is_array($params)) $params = $req->get_body_params();
            $_POST = array_merge($params ?? [], ['form_slug'=>$slug]);
            [$data,$errors] = formkit_collect_and_validate($slug);
            if (!empty($errors)) return new WP_REST_Response(['ok'=>false,'errors'=>$errors], 422);

            $doi_enabled = apply_filters('formkit_doi_enabled', false, $slug, $data);
            if ($doi_enabled) {
                formkit_send_doi($slug, $data);
            } else {
                formkit_store_and_mail($slug, $data);
            }
            return new WP_REST_Response(['ok'=>true], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});

// --- Admin pages ---
add_action('admin_menu', function () {
    add_menu_page('FormKit','FormKit','manage_options','formkit-admin','formkit_admin_page','dashicons-feedback',81);
    add_submenu_page('formkit-admin','Submissions','Submissions','manage_options','formkit-submissions','formkit_admin_submissions');
});

function formkit_admin_page() {
    $example = esc_url(rest_url('formkit/v1/preview?slug=contact'));
    echo '<div class="wrap"><h1>FormKit – Phase 0.2.4.3</h1>';
    echo '<p>Shortcode: <code>[formkit slug="contact"]</code> oder <code>[formkit slug="reservation"]</code></p>';
    echo '<p>REST Preview: <a href="'.$example.'" target="_blank">'.$example.'</a></p>';
    echo '<p>REST Submit: <code>POST /wp-json/formkit/v1/submit/contact</code></p>';
    echo '</div>';
}

function formkit_admin_submissions() {
    if (isset($_GET['export']) && $_GET['export']==='csv') {
        formkit_export_csv(); return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $page = max(1, intval($_GET['paged'] ?? 1));
    $per  = 20; $off  = ($page-1)*$per;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per, $off), ARRAY_A);
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $pages = max(1, ceil($total/$per));
    echo '<div class="wrap"><h1>Submissions</h1>';
    echo '<p><a class="button button-primary" href="'.esc_url(add_query_arg('export','csv')).'">Export CSV</a></p>';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Form</th><th>Status</th><th>Email</th><th>Created</th><th>Data</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $data_short = esc_html(mb_strimwidth($r['data_json'], 0, 120, '…'));
        printf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code style="font-size:11px">%s</code></td></tr>',
            $r['id'], esc_html($r['form_slug']), esc_html($r['status']), esc_html($r['email'] ?? ''), esc_html($r['created_at']), $data_short);
    }
    if (empty($rows)) echo '<tr><td colspan="6">Keine Einträge</td></tr>';
    echo '</tbody></table>';
    if ($pages>1) {
        echo '<p class="tablenav-pages">';
        for ($i=1;$i<=$pages;$i++) {
            $url = esc_url(add_query_arg('paged',$i));
            $cls = $i===$page ? 'class="button button-secondary disabled"' : 'class="button button-secondary"';
            echo '<a ' . $cls . ' href="' . $url . '">' . $i . '</a> ';
        }
        echo '</p>';
    }
    echo '</div>';
}
