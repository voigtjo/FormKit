<?php
namespace FormKit\Core\Contracts;
interface TemplateRepoInterface {
    public function getTemplate(string $slug): ?string;
    public function listTemplates(?string $type=null): array;
}
