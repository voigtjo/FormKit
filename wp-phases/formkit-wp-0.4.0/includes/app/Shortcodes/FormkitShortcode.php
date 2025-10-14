<?php
namespace FormKit\App\Shortcodes;

use FormKit\Core\Support\Container;
use FormKit\Core\Contracts\TemplateRepoInterface;
use FormKit\Core\Templating\{Parser, Validator};

final class FormkitShortcode
{
    public static function register(): void
    {
        add_shortcode('formkit', [self::class,'render']);
        add_action('admin_post_nopriv_formkit_submit', [self::class,'handle_submit']);
        add_action('admin_post_formkit_submit',        [self::class,'handle_submit']);
        add_action('wp_mail_failed', function($err){
            if (is_wp_error($err)) error_log('FormKit wp_mail_failed: '.$err->get_error_message());
        });
    }

    public static function render($atts): string
    {
        $atts = shortcode_atts(['slug'=>'contact','mode'=>'web'], $atts, 'formkit');
        $slug = sanitize_title($atts['slug']);

        /** @var TemplateRepoInterface $repo */
        $repo = Container::instance()->get(TemplateRepoInterface::class);
        $src  = $repo->getTemplate($slug);
        if ($src === null) return '<div class="formkit-error">Template not found.</div>';

        $src = \FormKit\Core\Templating\Parser::expandPartials($src, FORMKIT_WP_DIR.'includes/partials');

        $ctx = ['now'=>date('c')];
        $html = Container::instance()->get('renderer.web')->render($src, $ctx);

        $msg = '';
        if (isset($_GET['formkit']) && $_GET['formkit']==='ok') {
            $msg = '<div class="notice notice-success" style="margin:1em 0;padding:.6em;border-left:4px solid #46b450;background:#f7fff7;">Danke – Nachricht wurde gesendet.</div>';
        } elseif (isset($_GET['formkit']) && $_GET['formkit']==='err') {
            $msg = '<div class="notice notice-error" style="margin:1em 0;padding:.6em;border-left:4px solid #dc3232;background:#fff7f7;">Es gab ein Problem. Bitte prüfe deine Eingaben.</div>';
        } elseif (isset($_GET['formkit']) && $_GET['formkit']==='confirm_ok') {
            $msg = '<div class="notice notice-success" style="margin:1em 0;padding:.6em;border-left:4px solid #46b450;background:#f7fff7;">Danke – E-Mail bestätigt.</div>';
        }

        $action = esc_url(admin_url('admin-post.php'));
        ob_start(); ?>
        <div class="formkit" data-formkit="<?php echo esc_attr($slug); ?>">
          <?php echo $msg; ?>
          <form method="post" action="<?php echo $action; ?>" class="formkit-form">
            <input type="hidden" name="action" value="formkit_submit" />
            <input type="hidden" name="form_slug" value="<?php echo esc_attr($slug); ?>" />
            <?php wp_nonce_field('formkit_submit_' . $slug, 'formkit_nonce'); ?>
            <?php echo $html; ?>
            <button type="submit">Senden</button>
          </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_submit(): void
    {
        $ref = wp_get_referer() ?: home_url('/');
        if (!isset($_POST['form_slug'])) { wp_safe_redirect($ref); return; }
        $slug = sanitize_title(wp_unslash($_POST['form_slug']));
        if (!isset($_POST['formkit_nonce']) || !wp_verify_nonce($_POST['formkit_nonce'], 'formkit_submit_' . $slug)) {
            wp_safe_redirect(add_query_arg('formkit','err',$ref)); return;
        }

        /** @var TemplateRepoInterface $repo */
        $repo = Container::instance()->get(TemplateRepoInterface::class);
        $src  = $repo->getTemplate($slug);
        if ($src === null) { wp_safe_redirect(add_query_arg('formkit','err',$ref)); return; }

        // rules aus Template
        $rules = Validator::extractRules($src);
        $data = [];
        foreach ($_POST as $k=>$v){
            if (in_array($k,['action','form_slug','formkit_nonce','_wp_http_referer'],true)) continue;
            $data[$k] = is_array($v) ? array_map('sanitize_text_field', wp_unslash($v)) : sanitize_text_field(wp_unslash($v));
        }
        $errors = Validator::validate($data, $rules);
        if (!empty($errors)) { wp_safe_redirect(add_query_arg('formkit','err',$ref)); return; }

        // store pending + send DOI
        $email = (isset($data['email']) && is_email($data['email'])) ? sanitize_email($data['email']) : null;
        $pending = self::store_pending($slug, $data, $email);
        if ($email) self::send_doi($slug, $data, $pending['token']);

        wp_safe_redirect(add_query_arg('formkit','ok',$ref));
    }

    private static function store_pending(string $slug, array $data, ?string $email): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'formkit_submissions';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
        $token = wp_generate_password(64, false, false);
        $wpdb->insert($table, [
            'form_slug'=>$slug,'status'=>'pending','data_json'=>wp_json_encode($data, JSON_UNESCAPED_UNICODE),
            'email'=>$email,'doi_token'=>$token,'doi_expires_at'=>gmdate('Y-m-d H:i:s', time()+2*DAY_IN_SECONDS),
            'created_at'=>current_time('mysql'),'ip_hash'=>($ip?hash('sha256',$ip):null),'user_agent'=>$ua,
        ], ['%s','%s','%s','%s','%s','%s','%s','%s','%s']);
        return ['id'=>$wpdb->insert_id,'token'=>$token];
    }

    private static function send_doi(string $slug, array $data, string $token): void
    {
        if (empty($data['email']) || !is_email($data['email'])) return;
        $to = sanitize_email($data['email']);
        $confirm_url = add_query_arg(['formkit_confirm'=>$token], home_url('/'));
        $repo = \FormKit\Core\Support\Container::instance()->get(\FormKit\Core\Contracts\TemplateRepoInterface::class);
        $tpl  = $repo->getTemplate('doi-email');

        if ($tpl) {
            $tpl = \FormKit\Core\Templating\Parser::expandPartials($tpl, FORMKIT_WP_DIR.'includes/partials');
            $html = \FormKit\Core\Support\Container::instance()->get('renderer.email')->render($tpl, ['data'=>$data,'confirm_url'=>$confirm_url,'now'=>date('c')]);
        } else {
            $html = '<p>Bitte bestätige deine E-Mail:</p><p><a href="'.esc_url($confirm_url).'">'.esc_html($confirm_url).'</a></p>';
        }
        wp_mail($to, 'Bitte E-Mail bestätigen', $html, ['Content-Type: text/html; charset=UTF-8']);
    }

    // Admin-Benachrichtigung nach Confirm
    public static function notify_admin(string $slug, array $data): void
    {
        $to = apply_filters('formkit_admin_email', get_option('admin_email'), $slug, $data);
        if (!is_email($to)) $to = get_option('admin_email');

        $subject = ($slug === 'reservation')
            ? "Neue Reservierungsanfrage von ".($data['name'] ?? 'Unbekannt')
            : "Neue Kontakt-Anfrage von ".($data['name'] ?? 'Unbekannt');
        $subject = apply_filters('formkit_admin_subject', $subject, $slug, $data);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if (!empty($data['email']) && is_email($data['email'])) $headers[] = 'Reply-To: '.sanitize_email($data['email']);

        $repo = Container::instance()->get(TemplateRepoInterface::class);
        $tpl  = $repo->getTemplate($slug.'-email');
        if ($tpl) {
            $tpl = \FormKit\Core\Templating\Parser::expandPartials($tpl, FORMKIT_WP_DIR.'includes/partials');
            $html = Container::instance()->get('renderer.email')->render($tpl, ['data'=>$data,'now'=>date('c')]);
        } else {
            $html = nl2br(esc_html("Neue Einsendung ({$slug}):\n\n".print_r($data,true)));
        }
        wp_mail($to, $subject, $html, $headers);
    }
}
