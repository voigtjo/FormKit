<?php
declare(strict_types=1);

namespace FormKit\Core;

final class Parser
{
    /** 
     * Preprocess includes and return raw template string. 
     * Resolves {% include "name" %} from $partialsDir/name.mde when available.
     */
    public static function loadTemplate(string $templatePath, ?string $partialsDir = null): string
    {
        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Template not found: $templatePath");
        }
        return self::resolveIncludes($content, $partialsDir);
    }

    private static function resolveIncludes(string $content, ?string $partialsDir): string
    {
        $pattern = '/\{\%\s*include\s+"([^"]+)"\s*\%\}/';
        return preg_replace_callback($pattern, function ($m) use ($partialsDir) {
            $name = $m[1];
            if ($partialsDir) {
                $path = rtrim($partialsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.mde';
                if (is_file($path)) {
                    $inc = file_get_contents($path);
                    if ($inc !== false) {
                        // Recursively resolve nested includes
                        return self::resolveIncludes($inc, $partialsDir);
                    }
                }
            }
            return ''; // gracefully degrade
        }, $content);
    }
}
