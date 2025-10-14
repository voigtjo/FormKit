<?php
declare(strict_types=1);

namespace FormKit\Core\Templating;

final class Evaluator
{
    public function __construct(private Filters $filters) {}

    /** Render MDE → HTML (ohne WP-Funktionen; rein PHP) */
    public function render(string $mde, array $ctx = []): string
    {
        $c = $ctx;

        // 1) Kontext und Regeln
        $mde = $this->processConst($mde, $c);
        $mde = $this->processCalc($mde, $c);
        $mde = $this->processRules($mde);       // #rule-Zeilen entfernen

        // 2) Kontrollstrukturen
        $mde = $this->processIf($mde, $c);
        $mde = $this->processLoop($mde, $c);
        $mde = $this->processRepeat($mde, $c);

        // 3) Layout/Struktur
        $mde = $this->processRowLayout($mde, $c);    // NEU: 12er Grid (#row/#col:SPAN)
        $mde = $this->processSectionFieldset($mde, $c);
        $mde = $this->processGrid($mde, $c);

        // 4) Inline/Elemente
        $mde = $this->processImg($mde, $c);
        $mde = $this->processSignature($mde, $c);
        $mde = $this->processBreak($mde, $c);
        $mde = $this->processQr($mde, $c);  
        $mde = $this->processFields($mde, $c);
        $mde = $this->processVars($mde, $c);

        return $this->markdownToHtml($mde);
    }

    /* ===================== Utilities ===================== */

    private function h(?string $s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** a.b.c Traversal */
    private function get(array|object $ctx, string $path): mixed
    {
        $cur = $ctx;
        foreach (explode('.', $path) as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } elseif (is_object($cur) && isset($cur->{$p})) {
                $cur = $cur->{$p};
            } else {
                return null;
            }
        }
        return $cur;
    }

    /** key="v" | 'v' | v  +  bool Flags */
    private function parseAttrs(string $attrs): array
    {
        $out = [];
        if (preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s}]+))/', $attrs, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $key = $m[1];
                $val = $m[2] !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
                $out[$key] = $val;
            }
        }
        if (preg_match_all('/\s([a-zA-Z0-9_-]+)(?=\s|$)/', $attrs, $flags)) {
            foreach ($flags[1] as $f) {
                if (!isset($out[$f])) $out[$f] = true;
            }
        }
        return $out;
    }

    /** kompakte key=val Paare (ohne Quotes), z.B. cols=3 gap=16 */
    private function kv(string $s): array
    {
        $out = [];
        if (preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*([^\s}]+)/', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[$x[1]] = trim($x[2], "\"'");
            }
        }
        return $out;
    }

    /** Mini-Pipeline für Spalteninhalte (innerhalb #grid/#col) */
    private function renderInline(string $src, array $c): string
    {
        $src = $this->processImg($src, $c);
        $src = $this->processSignature($src, $c);
        $src = $this->processFields($src, $c);
        $src = $this->processVars($src, $c);
        return $this->markdownToHtml($src);
    }

    /* ===================== Controls / Blocks ===================== */

    /** #const name value="..."  → Kontext anreichern, Zeile entfernen */
    private function processConst(string $s, array &$c): string
    {
        return (string)preg_replace_callback('/^\s*#const\s+([a-zA-Z0-9_.\-]+)\s+(.+)$/m', function($m) use (&$c){
            $name = trim($m[1]);
            $attrs = $this->parseAttrs($m[2]);
            $val = $attrs['value'] ?? '';
            $c[$name] = $val;
            return ''; // Zeile entfernen
        }, $s);
    }

    /** #calc total expr="a*b"  → Kontext berechnen, Zeile entfernen */
    private function processCalc(string $s, array &$c): string
    {
        return (string)preg_replace_callback('/^\s*#calc\s+([a-zA-Z0-9_.\-]+)\s+expr="([^"]+)"\s*$/m', function($m) use (&$c){
            $name = trim($m[1]);
            $expr = $m[2];
            $c[$name] = $this->evalExpr($expr, $c);
            return '';
        }, $s);
    }

    /** #rule … – im HTML/PDF nicht anzeigen → entfernen */
    private function processRules(string $s): string
    {
        return (string)preg_replace('/^\s*#rule\b[^\n]*$/m', '', $s);
    }

    /** sehr einfacher Ausdrucksauswerter (arithmetik + Funktionen) */
    private function evalExpr(string $expr, array $c): float|int
    {
        $e = trim($expr);

        // Funktionen sum(path), datediff(a,b)
        $e = preg_replace_callback('/sum\(([^)]+)\)/', function($m) use ($c){
            $path = trim($m[1]);
            return (string)$this->sumPath($path, $c);
        }, $e);

        $e = preg_replace_callback('/datediff\(([^,]+),\s*([^)]+)\)/', function($m) use ($c){
            $a = $this->valOf(trim($m[1]), $c);
            $b = $this->valOf(trim($m[2]), $c);
            $t1 = strtotime((string)$a) ?: 0;
            $t2 = strtotime((string)$b) ?: 0;
            return (string)floor(($t1 - $t2) / 86400);
        }, $e);

        // Variablen a.b → numerischer Wert
        $e = preg_replace_callback('/[a-zA-Z_][a-zA-Z0-9_.]*/', function($m) use ($c){
            $v = $this->valOf($m[0], $c);
            if ($v === null || $v === '') return '0';
            if (is_numeric($v)) return (string)$v;
            $v = str_replace([' ', ','], ['', '.'], (string)$v);
            return is_numeric($v) ? (string)$v : '0';
        }, $e);

        if (!preg_match('/^[0-9\.\+\-\*\/\(\)\s]+$/', $e)) return 0;

        $res = 0;
        try {
            $res = eval('return (float)(' . $e . ');');
        } catch (\Throwable $t) {
            $res = 0;
        }
        if (abs($res - round($res)) < 1e-12) return (int)round($res);
        return round($res, 6);
    }

    private function valOf(string $token, array $c): mixed
    {
        if ((str_starts_with($token, '"') && str_ends_with($token, '"')) ||
            (str_starts_with($token, "'") && str_ends_with($token, "'"))) {
            return trim($token, "\"'");
        }
        return $this->get($c, $token);
    }

    /** sum(costs[*].amount) */
    private function sumPath(string $path, array $c): float
    {
        if (!preg_match('/^([a-zA-Z0-9_\.]+)\[\*\]\.([a-zA-Z0-9_\.]+)$/', $path, $m)) {
            $v = $this->get($c, $path);
            return (float)($v ?? 0);
        }
        $left = $m[1]; $right = $m[2];
        $arr = $this->get($c, $left);
        if (!is_iterable($arr)) return 0.0;
        $sum = 0.0;
        foreach ($arr as $row) {
            $v = $this->get(['item' => $row], 'item.' . $right);
            if (is_numeric($v)) $sum += (float)$v;
        }
        return $sum;
    }

    /** #if cond … #endif  */
    private function processIf(string $s, array $c): string
    {
        return (string)preg_replace_callback('/#if\s+([^\n]+)\n(.*?)#endif/s', function($m) use ($c){
            $cond = trim($m[1]);
            $v = $this->valOf($cond, $c);
            return !empty($v) ? $m[2] : '';
        }, $s);
    }

    /** #loop items … #endloop  */
    private function processLoop(string $s, array $c): string
    {
        return (string)preg_replace_callback('/#loop\s+([^\n]+)\n(.*?)#endloop/s', function($m) use ($c){
            $arr = $this->get($c, trim($m[1]));
            if (!is_iterable($arr)) return '';
            $out = '';
            foreach ($arr as $item) {
                $l = ['item' => $item] + $c;
                $chunk = $this->processVars($m[2], $l);
                $chunk = $this->processIf($chunk, $l);
                $chunk = $this->processFields($chunk, $l);
                $out .= $chunk;
            }
            return $out;
        }, $s);
    }

    /** #repeat … #endrepeat (unterstützt "inline"-Attribute) */
    private function processRepeat(string $s, array $c): string
    {
        return (string)preg_replace_callback('/#repeat\s+([^\n]+)\n?(.*?)#endrepeat/s', function($m) use ($c){
            $attrs = $this->parseAttrs(trim($m[1]));
            $name  = $attrs['name'] ?? 'items';
            $inner = $m[2];

            $index = 0;
            $transformed = (string)preg_replace_callback('/#field\s+([a-zA-Z0-9_.\-]+)([^\n]*)/', function($x) use ($name, $index){
                $fname = $x[1];
                $rest  = $x[2] ?? '';
                $prefixed = $name . '[' . $index . '].' . $fname;
                return '#field ' . $prefixed . $rest;
            }, $inner);

            return $transformed;
        }, $s);
    }

    /** NEU: 12er Row-Layout inkl. #cX-Kurzform und Inline-Rendering */
    private function processRowLayout(string $s, array $c): string
    {
        // #section(#row-layout) → Wrapper (optional; Web-CSS funktioniert auch ohne)
        $s = preg_replace('/^\s*#section\(#row-layout\)\s*$/m', '<section class="fk-section fk-row-layout">', $s);

        // Reihen öffnen/schließen
        $s = preg_replace('/^\s*#row\s*$/m', '<div class="fk-row">', $s);
        $s = preg_replace('/^\s*#endrow\s*$/m', '</div>', $s);

        // Variante A: #col:6 <content>  (Inline)
        $s = (string)preg_replace_callback(
            '/#col:(\d+)\s+(.*?)(?=\R|$)/',
            function($m) use ($c) {
                $span = max(1, min(12, (int)$m[1]));
                $content = $this->renderInline($m[2], $c);
                return '<div class="fk-col fk-span-'.$span.'">'.$content.'</div>';
            },
            $s
        );

        // Variante B: #c6 <content>  (Kurzform, Inline)
        $s = (string)preg_replace_callback(
            '/#c(\d+)\s+(.*?)(?=\R|$)/',
            function($m) use ($c) {
                $span = max(1, min(12, (int)$m[1]));
                $content = $this->renderInline($m[2], $c);
                return '<div class="fk-col fk-span-'.$span.'">'.$content.'</div>';
            },
            $s
        );

        // Variante C: Block-Start auf eigener Zeile → #col:6 / #c6 (bis #endcol)
        $s = (string)preg_replace_callback(
            '/^\s*(?:#col:(\d+)|#c(\d+))\s*$(.*?)^\s*#endcol\s*$/ms',
            function($m) use ($c) {
                $span = $m[1] !== '' ? (int)$m[1] : (int)$m[2];
                $span = max(1, min(12, $span));
                $content = $this->renderInline($m[3], $c);
                return '<div class="fk-col fk-span-'.$span.'">'.$content.'</div>';
            },
            $s
        );

        return $s;
    }


    /** #qr {value="SRC" size=120 alt="QR"}  → <img …>  */
    private function processQr(string $s, array $c): string
    {
        return (string)preg_replace_callback('/^\s*#qr\s*\{([^}]*)\}\s*$/m', function($m){
            $a    = $this->parseAttrs($m[1]);
            $src  = (string)($a['value'] ?? '');
            $size = (int)($a['size']  ?? 120);
            $alt  = (string)($a['alt'] ?? 'QR');
            if ($src === '') return '';
            return '<img class="fk-qr" src="'.$this->h($src).'" width="'.$size
                .'" height="'.$size.'" alt="'.$this->h($alt).'" />';
        }, $s);
    }



    /** #section/#endsection, #fieldset/#endfieldset – tolerant gegen Einrückung */
    private function processSectionFieldset(string $s, array $c): string
    {
        $s = (string)preg_replace_callback('/^\s*#section\s*\{([^}]*)\}\s*$/m', function($m){
            $a = $this->parseAttrs($m[1]);
            $title = isset($a['title']) ? '<h2 class="fk-section-title">'.$this->h($a['title']).'</h2>' : '';
            return '<section class="fk-section">'.$title;
        }, $s);
        $s = preg_replace('/^\s*#endsection\s*$/m', '</section>', $s);

        $s = (string)preg_replace_callback('/^\s*#fieldset\s*\{([^}]*)\}\s*$/m', function($m){
            $a = $this->parseAttrs($m[1]);
            $legend = isset($a['legend']) ? '<legend>'.$this->h($a['legend']).'</legend>' : '';
            return '<fieldset class="fk-fieldset">'.$legend;
        }, $s);
        $s = preg_replace('/^\s*#endfieldset\s*$/m', '</fieldset>', $s);

        return (string)$s;
    }

    /** #grid/#col – innerer Inhalt wird erneut durch die Inline-Pipeline gerendert */
    private function processGrid(string $s, array $c): string
    {
        return (string)preg_replace_callback('/#grid\s*\{([^}]*)\}\s*(.*?)#endgrid/s', function($m) use ($c){
            $a = $this->kv($m[1]);
            $cols = max(1, (int)($a['cols'] ?? 2));
            $gap  = (string)($a['gap'] ?? '16');
            $inner = $m[2];

            $inner = (string)preg_replace_callback('/#col\s*(?:\{([^}]*)\})?\s*(.*?)#endcol/s', function($n) use ($c){
                $attrs = $this->kv($n[1] ?? '');
                $span  = isset($attrs['span']) ? max(1, (int)$attrs['span']) : 1;
                $style = $span > 1 ? ' style="grid-column: span '.$span.'"' : '';
                $content = $this->renderInline($n[2], $c);
                return '<div class="fk-col"'.$style.'>'.$content.'</div>';
            }, $inner);

            $style = 'display:grid;grid-template-columns:repeat('.$cols.',1fr);gap:'.$this->h($gap).'px';
            return '<div class="fk-grid fk-cols-'.$cols.'" data-gap="'.$this->h($gap).'" style="'.$style.'">'.$inner.'</div>';
        }, $s);
    }

    /** #img {src=... width=...} */
    private function processImg(string $s, array $c): string
    {
        return (string)preg_replace_callback('/#img\s*\{([^}]*)\}/', function($m){
            $a = $this->kv($m[1]);
            $src = $a['src'] ?? '';
            $w   = isset($a['width']) ? ' width="'.$this->h($a['width']).'"' : '';
            if ($src === '') return '';
            return '<img src="'.$this->h($src).'"'.$w.' />';
        }, $s);
    }

    /** #signature name="…" label="…" – tolerant gegen Einrückung */
    private function processSignature(string $s, array $c): string
    {
        return (string)preg_replace_callback('/^\s*#signature\s+\{([^}]*)\}\s*$/m', function($m){
            $a = $this->parseAttrs($m[1]);
            $name  = $a['name']  ?? 'signature';
            $label = $a['label'] ?? 'Unterschrift';
            $id = 'fk_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);
            $html  = '<label for="'.$id.'">'.$this->h($label).'</label>'."\n";
            $html .= '<input type="text" id="'.$id.'" name="'.$this->h($name).'" placeholder="Unterschrift" />';
            return $html;
        }, $s);
    }

    /** #break → Block, der im PDF als Seitenumbruch gestylt wird (Einrückung ok) */
    private function processBreak(string $s, array $c): string
    {
        return (string)preg_replace('/^\s*#break\s*$/m', '<div class="fk-break"></div>', $s);
    }

    /** Felder (#field) inkl. Typen */
   private function processFields(string $s, array $c): string
    {
        $format = $c['__format'] ?? 'web'; // 'web' | 'pdf'

        return (string)preg_replace_callback('/#field\s+([a-zA-Z0-9_.\-\[\]]+)([^\n]*)/', function($m) use ($c, $format){
            $nameRaw = $m[1];
            $attrs   = $m[2] ?? '';
            $p       = $this->parseAttrs($attrs);

            $type  = (string)($p['type'] ?? 'text');
            $label = $p['label'] ?? ucfirst(str_replace('_', ' ', preg_replace('/\[[0-9]+\]\./', ' ', $nameRaw)));
            $required   = array_key_exists('required', $p) ? ' required' : '';
            $placeholder= isset($p['placeholder']) ? ' placeholder="'.$this->h((string)$p['placeholder']).'"' : '';
            $min        = isset($p['min']) ? ' min="'.$this->h((string)$p['min']).'"' : '';
            $max        = isset($p['max']) ? ' max="'.$this->h((string)$p['max']).'"' : '';
            $step       = isset($p['step'])? ' step="'.$this->h((string)$p['step']).'"' : '';
            $readonly   = array_key_exists('readonly', $p) ? ' readonly' : '';

            $id    = 'fk_' . preg_replace('/[^a-z0-9_]+/i', '_', str_replace(['[',']','.'], '_', $nameRaw));
            $value = (string)($this->get($c, $nameRaw) ?? '');
            $valueEsc = $this->h($value);

            // Wrapper
            $html = '<div class="fk-control"><label for="'.$id.'">'.$this->h($label).'</label>'."\n";

            // Für PDFs: keine echten <input>, sondern „Boxen“, gut druckbar/schreibbar
            if ($format === 'pdf') {
                if ($type === 'textarea') {
                    $html .= '<div class="fk-textbox">'.($valueEsc !== '' ? $valueEsc : '&nbsp;').'</div>';
                } else {
                    // select/number/date/time/currency → gleiche Boxdarstellung
                    $html .= '<div class="fk-inputbox">'.($valueEsc !== '' ? $valueEsc : '&nbsp;').'</div>';
                }
                $html .= '</div>';
                return $html;
            }

            // Web: echte Form-Controls
            if ($type === 'textarea') {
                $html .= '<textarea id="'.$id.'" name="'.$this->h($nameRaw).'"'
                    .  $required.$placeholder.$readonly.'>'.$valueEsc.'</textarea>';
            } elseif ($type === 'select') {
                $opts = (string)($p['options'] ?? '');
                $html .= '<select id="'.$id.'" name="'.$this->h($nameRaw).'"'.$required.$readonly.'>';
                foreach (explode('|', $opts) as $opt) {
                    $opt = trim($opt);
                    if ($opt === '') continue;
                    $sel = ($opt === html_entity_decode($value, ENT_QUOTES, 'UTF-8')) ? ' selected' : '';
                    $html .= '<option value="'.$this->h($opt).'"'.$sel.'>'.$this->h($opt).'</option>';
                }
                $html .= '</select>';
            } else {
                if ($type === 'currency') { $type = 'number'; if ($step === '') $step = ' step="0.01"'; }
                $html .= '<input type="'.$this->h($type).'" id="'.$id.'" name="'.$this->h($nameRaw).'" value="'.$valueEsc.'"'
                    .  $required.$placeholder.$min.$max.$step.$readonly.' />';
            }

            $html .= '</div>';
            return $html;
        }, $s);
    }



    /** Variablen {{ path | filter:arg }} */
    private function processVars(string $s, array $c): string
    {
        return (string)preg_replace_callback('/\{\{\s*([^\|\}]+?)\s*(?:\|\s*([a-zA-Z0-9_]+)\s*(?::\s*([^}]+))?)?\s*\}\}/', function($m) use ($c){
            $path   = trim($m[1]);
            $filter = $m[2] ?? null;
            $argRaw = $m[3] ?? null;

            $val = $this->get($c, $path);
            if ($filter) {
                $arg = null;
                if ($argRaw !== null) {
                    $arg = trim($argRaw);
                    $arg = trim($arg, "\"'");
                }
                $val = $this->filters->apply($filter, $val, $arg);
            }
            return $this->h((string)($val ?? ''));
        }, $s);
    }

    /* ===================== Mini-Markdown ===================== */

    private function markdownToHtml(string $md): string
    {
        $lines = preg_split("/\r?\n/", $md);
        $html = [];
        foreach ($lines as $line) {
            // WICHTIG: HTML-Zeilen nicht in <p> einwickeln
            if (preg_match('/^\s*</', $line)) { $html[] = $line; continue; }

            if (preg_match('/^(#{1,6})\s*(.+)$/', $line, $m)) {
                $lvl = strlen($m[1]);
                $html[] = '<h' . $lvl . '>' . $this->inlineMd($m[2]) . '</h' . $lvl . '>';
                continue;
            }
            if (trim($line) === '') continue;
            $html[] = '<p>' . $this->inlineMd($line) . '</p>';
        }
        return implode("\n", $html);
    }

    private function inlineMd(string $t): string
    {
        $t = (string)preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $t);
        $t = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
        $t = (string)preg_replace('/(^|[^*])\*([^*\n]+)\*/', '\\1<em>\\2</em>', $t);
        return $t;
    }
}
