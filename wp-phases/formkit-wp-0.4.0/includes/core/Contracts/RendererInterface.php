<?php
namespace FormKit\Core\Contracts;
interface RendererInterface {
    public function render(string $mde, array $ctx = []);
    public function getFormat(): string;
}
