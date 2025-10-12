<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('FormKit','FormKit','manage_options','formkit-admin','formkit_admin_page','dashicons-feedback',81);
    add_submenu_page('formkit-admin','Submissions','Submissions','manage_options','formkit-submissions','formkit_admin_submissions');
});

function formkit_admin_page() {
    echo '<div class="wrap"><h1>FormKit – 0.3</h1>';
    echo '<p>Shortcodes: <code>[formkit slug="contact"]</code>, <code>[formkit slug="reservation"]</code></p>';
    echo '<p>DOI: aktiviert (Variante A, Query-Param).</p>';
    echo '</div>';
}

function formkit_admin_submissions() {
    if (isset($_GET['export']) && $_GET['export']==='csv') {
        formkit_export_csv(); return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'formkit_submissions';
    $status = sanitize_text_field($_GET['status'] ?? '');
    $where = '';
    $args = [];
    if (in_array($status, ['pending','confirmed','rejected'], true)) {
        $where = 'WHERE status = %s';
        $args[] = $status;
    }
    $page = max(1, intval($_GET['paged'] ?? 1));
    $per  = 20; $off  = ($page-1)*$per;
    $sql = "SELECT * FROM {$table} " . ($where ? $where.' ' : '') . "ORDER BY id DESC LIMIT %d OFFSET %d";
    $args = array_merge($args, [$per, $off]);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $pages = max(1, ceil($total/$per));
    echo '<div class="wrap"><h1>Submissions</h1>';
    echo '<p>';
    $base = remove_query_arg(['status','paged']);
    printf('<a class="button" href="%s">Alle</a> ', esc_url($base));
    printf('<a class="button" href="%s">Pending</a> ', esc_url(add_query_arg('status','pending',$base)));
    printf('<a class="button" href="%s">Confirmed</a> ', esc_url(add_query_arg('status','confirmed',$base)));
    printf('<a class="button" href="%s">Rejected</a> ', esc_url(add_query_arg('status','rejected',$base)));
    printf('<a class="button button-primary" href="%s">Export CSV</a>', esc_url(add_query_arg('export','csv')));
    echo '</p>';
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
            echo "<a $cls href=\"$url\">$i</a> ";
        }
        echo '</p>';
    }
    echo '</div>';
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
