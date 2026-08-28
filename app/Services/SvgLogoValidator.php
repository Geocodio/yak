<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * Allowlist-based validator for uploaded SVG logos.
 *
 * The theme logo is served unauthenticated from the app's own origin
 * (see App\Http\Controllers\VideoThemeAssetController::logo()), so a
 * user who is tricked into navigating directly to the logo URL would
 * have any script embedded in an uploaded SVG execute with the app's
 * origin. This validator rejects any SVG that could carry an XSS
 * payload before it is ever stored.
 *
 * PNG uploads are not affected by this class at all — PNG is a raster
 * format with no script execution surface, so no PNG-specific
 * validation is needed or provided here.
 *
 * Usage from a Livewire `rules()` closure or a custom validation rule:
 *
 *     'logo' => ['file', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
 *         if ($value instanceof \Illuminate\Http\UploadedFile
 *             && $value->getClientOriginalExtension() === 'svg'
 *             && ! app(SvgLogoValidator::class)->isSafe($value->get())) {
 *             $fail('The logo SVG contains disallowed content.');
 *         }
 *     }],
 */
class SvgLogoValidator
{
    /**
     * Determine whether the given SVG bytes are safe to store and serve.
     *
     * Rejects:
     * - anything that does not parse as well-formed XML,
     * - any <script> element (any namespace),
     * - any attribute whose name starts with "on" (case-insensitive),
     * - any <foreignObject> element,
     * - any href / xlink:href attribute whose value is not a pure
     *   "#fragment" reference,
     * - any SMIL animation element (<animate>, <set>, <animateMotion>,
     *   <animateTransform>) whose `attributeName` targets `href`,
     *   `xlink:href`, or any `on*` handler — SMIL can mutate those at
     *   runtime (e.g. animating `xlink:href` to a `javascript:` URI),
     *   bypassing a static check of the literal attribute values alone.
     *
     * Not rejected, by design: element names are matched case-sensitively
     * (e.g. `<SCRIPT>`/`<FOREIGNOBJECT>` pass through) — this is not a
     * real vector because SVG is namespaced XML and no browser recognizes
     * an uppercase local name as the `script`/`foreignObject` element, it
     * is just inert markup. `<handler>` (SVG Tiny 1.2's scripting
     * element) is also not checked for the same reason: no modern
     * browser implements SVG Tiny 1.2 handler execution.
     */
    public function isSafe(string $contents): bool
    {
        $document = $this->parse($contents);

        if ($document === null) {
            return false;
        }

        $xpath = new DOMXPath($document);

        // Reject any <script> element regardless of namespace.
        $scripts = $xpath->query('//*[local-name()="script"]');
        if ($scripts === false || $scripts->length > 0) {
            return false;
        }

        // Reject any <foreignObject> element regardless of namespace.
        $foreignObjects = $xpath->query('//*[local-name()="foreignObject"]');
        if ($foreignObjects === false || $foreignObjects->length > 0) {
            return false;
        }

        if (! $this->smilAnimationsAreSafe($xpath)) {
            return false;
        }

        return $this->walk($document);
    }

    /**
     * Reject any SMIL animation element that targets `href`, `xlink:href`,
     * or an `on*` event-handler attribute via its `attributeName`. SMIL
     * (<animate>, <set>, <animateMotion>, <animateTransform>) can mutate a
     * target attribute at runtime — e.g. animating `xlink:href` on an
     * anchor to a `javascript:` URI — so the literal `href`/`on*` checks
     * in `attributesAreSafe()` alone are not enough; the animation
     * elements themselves must be rejected outright.
     */
    private function smilAnimationsAreSafe(DOMXPath $xpath): bool
    {
        $animations = $xpath->query(
            '//*[local-name()="animate" or local-name()="set" ' .
            'or local-name()="animateMotion" or local-name()="animateTransform"]'
        );

        if ($animations === false) {
            return false;
        }

        foreach ($animations as $animation) {
            if (! $animation instanceof DOMElement) {
                continue;
            }

            $target = strtolower(trim($animation->getAttribute('attributeName')));

            if ($target === 'href' || $target === 'xlink:href' || str_starts_with($target, 'on')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse SVG bytes into a DOMDocument with external entity loading
     * disabled, returning null when the input is not well-formed XML.
     *
     * Entity substitution is never enabled: we do not pass LIBXML_NOENT,
     * and we disable the external entity loader for the duration of the
     * parse so DOCTYPE-declared external entities cannot be resolved
     * (guards against XXE as a side effect of accepting XML uploads).
     */
    private function parse(string $contents): ?DOMDocument
    {
        if (trim($contents) === '') {
            return null;
        }

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        libxml_set_external_entity_loader(
            static fn (): null => null,
        );
        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            // Restore the default (no-op) loader rather than passing back
            // whatever was previously registered, since libxml does not
            // expose a way to read the current loader.
            libxml_set_external_entity_loader(null);
            libxml_use_internal_errors($previousUseErrors);
        }

        if ($loaded === false || $document->documentElement === null) {
            return null;
        }

        if (strtolower($document->documentElement->localName ?? '') !== 'svg') {
            return null;
        }

        return $document;
    }

    /**
     * Walk every element and attribute in the document, rejecting any
     * `on*` event-handler attribute and any non-fragment href / xlink:href.
     */
    private function walk(DOMNode $node): bool
    {
        if ($node instanceof DOMElement) {
            if (! $this->attributesAreSafe($node)) {
                return false;
            }
        }

        foreach ($node->childNodes as $child) {
            if (! $this->walk($child)) {
                return false;
            }
        }

        return true;
    }

    private function attributesAreSafe(DOMElement $element): bool
    {
        if (! $element->hasAttributes()) {
            return true;
        }

        foreach ($element->attributes as $attribute) {
            $localName = strtolower($attribute->localName ?? $attribute->name);

            if (str_starts_with($localName, 'on')) {
                return false;
            }

            if ($localName === 'href' && ! $this->isFragmentOnly($attribute->value)) {
                return false;
            }
        }

        return true;
    }

    private function isFragmentOnly(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && $value[0] === '#';
    }
}
