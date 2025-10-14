<?php
namespace FormKit\Renderers;
use FormKit\Core\Contracts\RendererInterface;
use FormKit\Core\Templating\Evaluator;

final class WebRenderer implements RendererInterface {
    public function __construct(private Evaluator $ev, private string $partialsDir) {}
    public function getFormat(): string { return 'web'; }
    public function render(string $mde, array $ctx=[]) { return $this->ev->render($mde, $ctx); }
}
