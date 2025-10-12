# FormKit Core (Phase 0.1)

Minimal lauffähige Referenz-Implementation für:
- Parser (Direktiven: #field, #if, #loop, #include)
- Evaluator (Variablen {{ path }}, Filter | number/date/upper/lower)
- RendererInterface (Web, Email, PDF – PDF als Stub)
- Beispiele: `examples/contact.mde` + `examples/contact.json`
- Runner: `bin/render.php`

> Ziel: MarkdownExtended (MDE) → HTML als Basis für WordPress-Plugin.

## Nutzung (CLI)
```bash
php bin/render.php examples/contact.mde examples/contact.json > out.html
```

## Einschränkungen (MVP)
- Markdown-Parser ist minimal (Überschriften, **bold**, *italic*, Links).
- Direktiven-Syntax: 
  - `#if path` … `#endif`
  - `#loop path` … `#endloop` (Loop-Variable: `item`)
  - `#field name type="text" label="..." required`
  - `{% include "name" %}` lädt aus `partials/name.mde` (falls vorhanden)
- Filter: `number[:decimals]`, `date[:Y-m-d]`, `upper`, `lower`.

Diese Version ist bewusst klein gehalten und dient als Startpunkt.
