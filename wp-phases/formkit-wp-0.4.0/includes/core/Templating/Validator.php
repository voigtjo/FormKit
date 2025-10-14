<?php
declare(strict_types=1);

namespace FormKit\Core\Templating;

final class Validator
{
    /**
     * Extrahiert Feldregeln aus #field-Direktiven.
     * Unterstützt:
     *  - required Flag
     *  - type=\"email\"
     *  - rules=\"required|iban|bic|email\"
     */
    public static function extractRules(string $mde): array
    {
        $rules = [];

        if (preg_match_all('/#field\s+([a-zA-Z0-9_.\-\[\]]+)([^\n]*)/', $mde, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $name  = $m[1];
                $attrs = $m[2] ?? '';
                $fieldRules = [];

                // required Flag
                if (preg_match('/\brequired\b/', $attrs)) {
                    $fieldRules[] = 'required';
                }

                // type=\"email\" -> email
                if (preg_match('/\btype\s*=\s*\"(email)\"/', $attrs)) {
                    $fieldRules[] = 'email';
                }

                // rules=\"required|iban|...\"
                if (preg_match('/\brules\s*=\s*\"([^\"]+)\"/', $attrs, $rm)) {
                    $parts = array_filter(array_map('trim', explode('|', $rm[1])));
                    foreach ($parts as $r) {
                        $fieldRules[] = $r;
                    }
                }

                $rules[$name] = array_values(array_unique($fieldRules));
            }
        }

        return $rules;
    }

    /**
     * Führt Validierung anhand der extrahierten Regeln aus.
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $rs) {
            $value = $data[$field] ?? null;

            // required
            if (in_array('required', $rs, true)) {
                if ($value === null || $value === '' || $value === []) {
                    $errors[$field][] = 'required';
                }
            }

            // email
            if (in_array('email', $rs, true) && !empty($value)) {
                if (!function_exists('is_email')) {
                    // Fallback: einfache Emailprüfung
                    $ok = (bool)preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string)$value);
                } else {
                    $ok = (bool)is_email((string)$value);
                }
                if (!$ok) {
                    $errors[$field][] = 'email';
                }
            }

            // iban
            if (in_array('iban', $rs, true) && !empty($value)) {
                if (!self::rule_iban((string)$value)) {
                    $errors[$field][] = 'iban';
                }
            }

            // bic
            if (in_array('bic', $rs, true) && !empty($value)) {
                if (!self::rule_bic((string)$value)) {
                    $errors[$field][] = 'bic';
                }
            }
        }

        return $errors;
    }

    /* -----------------------------
       Einzelregeln
    ------------------------------*/
    private static function rule_iban(string $value): bool
    {
        $v = preg_replace('/\s+/', '', strtoupper($value));
        if (!preg_match('/^[A-Z]{2}[0-9A-Z]{13,30}$/', $v)) return false;

        $moved = substr($v, 4) . substr($v, 0, 4);
        $num = preg_replace_callback('/[A-Z]/', fn($m) => (string)(ord($m[0]) - 55), $moved);

        $mod = 0;
        foreach (str_split($num, 7) as $chunk) {
            $mod = (int)(($mod . $chunk) % 97);
        }
        return $mod === 1;
    }

    private static function rule_bic(string $value): bool
    {
        return (bool)preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper($value));
    }
}
