<?php
namespace FormKit\App\Admin;

final class SubmissionsPage
{
    public static function render(): void
    {
        if (isset($_GET['export']) && $_GET['export']==='csv') { self::exportCsv(); return; }
        global $wpdb; $table = $wpdb->prefix.'formkit_submissions';
        $page = max(1, intval($_GET['paged'] ?? 1)); $per = 20; $off = ($page-1)*$per;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per,$off), ARRAY_A);
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
                echo '<a '.$cls.' href="'.$url.'">'.$i.'</a> ';
            }
            echo '</p>';
        }
        echo '</div>';
    }

    private static function exportCsv(): void
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb; $table = $wpdb->prefix.'formkit_submissions';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=formkit-submissions.csv');
        $out = fopen('php://output','w');
        fputcsv($out, ['id','form_slug','status','email','created_at','data_json']);
        foreach ($rows as $r) fputcsv($out, [$r['id'],$r['form_slug'],$r['status'],$r['email'],$r['created_at'],$r['data_json']]);
        fclose($out); exit;
    }
}
