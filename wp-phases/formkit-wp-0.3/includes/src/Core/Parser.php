<?php
declare(strict_types=1);
namespace FormKit\Core;

final class Parser {
    public static function loadTemplate(string $templatePath, ?string $partialsDir = null): string {
        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Template not found: {$templatePath}");
        }
        return self::resolveIncludes($content, $partialsDir);
    }

    private static function resolveIncludes(string $content, ?string $partialsDir): string {
        $pattern = '/\{\%\s*include\s+"([^"]+)"\s*\%\}/';
        return (string) preg_replace_callback($pattern, function(array $m) use ($partialsDir) {
            $name = $m[1];
            if ($partialsDir) {
                $path = rtrim($partialsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.mde';
                if (is_file($path)) {
                    $inc = file_get_contents($path);
                    if ($inc !== false) {
                        return self::resolveIncludes($inc, $partialsDir);
                    }
                }
            }
            return '';
        }, $content);
    }
}
