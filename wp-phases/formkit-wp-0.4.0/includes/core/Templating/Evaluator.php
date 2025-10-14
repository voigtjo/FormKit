<?php
declare(strict_types=1);

namespace FormKit\Core\Templating;

final class Evaluator
{
    public function __construct(private Filters $filters) {}

    /** Hauptpipeline */
    public function render(string $mde, array $ctx = []): string
    {
        // 1) Logik
        $mde = $this->processIf($mde, $ctx);
        $mde = $this->processLoop($mde, $ctx);

        // 2) Layout-Makros (#grid/#col/… -> HTML-Container)
        $mde = $this->processLayoutBlocks($mde);

        // 3) Medien & Form-Felder
        $mde = $this->processImg($mde, $ctx);
        $mde = $this->processFields($mde, $ctx);

        // 4) Variablen / Filter
        $mde = $this->processVars($mde, $ctx);

        // 5) Markdown -> HTML
        return $this->markdownToHtml($mde);
    }

    /* -------------------------
       #if / #endif
    --------------------------*/
    private function processIf(string $s, array $c): string
    {
        return (string) preg_replace_callback(
            '/#if\s+([^\n]+)\n(.*?)#endif/s',
            function ($m) use ($c) {
                $v = $this->get($c, trim($m[1]));
                return !empty($v) ? $m[2] : '';
            },
            $s
        );
    }

    /* -------------------------
       #loop path … #endloop
    --------------------------*/
    private function processLoop(string $s, array $c): string
    {
        return (string) preg_replace_callback(
            '/#loop\s+([^\n]+)\n(.*?)#endloop/s',
            function ($m) use ($c) {
                $arr = $this->get($c, trim($m[1]));
                if (!is_iterable($arr)) return '';
                $out = '';
                foreach ($arr as $item) {
                    $lctx  = ['item' => $item] + $c;
                    $chunk = $m[2];
                    $chunk = $this->processIf($chunk, $lctx);
                    $chunk = $this->processVars($chunk, $lctx);
                    $chunk = $this->processFields($chunk, $lctx);
                    $out  .= $chunk;
                }
                return $out;
            },
            $s
        );
    }

    /* -------------------------
       Layout-Blöcke
       #grid {cols=2 gap=16}
       #col … #endcol
       #endgrid
       (Nur in HTML-Container umschreiben; Markdown danach global)
    --------------------------*/
    private function processLayoutBlocks(string $src): string
    {
        // #grid {…} -> <div class="fk-grid fk-cols-X" data-gap="…">
        $src = preg_replace_callback(
            '/^#grid\s*\{([^}]*)\}\s*$/m',
            function ($m) {
                $a     = $this->parseInlineAttrs($m[1]);
                $cols  = isset($a['cols']) ? max(1, (int) $a['cols']) : 1;
                $gap   = isset($a['gap'])  ? (string) $a['gap'] : '';
                $gapAttr = $gap !== '' ? ' data-gap="' . htmlspecialchars($gap, ENT_QUOTES) . '"' : '';
                return '<div class="fk-grid fk-cols-' . $cols . '"' . $gapAttr . '>';
            },
            $src
        );

        // #col             -> <div class="fk-col">
        $src = preg_replace('/^#col\s*$/m', '<div class="fk-col">', $src);

        // #endcol / #endgrid -> </div>
        $src = preg_replace('/^#endcol\s*$/m', '</div>', $src);
        $src = preg_replace('/^#endgrid\s*$/m', '</div>', $src);

        return $src;
    }

    /* -------------------------
       Bilder: #img {src=... width=...}
    --------------------------*/
    private function processImg(string $s, array $c): string
    {
        return (string) preg_replace_callback(
            '/#img\s*\{([^}]*)\}/',
            function ($m) {
                $a   = $this->parseInlineAttrs($m[1]);
                $src = $a['src'] ?? '';
                if ($src === '') return '';
                $src = esc_url_raw($src);
                $w   = isset($a['width']) ? ' width="' . htmlspecialchars($a['width'], ENT_QUOTES) . '"' : '';
                $h   = isset($a['height']) ? ' height="' . htmlspecialchars($a['height'], ENT_QUOTES) . '"' : '';
                $alt = isset($a['alt']) ? ' alt="' . htmlspecialchars($a['alt'], ENT_QUOTES) . '"' : ' alt=""';
                return '<img src="' . esc_url($src) . '"' . $w . $h . $alt . ' />';
            },
            $s
        );
    }

    /* -------------------------
       Felder: #field name [type=… label=… required …]
       Unterstützt u. a. type=date|time|number|email|textarea
    --------------------------*/
    private function processFields(string $s, array $c): string
    {
        return (string) preg_replace_callback(
            '/#field\s+([a-zA-Z0-9_.\-]+)([^\n]*)/',
            function ($m) use ($c) {
                $name  = $m[1];
                $attrs = trim($m[2] ?? '');
                $p     = $this->parseAttrs($attrs);

                $type  = $p['type']  ?? 'text';
                $label = $p['label'] ?? ucfirst(str_replace('_', ' ', $name));

                $required    = array_key_exists('required', $p) ? ' required' : '';
                $placeholder = isset($p['placeholder']) ? ' placeholder="' . htmlspecialchars((string)$p['placeholder'], ENT_QUOTES) . '"' : '';
                $min         = isset($p['min'])  ? ' min="'  . htmlspecialchars((string)$p['min'],  ENT_QUOTES) . '"' : '';
                $max         = isset($p['max'])  ? ' max="'  . htmlspecialchars((string)$p['max'],  ENT_QUOTES) . '"' : '';
                $step        = isset($p['step']) ? ' step="' . htmlspecialchars((string)$p['step'], ENT_QUOTES) . '"' : '';

                $value = htmlspecialchars((string)($this->get($c, $name) ?? ''), ENT_QUOTES);
                $id    = 'fk_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);

                $html  = '<label for="' . $id . '">' . htmlspecialchars($label) . '</label>' . "\n";
                if ($type === 'textarea') {
                    $html .= '<textarea id="' . $id . '" name="' . $name . '"' . $required . $placeholder . '>' . $value . '</textarea>';
                } else {
                    // Erlaubte Typen explizit whitelisten; unbekannte -> text
                    $allowed = ['text','email','date','time','number','url','tel','password','hidden'];
                    if (!in_array($type, $allowed, true)) $type = 'text';
                    $html .= '<input type="' . htmlspecialchars($type, ENT_QUOTES) . '" id="' . $id . '" name="' . $name . '" value="' . $value . '"' . $required . $placeholder . $min . $max . $step . ' />';
                }
                return $html;
            },
            $s
        );
    }

    /* -------------------------
       Variablen + Filter: {{ path | filter:arg }}
    --------------------------*/
    private function processVars(string $s, array $c): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([^\|\}]+?)\s*(?:\|\s*([a-zA-Z0-9_]+)\s*(?::\s*([^}]+))?)?\s*\}\}/',
            function ($m) use ($c) {
                $path   = trim($m[1]);
                $filter = $m[2] ?? null;
                $argRaw = $m[3] ?? null;

                $val = $this->get($c, $path);
                if ($filter) {
                    $arg = null;
                    if ($argRaw !== null) {
                        $arg = trim($argRaw);
                        $arg = trim($arg, "\"'"); // Quotes außen entfernen
                    }
                    $val = $this->filters->apply($filter, $val, $arg);
                }
                return htmlspecialchars((string)($val ?? ''), ENT_QUOTES);
            },
            $s
        );
    }

    /* -------------------------
       Minimaler Markdown-Block-Renderer
    --------------------------*/
    private function markdownToHtml(string $md): string
    {
        $lines = preg_split("/\r?\n/", $md) ?: [];
        $html  = [];
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s*(.+)$/', $line, $m)) {
                $lvl   = strlen($m[1]);
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
        // Links [text](url)
        $t = (string) preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $t);
        // Fett **text**
        $t = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
        // Kursiv *text*
        $t = (string) preg_replace('/(^|[^*])\*([^*\n]+)\*/', '\\1<em>\\2</em>', $t);
        return $t;
    }

    /* -------------------------
       Utilities
    --------------------------*/
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
        // bool flags
        if (preg_match_all('/\s([a-zA-Z0-9_-]+)(?=\s|$)/', $attrs, $flags)) {
            foreach ($flags[1] as $f) if (!isset($out[$f])) $out[$f] = true;
        }
        return $out;
    }

    private function parseInlineAttrs(string $raw): array
    {
        $out = [];
        if (preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s}]+))/', $raw, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out[$m[1]] = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : $m[4]);
            }
        }
        return $out;
    }

    private function get(array|object $ctx, string $path): mixed
    {
        $parts = explode('.', $path);
        $cur   = $ctx;
        foreach ($parts as $p) {
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
}
