<?php
declare(strict_types=1);

namespace FormKit\Core;

final class Evaluator
{
    public function __construct(private Filters $filters) {}

    public function render(string $mde, array $context = []): string
    {
        // 1) Process control structures: if / loop
        $mde = $this->processIfBlocks($mde, $context);
        $mde = $this->processLoops($mde, $context);
        // 2) Process #field to HTML inputs (simple MVP)
        $mde = $this->processFields($mde, $context);
        // 3) Replace variables {{ path | filter:arg }}
        $mde = $this->processVariables($mde, $context);
        // 4) Convert minimal markdown to HTML
        return $this->markdownToHtml($mde);
    }

    private function processIfBlocks(string $src, array $ctx): string
    {
        $pattern = '/#if\s+([^\n]+)\n(.*?)#endif/s';
        return preg_replace_callback($pattern, function ($m) use ($ctx) {
            $path = trim($m[1]);
            $inner = $m[2];
            $val = $this->getByPath($ctx, $path);
            $truthy = !empty($val);
            return $truthy ? $inner : '';
        }, $src);
    }

    private function processLoops(string $src, array $ctx): string
    {
        $pattern = '/#loop\s+([^\n]+)\n(.*?)#endloop/s';
        return preg_replace_callback($pattern, function ($m) use ($ctx) {
            $path = trim($m[1]);
            $inner = $m[2];
            $arr = $this->getByPath($ctx, $path);
            if (!is_iterable($arr)) return '';
            $out = '';
            foreach ($arr as $item) {
                $local = ['item' => $item] + $ctx;
                $out .= $this->processVariables($inner, $local);
                // Allow nested loops/ifs on each iteration:
                $out = $this->processIfBlocks($out, $local);
                $out = $this->processLoops($out, $local);
                $out = $this->processFields($out, $local);
            }
            return $out;
        }, $src);
    }

    private function processFields(string $src, array $ctx): string
    {
        // Syntax: #field name type="text" label="Your name" required
        $pattern = '/#field\s+([a-zA-Z0-9_\.\-]+)([^\n]*)/';
        return preg_replace_callback($pattern, function ($m) use ($ctx) {
            $name = $m[1];
            $attrs = trim($m[2] ?? '');
            $parsed = $this->parseFieldAttrs($attrs);
            $type = $parsed['type'] ?? 'text';
            $label = $parsed['label'] ?? ucfirst(str_replace('_', ' ', $name));
            $required = array_key_exists('required', $parsed) ? ' required' : '';
            $value = htmlspecialchars((string)$this->getByPath($ctx, $name) ?? '', ENT_QUOTES);
            $id = 'fk_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);

            $html = "<label for=\"$id\">".htmlspecialchars($label)."</label>\n";
            if ($type === 'textarea') {
                $html .= "<textarea id=\"$id\" name=\"$name\"$required>$value</textarea>";
            } else {
                $html .= "<input type=\"$type\" id=\"$id\" name=\"$name\" value=\"$value\"$required />";
            }
            return $html;
        }, $src);
    }

    private function parseFieldAttrs(string $attrs): array
    {
        $out = [];
        // key="value"
        if (preg_match_all('/([a-zA-Z0-9_\-]+)\s*=\s*"([^"]*)"/', $attrs, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out[$m[1]] = $m[2];
            }
        }
        // standalone flags (e.g., required)
        if (preg_match_all('/\s([a-zA-Z0-9_\-]+)(?=\s|$)/', $attrs, $flags)) {
            foreach ($flags[1] as $flag) {
                if (!isset($out[$flag])) {
                    $out[$flag] = true;
                }
            }
        }
        return $out;
    }

    private function processVariables(string $src, array $ctx): string
    {
        $pattern = '/\{\{\s*([^\|\}]+?)\s*(?:\|\s*([a-zA-Z0-9_]+)\s*(?::\s*([^}]+))?)?\s*\}\}/';
        return preg_replace_callback($pattern, function ($m) use ($ctx) {
            $path = trim($m[1]);
            $filter = $m[2] ?? null;
            $argRaw = $m[3] ?? null;
            $val = $this->getByPath($ctx, $path);
            if ($filter) {
                $arg = null;
                if ($argRaw !== null) {
                    $arg = trim($argRaw);
                    $arg = trim($arg, '"\'');
                }
                $val = $this->filters->apply($filter, $val, $arg);
            }
            return htmlspecialchars((string)($val ?? ''), ENT_QUOTES);
        }, $src);
    }

    private function markdownToHtml(string $md): string
    {
        $lines = preg_split("/\r?\n/", $md);
        $html = [];
        foreach ($lines as $line) {
            // Headings: #, ##, ###
            if (preg_match('/^(#{1,6})\s*(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = $m[2];
                $html[] = "<h$level>" . $this->inlineMd($text) . "</h$level>";
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $html[] = "<p>".$this->inlineMd($line)."</p>";
        }
        return implode("\n", $html);
    }

    private function inlineMd(string $t): string
    {
        // **bold**, *italic*, [text](url)
        $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
        $t = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $t);
        $t = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $t);
        return $t;
    }

    /** Get value by dot path from context array. */
    private function getByPath(array $ctx, string $path): mixed
    {
        $parts = explode('.', $path);
        $cur = $ctx;
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
