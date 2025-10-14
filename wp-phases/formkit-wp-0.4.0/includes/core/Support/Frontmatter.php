<?php
namespace FormKit\Core\Support;
final class Frontmatter {
    public static function split(string $src): array {
        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s',$src,$m)) return [self::parse(trim($m[1])), $m[2]];
        return [[], $src];
    }
    private static function parse(string $s): array {
        $out=[]; foreach (preg_split('/\R/',$s) as $line) {
            if (strpos($line,':')!==false){ [$k,$v]=array_map('trim',explode(':',$line,2)); $out[$k]=$v; }
        } return $out;
    }
}
