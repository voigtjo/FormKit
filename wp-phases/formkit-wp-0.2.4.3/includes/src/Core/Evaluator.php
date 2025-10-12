<?php
declare(strict_types=1);
namespace FormKit\Core;

final class Evaluator
{
    private Filters $filters;

    public function __construct(Filters $filters) { $this->filters = $filters; }

    public function render(string $mde, array $context = []): string
    {
        $mde = $this->processIfBlocks($mde, $context);
        $mde = $this->processLoops($mde, $context);
        $mde = $this->processFields($mde, $context);
        $mde = $this->processVariables($mde, $context);
        return $this->markdownToHtml($mde);
    }

    private function processIfBlocks(string $src, array $ctx): string
    {
        $pattern = '/#if\s+([^\n]+)\n(.*?)#endif/s';
        return (string) preg_replace_callback($pattern, function ($m) use ($ctx) {
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
        return (string) preg_replace_callback($pattern, function ($m) use ($ctx) {
            $path = trim($m[1]);
            $inner = $m[2];
            $arr = $this->getByPath($ctx, $path);
            if (!is_iterable($arr)) return '';
            $out = '';
            foreach ($arr as $item) {
                $local = ['item' => $item] + $ctx;
                $chunk = $this->processVariables($inner, $local);
                $chunk = $this->processIfBlocks($chunk, $local);
                $chunk = $this->processLoops($chunk, $local);
                $chunk = $this->processFields($chunk, $local);
                $out .= $chunk;
            }
            return $out;
        }, $src);
    }

    private function processFields(string $src, array $ctx): string
    {
        $pattern = '/#field\s+([a-zA-Z0-9_\.\-]+)([^\n]*)/';
        return (string) preg_replace_callback($pattern, function ($m) use ($ctx) {
            $name = $m[1];
            $attrs = trim($m[2] ?? '');
            $parsed = $this->parseFieldAttrs($attrs);
            $type = $parsed['type'] ?? 'text';
            $label = $parsed['label'] ?? ucfirst(str_replace('_', ' ', $name));
            $required = array_key_exists('required', $parsed) ? ' required' : '';
            $value = htmlspecialchars((string)($this->getByPath($ctx, $name) ?? ''), ENT_QUOTES);
            $id = 'fk_' . preg_replace('/[^a-z0-9_]+/i', '_', $name);

            $html = '<label for="' . $id . '">' . htmlspecialchars($label) . '</label>' . "\n";
            if ($type === 'textarea') {
                $html .= '<textarea id="' . $id . '" name="' . $name . '"' . $required . '>' . $value . '</textarea>';
            } else if ($type === 'checkbox') {
                $checked = !empty($value) ? ' checked' : '';
                $html = '<label><input type="checkbox" name="' . $name . '" value="1"' . $required . $checked . ' /> ' . htmlspecialchars($label) . '</label>';
            } else {
                $html .= '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . $value . '"' . $required . ' />';
            }
            return $html;
        }, $src);
    }

    private function parseFieldAttrs(string $attrs): array
    {
        $out = [];
        if (preg_match_all('/([a-zA-Z0-9\-]+)\s*=\s*\"([^\"]*)\"/', $attrs, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) $out[$m[1]] = $m[2];
        }
        if (preg_match_all('/\s([a-zA-Z0-9\-]+)(?=\s|$)/', $attrs, $flags)) {
            foreach ($flags[1] as $flag) if (!isset($out[$flag])) $out[$flag] = true;
        }
        return $out;
    }

    private function processVariables(string $src, array $ctx): string
    {
        $pattern = '/\{\{\s*([^\|\}]+?)\s*(?:\|\s*([a-zA-Z0-9_]+)\s*(?::\s*([^}]+))?)?\s*\}\}/';
        return (string) preg_replace_callback($pattern, function ($m) use ($ctx) {
            $path = trim($m[1]);
            $filter = $m[2] ?? null;
            $argRaw = $m[3] ?? null;
            $val = $this->getByPath($ctx, $path);
            if ($filter) {
                $arg = null;
                if ($argRaw !== null) {
                    $arg = trim($argRaw);
                    $arg = trim($arg, '\"\'');
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
            if (preg_match('/^(#{1,6})\s*(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text = $m[2];
                $html[] = '<h' . $level . '>' . $this->inlineMd($text) . '</h' . $level . '>';
                continue;
            }
            if (trim($line) === '') continue;
            $html[] = '<p>'.$this->inlineMd($line).'</p>';
        }
        return implode("\n", $html);
    }

    private function inlineMd(string $t): string
    {
        $t = (string) preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2">$1</a>', $t);
        $t = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
        $t = (string) preg_replace('/(^|[^*])\*([^*\n]+)\*/', '\\1<em>\\2</em>', $t);
        return $t;
    }

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
