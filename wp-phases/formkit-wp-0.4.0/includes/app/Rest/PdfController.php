<?php
namespace FormKit\App\Rest;

use WP_REST_Request; use WP_Error;
use FormKit\Core\Support\Container;
use FormKit\Core\Contracts\TemplateRepoInterface;
use FormKit\Core\Templating\Parser;

final class PdfController
{
    public function register(): void
    {
        register_rest_route('formkit/v1','/pdf',[
            'methods'=>'GET',
            'callback'=>[$this,'handle'],
            'permission_callback'=>'__return_true',
        ]);
    }

    public function handle(WP_REST_Request $req)
    {
        $slug = sanitize_title($req->get_param('slug') ?: 'menu');
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return new WP_Error('formkit_pdf_missing','Dompdf nicht installiert. Bitte <code>composer require dompdf/dompdf</code> lokal ausführen und den <code>vendor/</code>-Ordner mit hochladen.',['status'=>500]);
        }
        /** @var TemplateRepoInterface $repo */
        $repo = Container::instance()->get(TemplateRepoInterface::class);
        $src  = $repo->getTemplate($slug);
        if ($src === null) return new WP_Error('formkit_not_found','Template not found',['status'=>404]);

        $src = Parser::expandPartials($src, FORMKIT_WP_DIR.'includes/partials');

        // Beispiel-Context
        $ctx = apply_filters('formkit_pdf_context', [
            'now'=>date('c'),
            'data'=>[ 'prices'=>['soup'=>6.9,'salad'=>8.5,'ragout'=>18.9,'zander'=>21.5] ]
        ], $slug);

        $binary = Container::instance()->get('renderer.pdf')->render($src, $ctx);

        $filename = $slug.'.pdf';
        $headers = apply_filters('formkit_pdf_headers', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"'
        ], $slug);

        foreach ($headers as $k=>$v) header($k.': '.$v);
        echo $binary; exit;
    }
}
