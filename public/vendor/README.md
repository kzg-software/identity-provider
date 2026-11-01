# Lokale Frontend-Abhängigkeiten

Diese Dateien werden bewusst mitgeliefert, damit die Anwendung ohne externe
CDN funktioniert (Air-Gapped-Betrieb, keine Requests an fremde Hosts). Die
Blade-Layouts binden sie über `asset('vendor/...')` ein.

| Ordner | Inhalt | Version | Quelle |
|---|---|---|---|
| `tailwindcss/` | Tailwind Play CDN (JIT im Browser, kein Build nötig) | 3.4.16 | `https://cdn.tailwindcss.com/3.4.16` |
| `alpinejs/` | Alpine.js | 3.14.1 | `https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js` |
| `fonts/` | Schrift Figtree (SIL OFL 1.1), nur `woff2` | – | `fonts.bunny.net` |
| `drawflow/` | Drawflow (MIT), Flow-Editor für die Workflows, nur im Admin-Editor geladen | 0.0.60 | `https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/` |

## Aktualisieren

```bash
cd public/vendor
curl -sSL "https://cdn.tailwindcss.com/<version>" -o tailwindcss/tailwindcss-<version>.js
curl -sSL "https://cdn.jsdelivr.net/npm/alpinejs@<version>/dist/cdn.min.js" -o alpinejs/alpinejs-<version>.min.js
```

Danach die Dateinamen in `resources/views/layouts/app.blade.php` anpassen und
die alte Datei löschen.
