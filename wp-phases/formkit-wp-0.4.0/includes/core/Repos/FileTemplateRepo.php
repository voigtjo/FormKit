<?php
namespace FormKit\Core\Repos;
use FormKit\Core\Contracts\TemplateRepoInterface;

final class FileTemplateRepo implements TemplateRepoInterface {
    public function __construct(private string $dir) {}
    public function getTemplate(string $slug): ?string {
        $f = rtrim($this->dir,'/').'/'.$slug.'.mde';
        return is_file($f) ? (file_get_contents($f) ?: '') : null;
    }
    public function listTemplates(?string $type=null): array {
        $out=[]; foreach (glob(rtrim($this->dir,'/').'/*.mde') as $f) $out[] = basename($f,'.mde'); return $out;
    }
}
