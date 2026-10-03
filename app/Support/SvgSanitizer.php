<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SvgSanitizer
{
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'use', 'symbol', 'pattern', 'style', 'image', 'marker', 'a',
    ];

    private const URL_ATTRIBUTES = ['href', 'xlink:href'];

    public static function storeImage(UploadedFile $file, string $directory, string $field = 'logo'): string
    {
        if (! self::looksLikeSvg($file)) {
            return $file->store($directory, 'public');
        }

        $clean = self::sanitize((string) file_get_contents($file->getRealPath()));
        if ($clean === null) {
            throw ValidationException::withMessages([$field => 'Die SVG-Datei ist ungültig oder enthält nicht erlaubte Inhalte.']);
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.svg';
        Storage::disk('public')->put($path, $clean);

        return $path;
    }

    public static function looksLikeSvg(UploadedFile $file): bool
    {
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4096);

        return stripos($head, '<svg') !== false;
    }

    public static function sanitize(string $svg): ?string
    {
        if (str_contains($svg, '<!ENTITY')) {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->documentElement;
        if (! $loaded || ! $root || strtolower($root->localName) !== 'svg') {
            return null;
        }

        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child->nodeType === XML_DOCUMENT_TYPE_NODE || $child->nodeType === XML_PI_NODE) {
                $dom->removeChild($child);
            }
        }

        self::cleanAttributes($root);
        self::cleanNode($root);

        $out = $dom->saveXML($root);

        return $out === false ? null : $out;
    }

    private static function cleanNode(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $node->removeChild($child);

                    continue;
                }
                self::cleanAttributes($child);
                if (strtolower($child->localName) === 'style' && self::isDangerousCss($child->textContent)) {
                    $node->removeChild($child);

                    continue;
                }
                self::cleanNode($child);
            } elseif ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->value;

            if (str_starts_with($name, 'on')) {
                $el->removeAttributeNode($attr);
            } elseif (in_array($name, self::URL_ATTRIBUTES, true)) {
                $v = strtolower(trim(preg_replace('/\s+/', '', $value)));
                if (! str_starts_with($v, '#') && ! preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $v)) {
                    $el->removeAttributeNode($attr);
                }
            } elseif ($name === 'style' && self::isDangerousCss($value)) {
                $el->removeAttributeNode($attr);
            } elseif (preg_match('/javascript:|data:text|<|expression\(/i', $value)) {
                $el->removeAttributeNode($attr);
            }
        }
    }

    private static function isDangerousCss(string $css): bool
    {
        return (bool) preg_match('/@import|javascript:|expression\(|behavior:|-moz-binding|url\(\s*[\'"]?\s*(?!#|data:image\/(png|jpe?g|gif|webp))/i', $css);
    }
}
