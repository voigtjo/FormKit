<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FormKit\Core\Parser;
use FormKit\Core\Evaluator;
use FormKit\Core\Filters;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/render.php <template.mde> <context.json>\n");
    exit(1);
}

$templatePath = $argv[1];
$contextPath  = $argv[2];
$partialsDir  = dirname(__DIR__) . '/partials';

$template = Parser::loadTemplate($templatePath, $partialsDir);
$context  = json_decode(file_get_contents($contextPath), true, flags: JSON_THROW_ON_ERROR);

$ev = new Evaluator(new Filters());
$html = $ev->render($template, $context);

echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>FormKit Render</title>";
echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
echo "<style>body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.5;padding:2rem;max-width:860px;margin:auto}label{display:block;margin:.5rem 0 .25rem}input,textarea{width:100%;padding:.5rem;border:1px solid #ccc;border-radius:4px}h1,h2,h3{margin-top:1.2em}p{margin:.6em 0}</style></head><body>";
echo $html;
echo "</body></html>";
