<?php
declare(strict_types=1);

namespace FormKit\Renderers;

use Dompdf\Dompdf;
use Dompdf\Options;
use FormKit\Core\Contracts\RendererInterface;
use FormKit\Core\Support\Frontmatter;
use FormKit\Core\Templating\Evaluator;

final class PdfRendererDompdf implements RendererInterface
{
    public function __construct(private Evaluator $ev, private string $partialsDir) {}
    public function getFormat(): string { return 'pdf'; }

    public function render(string $mde, array $ctx = [])
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException('Dompdf not installed');
        }

        [$meta, $body] = Frontmatter::split($mde);
        $ctx['meta']   = $meta;

        $html = $this->ev->render($body, $ctx);

        $pageSize = $meta['page']   ?? 'A4';
        $margin   = $meta['margin'] ?? '20mm';
        $header   = $meta['header'] ?? '';
        $footer   = $meta['footer'] ?? '';

        // Tokens in Footer (werden von Dompdf nicht automatisch ersetzt,
        // bleiben hier als Platzhalter, falls du später eine Seiten-Canvas einfügst)
        $footer = strtr($footer, [
            '{PAGE_NUM}'   => '{PAGE_NUM}',
            '{PAGE_COUNT}' => '{PAGE_COUNT}',
        ]);

        // WICHTIG: Dompdf hat Probleme mit CSS Grid → table-basierte Spalten
        $baseCss = <<<CSS
        @page { margin: $margin; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1,h2,h3 { margin: 0 0 8px 0; }

        /* ------- FormKit PDF Grid (stabil) ------- */
        .fk-grid { display: table; width: 100%; border-collapse: separate; }
        .fk-col  { display: table-cell; vertical-align: top; padding-right: 12pt; }

        .fk-cols-1 .fk-col { width: 100%; }
        .fk-cols-2 .fk-col { width: 50%; }
        .fk-cols-3 .fk-col { width: 33.3333%; }
        .fk-cols-4 .fk-col { width: 25%; }

        /* Gap-Simulation über data-gap */
        .fk-grid[data-gap="0"]  .fk-col { padding-right: 0; }
        .fk-grid[data-gap="8"]  .fk-col { padding-right: 8pt; }
        .fk-grid[data-gap="12"] .fk-col { padding-right: 12pt; }
        .fk-grid[data-gap="16"] .fk-col { padding-right: 16pt; }
        .fk-grid[data-gap="24"] .fk-col { padding-right: 24pt; }

        .fk-grid .fk-col:last-child { padding-right: 0; }

        /* Tabellen */
        .fk-table { width: 100%; border-collapse: collapse; }
        .fk-table th, .fk-table td { border: 1px solid #ccc; padding: 6px; }

        .page-header { position: fixed; top: -25mm; left: 0; right: 0; text-align: center; }
        .page-footer { position: fixed; bottom: -15mm; left: 0; right: 0; text-align: center; font-size: 10px; color: #666; }
        CSS;

        $decor = '';
        if ($header) $decor .= '<div class="page-header">' . $header . '</div>';
        if ($footer) $decor .= '<div class="page-footer">' . $footer . '</div>';

        $outHtml = '<!doctype html><html><head><meta charset="utf-8"><style>' . $baseCss . '</style></head><body>' .
                   $decor . $html . '</body></html>';

        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($opts);
        $dompdf->setPaper($pageSize);
        $dompdf->loadHtml($outHtml, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }
}
