<?php
declare(strict_types=1);
namespace FormKit\Core;

final class Validator {
    public static function extractRules(string $mde): array {
        $rules = [];
        if (preg_match_all('/#field\s+([a-zA-Z0-9_\.\-]+)([^\n]*)/m', $mde, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = $m[1];
                $attrs = $m[2] ?? '';
                $r = ['required'=>false,'type'=>'text'];
                if (preg_match_all('/([a-zA-Z0-9\-]+)\s*=\s*\"([^\"]*)\"/', $attrs, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $pair) { $r[$pair[1]] = $pair[2]; }
                }
                if (preg_match('/\srequired(\s|$)/', $attrs)) $r['required'] = true;
                $rules[$name] = $r;
            }
        }
        return $rules;
    }
    public static function validate(array $data, array $rules): array {
        $errors = [];
        foreach ($rules as $field => $r) {
            $val = $data[$field] ?? null;
            if (($r['required'] ?? false) && (is_null($val) || $val === '')) {
                $errors[$field][] = 'required';
            }
            $type = $r['type'] ?? 'text';
            if ($type === 'email' && !empty($val) && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $errors[$field][] = 'email';
            }
        }
        return $errors;
    }
}
