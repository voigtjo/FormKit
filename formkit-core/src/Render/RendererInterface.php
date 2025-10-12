<?php
declare(strict_types=1);

namespace FormKit\Render;

interface RendererInterface
{
    public function renderWeb(string $slug, array $context): string;
    public function renderEmail(string $slug, array $context): string;
    /** Stub for Phase 0.1; real impl in Pro */
    public function renderPDF(string $slug, array $context): string;
}
