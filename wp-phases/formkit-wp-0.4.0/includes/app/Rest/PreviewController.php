<?php
namespace FormKit\App\Rest;

use WP_REST_Request; use WP_REST_Response; use WP_Error;
use FormKit\Core\Support\Container;
use FormKit\Core\Contracts\TemplateRepoInterface;
use FormKit\Core\Templating\Parser;

final class PreviewController
{
    public function register(): void
    {
        register_rest_route('formkit/v1','/preview',[
            'methods'=>'GET',
            'callback'=>[$this,'handle'],
            'permission_callback'=>'__return_true',
        ]);
    }
    public function handle(WP_REST_Request $req){
        $slug = sanitize_title($req->get_param('slug') ?: 'contact');
        /** @var TemplateRepoInterface $repo */
        $repo = Container::instance()->get(TemplateRepoInterface::class);
        $src  = $repo->getTemplate($slug);
        if ($src === null) return new WP_Error('formkit_not_found','Template not found',['status'=>404]);
        $src  = Parser::expandPartials($src, FORMKIT_WP_DIR.'includes/partials');
        $html = Container::instance()->get('renderer.web')->render($src, ['now'=>date('c')]);
        return new WP_REST_Response(['html'=>$html], 200);
    }
}
