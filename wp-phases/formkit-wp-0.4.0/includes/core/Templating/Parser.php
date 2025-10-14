<?php
namespace FormKit\Core\Templating;

final class Parser
{
    public static function expandPartials(string $src, string $partialsDir): string
    {
        return (string) preg_replace_callback('/\{%\s*include\s+\"([^\"]+)\"\s*%\}/', function($m) use ($partialsDir){
            $p = rtrim($partialsDir,'/').'/'.$m[1].'.mde';
            return is_file($p) ? (file_get_contents($p) ?: '') : '';
        }, $src);
    }
}
