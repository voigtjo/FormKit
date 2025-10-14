<?php
namespace FormKit\App;

use FormKit\Core\Support\Container;
use FormKit\Core\Contracts\TemplateRepoInterface;
use FormKit\Core\Contracts\PartialRepoInterface;
use FormKit\Core\Repos\FileTemplateRepo;
use FormKit\Core\Repos\FilePartialRepo;
use FormKit\Core\Templating\{Parser,Evaluator,Filters};
use FormKit\Renderers\{WebRenderer,EmailRenderer,PdfRendererDompdf};

final class Bootstrap
{
    public static function init(): void
    {
        self::db();
        self::assets();
        self::container();
        self::shortcodes();
        self::rest();
        self::admin();
        self::doiFlow();
    }

    private static function db(): void
    {
        register_activation_hook(FORMKIT_WP_DIR . 'formkit-wp.php', function(){
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
              PRIMARY KEY (id),
              KEY form_slug_idx (form_slug),
              KEY status_idx (status),
              KEY doi_token_idx (doi_token)
            ) $charset;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        });
    }

    private static function assets(): void
    {
        add_action('wp_enqueue_scripts', function () {
            $css = '.formkit{background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:2rem;max-width:860px;margin:2rem auto}.formkit h1,.formkit h2,.formkit h3{margin-top:0}.formkit label{display:block;margin:.75rem 0 .25rem;font-weight:600}.formkit input,.formkit textarea,.formkit select{background:#fff;border:1px solid #dcdcdc;border-radius:8px;width:100%;padding:.6rem .7rem}.formkit button[type=submit]{margin-top:1rem;padding:.7rem 1.1rem;border:0;border-radius:8px;background:#0073aa;color:#fff;cursor:pointer}.formkit .notice{margin:0 0 1rem 0}@media (max-width:600px){.formkit{padding:1.25rem;border-radius:12px}}';
            wp_register_style('formkit-inline', false);
            wp_enqueue_style('formkit-inline');
            wp_add_inline_style('formkit-inline', $css);
        });
    }

    private static function container(): void
    {
        $c = Container::instance();
        $c->set(TemplateRepoInterface::class, fn()=> new FileTemplateRepo(FORMKIT_WP_DIR.'includes/examples'));
        $c->set(PartialRepoInterface::class,  fn()=> new FilePartialRepo(FORMKIT_WP_DIR.'includes/partials'));

        $c->set('filters', fn()=> new Filters());
        $c->set('evaluator', fn($c)=> new Evaluator($c->get('filters')));

        $c->set('renderer.web',   fn($c)=> new WebRenderer($c->get('evaluator'), FORMKIT_WP_DIR.'includes/partials'));
        $c->set('renderer.email', fn($c)=> new EmailRenderer($c->get('evaluator'), FORMKIT_WP_DIR.'includes/partials'));
        $c->set('renderer.pdf',   fn($c)=> new PdfRendererDompdf($c->get('evaluator'), FORMKIT_WP_DIR.'includes/partials'));
    }

    private static function shortcodes(): void
    {
        \FormKit\App\Shortcodes\FormkitShortcode::register();
    }

    private static function rest(): void
    {
        add_action('rest_api_init', function(){
            (new \FormKit\App\Rest\PreviewController())->register();
            (new \FormKit\App\Rest\PdfController())->register();
        });
    }

    private static function admin(): void
    {
        add_action('admin_menu', function () {
            add_menu_page('FormKit','FormKit','manage_options','formkit-admin',
                function(){ echo '<div class="wrap"><h1>FormKit 0.4.0</h1><p>Shortcodes: <code>[formkit slug="contact"]</code>, <code>[formkit slug="reservation"]</code></p><p>PDF-REST: <code>GET /wp-json/formkit/v1/pdf?slug=menu</code></p></div>'; },
                'dashicons-feedback',81
            );
            add_submenu_page('formkit-admin','Submissions','Submissions','manage_options','formkit-submissions',
                [\FormKit\App\Admin\SubmissionsPage::class,'render']);
        });
    }

    private static function doiFlow(): void
    {
        // DOI-Confirm via ?formkit_confirm=TOKEN
        add_action('init', function(){
            if (!isset($_GET['formkit_confirm'])) return;
            $token = sanitize_text_field($_GET['formkit_confirm']);
            global $wpdb;
            $table = $wpdb->prefix . 'formkit_submissions';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE doi_token=%s", $token), ARRAY_A);
            $ref = remove_query_arg('formkit_confirm', wp_get_referer() ?: home_url('/'));
            if (!$row) { wp_safe_redirect(add_query_arg('formkit','err',$ref)); exit; }
            if (!empty($row['doi_expires_at']) && strtotime($row['doi_expires_at'].' UTC') < time()) {
                wp_safe_redirect(add_query_arg('formkit','err',$ref)); exit;
            }
            $wpdb->update($table, [
                'status'=>'confirmed', 'confirmed_at'=>current_time('mysql'), 'doi_token'=>null
            ], ['id'=>$row['id']], ['%s','%s','%s'], ['%d']);

            // Admin-Mail nach Confirm
            $data = json_decode($row['data_json'], true) ?: [];
            \FormKit\App\Shortcodes\FormkitShortcode::notify_admin($row['form_slug'], $data);

            wp_safe_redirect(add_query_arg('formkit','confirm_ok',$ref)); exit;
        });
    }
}
