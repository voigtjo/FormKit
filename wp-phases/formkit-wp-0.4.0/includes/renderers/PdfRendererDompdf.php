<?php
declare(strict_types=1);

namespace FormKit\Renderers;

use Dompdf\Dompdf;
use Dompdf\Options;
use DOMDocument;
use DOMElement;
use DOMXPath;
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
        $ctx['meta']     = $meta;
        $ctx['__format'] = 'pdf'; // Evaluator rendert Boxen statt Inputs

        // 1) MDE → HTML
        $html = $this->ev->render($body, $ctx);

        // 1a) Row-Layout (fk-row/fk-col) → <table class="grid g12">…</table>
        $html = $this->transformRowLayoutToTableGrid($html);

        // 1b) HTML5-Inputs normalisieren (date/time/number → text) – Fallback
        $html = $this->normalizeControlsForPdf($html);

        // 2) fk-grid → Tabellen (bestehende Grids werden ebenfalls PDF-stabil)
        $html = $this->transformGridToTables($html);

        // 3) Seite/CSS
        $pageSize = $meta['page']   ?? 'A4';
        $margin   = $meta['margin'] ?? '20mm';
        $header   = $meta['header'] ?? '';
        $footer   = $meta['footer'] ?? '';
        $footer   = strtr($footer, ['{PAGE_NUM}' => '{PAGE_NUM}', '{PAGE_COUNT}' => '{PAGE_COUNT}']);

        $css = "@page { margin: {$margin}; }";
        $cssPath = dirname(__DIR__, 2) . '/assets/pdf.css';
        if (is_file($cssPath)) {
            $css .= "\n" . @file_get_contents($cssPath);
        }

        $decor = '';
        if ($header) $decor .= '<div class="page-header">'.$header.'</div>';
        if ($footer) $decor .= '<div class="page-footer">'.$footer.'</div>';

        $outHtml = '<!doctype html><html><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'
                 . $decor . $html . '</body></html>';

        // 4) Dompdf-Optionen (stabil & hübsch)
        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $opts->set('dpi', 96);
        $opts->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($opts);
        $dompdf->setPaper($pageSize);
        $dompdf->loadHtml($outHtml, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    private function normalizeControlsForPdf(string $html): string
    {
        // Falls doch mal echte Inputs durchrutschen: verlässliche Darstellung
        return (string)preg_replace(
            '/(<input\b[^>]*?\btype=")(?:date|time|number)(")/i',
            '$1text$2',
            $html
        );
    }

    /**
     * Wandelt das Web-Row-Layout (.fk-row / .fk-col fk-span-N) in
     * eine einzelne Tabelle <table class="grid g12"> um.
     * Jede .fk-row wird zu einem <tr>, jede .fk-col zu <td colspan=N>.
     * Nicht-Row-Elemente werden als volle 12er-Zeile eingefügt.
     */
    private function transformRowLayoutToTableGrid(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        // Zieltabelle vorbereiten
        $table = $dom->createElement('table');
        $table->setAttribute('class', 'grid g12');
        $tbody = $dom->createElement('tbody');
        $table->appendChild($tbody);

        // Hilfsfunktion: beliebigen Node als volle Zeile (colspan=12)
        $wrapAsFullRow = function(DOMElement|\DOMNode $node) use ($dom, $tbody) {
            $tr = $dom->createElement('tr');
            $td = $dom->createElement('td');
            $td->setAttribute('colspan', '12');
            $td->appendChild($node);
            $tr->appendChild($td);
            $tbody->appendChild($tr);
        };

        // Body-Kinder der Reihe nach abarbeiten (Dokument-Reihenfolge beibehalten)
        while ($body->firstChild) {
            $node = $body->firstChild;
            $body->removeChild($node);

            if ($node instanceof DOMElement &&
                strpos(' '.$node->getAttribute('class').' ', ' fk-row ') !== false) {

                // .fk-row → <tr> mit mehreren <td colspan=…>
                $tr = $dom->createElement('tr');

                foreach (iterator_to_array($node->childNodes) as $col) {
                    if (!($col instanceof DOMElement)) continue;
                    if (strpos(' '.$col->getAttribute('class').' ', ' fk-col ') === false) continue;

                    // Span aus Klasse fk-span-N
                    $span = 12;
                    if (preg_match('/fk-span-(\d+)/', (string)$col->getAttribute('class'), $m)) {
                        $span = max(1, min(12, (int)$m[1]));
                    }

                    $td = $dom->createElement('td');
                    $td->setAttribute('colspan', (string)$span);

                    // Inhalte aus der Spalte in die Zelle verschieben
                    while ($col->firstChild) {
                        $td->appendChild($col->firstChild);
                    }
                    $tr->appendChild($td);
                }

                $tbody->appendChild($tr);
            } else {
                // Alles, was keine .fk-row ist, als volle Breite einfügen
                $wrapAsFullRow($node);
            }
        }

        // Tabelle zurück in den Body hängen
        $body->appendChild($table);

        $out = $dom->saveHTML();
        return (string)preg_replace('/^<\?xml.*?\?>/u', '', (string)$out);
    }

    /** FK-Grid (altes #grid/#col) → echte Tabellen (rekursiv) */
    private function transformGridToTables(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xp = new DOMXPath($dom);

        while (true) {
            $grids = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " fk-grid ")]');
            if (!$grids || $grids->length === 0) break;

            /** @var DOMElement $grid */
            foreach ($grids as $grid) {
                $this->convertOneGrid($dom, $grid);
            }
        }

        $out = $dom->saveHTML();
        return (string)preg_replace('/^<\?xml.*?\?>/u', '', (string)$out);
    }

    private function convertOneGrid(DOMDocument $dom, DOMElement $grid): void
    {
        $class = ' '.$grid->getAttribute('class').' ';
        $cols = 2;
        if (preg_match('/fk-cols-(\d+)/', $class, $m)) {
            $cols = max(1, (int)$m[1]);
        }

        $gap = $grid->getAttribute('data-gap');
        $gap = ($gap !== '') ? (int)$gap : 16;

        $children = [];
        foreach (iterator_to_array($grid->childNodes) as $node) {
            if ($node instanceof DOMElement && preg_match('/\bfk-col\b/', ' '.$node->getAttribute('class').' ')) {
                $children[] = $node;
            }
        }
        if (empty($children)) {
            $wrapper = $dom->createElement('div');
            while ($grid->firstChild) {
                $wrapper->appendChild($grid->firstChild);
            }
            $grid->parentNode?->replaceChild($wrapper, $grid);
            return;
        }

        $table = $dom->createElement('table');
        $table->setAttribute('class', 'fk-grid-table');
        $tbody = $dom->createElement('tbody');
        $table->appendChild($tbody);

        $currentRow = $dom->createElement('tr');
        $tbody->appendChild($currentRow);

        $filled = 0;

        foreach ($children as $colDiv) {
            $colspan = 1;
            $style = $colDiv->getAttribute('style');
            if ($style && preg_match('/grid-column\s*:\s*span\s+(\d+)/i', $style, $m)) {
                $colspan = max(1, (int)$m[1]);
            }

            if ($filled + $colspan > $cols) {
                $currentRow = $dom->createElement('tr');
                $tbody->appendChild($currentRow);
                $filled = 0;
            }

            $td = $dom->createElement('td');
            if ($colspan > 1) $td->setAttribute('colspan', (string)$colspan);

            while ($colDiv->firstChild) {
                $td->appendChild($colDiv->firstChild);
            }
            $currentRow->appendChild($td);
            $filled += $colspan;

            if ($gap > 0 && $filled < $cols) {
                $gapTd = $dom->createElement('td');
                $gapTd->setAttribute('class', '_gap');
                $gapTd->setAttribute('style', 'width: '.$gap.'px;');
                $currentRow->appendChild($gapTd);
            }
        }

        $grid->parentNode?->replaceChild($table, $grid);
    }
}
