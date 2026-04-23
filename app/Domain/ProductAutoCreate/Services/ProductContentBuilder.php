<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Phase 6 Plan 01 — ProductContentBuilder (D-01, D-02).
 *
 * Single public entry-point: compile(array $supplierData): array returning
 * ['title', 'slug', 'meta_description', 'short_description', 'long_description'].
 *
 * Shortcodes honoured (per D-02):
 *   brand         → {{ $brand_name }}
 *   model         → {{ $model_name }}   (derived from supplier `name` if no
 *                   explicit model — splits off the brand prefix)
 *   product_type  → {{ $product_type }} (from supplier `category`)
 *   overview      → {{ $supplier_overview }} (optional)
 *   features[]    → {{ $supplier_features }} (optional)
 *   specs[label→v]→ {{ $supplier_specs }} (optional)
 *   box_contents[]→ {{ $supplier_box_contents }} (optional)
 *
 * Blade template lives at resources/views/product-auto-create/seo-template.blade.php.
 * Missing sections produce zero output via @if(!empty(...)) guards so the
 * long_description never contains "empty headers" (D-01 requirement).
 *
 * Meta description truncation: > 160 chars appends ellipsis (…) per D-01.
 *
 * Pure — no DB reads, no HTTP calls. Pure Blade compile + string math.
 */
final class ProductContentBuilder
{
    private const META_DESC_MAX = 160;

    /**
     * Compile the SEO template for a single supplier row.
     *
     * @param  array<string, mixed>  $supplierData  decoded supplier row (see probe output for shape)
     * @return array{title: string, slug: string, meta_description: string, short_description: string, long_description: string}
     */
    public function compile(array $supplierData): array
    {
        $variables = $this->buildTemplateVariables($supplierData);

        $view = View::make('product-auto-create.seo-template', $variables);
        $sections = $view->renderSections();

        $title = trim($sections['title'] ?? '');
        $shortDescription = trim($sections['short_description'] ?? '');
        $longDescription = trim($sections['long_description'] ?? '');

        $slug = Str::slug($title);
        $metaDescription = $this->buildMetaDescription($variables);

        return [
            'title' => $title,
            'slug' => $slug,
            'meta_description' => $metaDescription,
            'short_description' => $shortDescription,
            'long_description' => $longDescription,
        ];
    }

    /**
     * Map the raw supplier row to the Blade variable names per D-02.
     *
     * Missing keys are mapped to safe defaults (empty strings / empty arrays)
     * so the template's @if(!empty(...)) guards short-circuit cleanly.
     *
     * @param  array<string, mixed>  $supplierData
     * @return array<string, mixed>
     */
    private function buildTemplateVariables(array $supplierData): array
    {
        $brand = (string) ($supplierData['brand'] ?? '');
        $name = (string) ($supplierData['name'] ?? '');
        $category = (string) ($supplierData['category'] ?? '');
        $model = $this->deriveModelName($name, $brand);

        return [
            'brand_name' => $brand,
            'model_name' => $model,
            'product_type' => $category,
            'supplier_overview' => (string) ($supplierData['overview'] ?? ''),
            'supplier_features' => is_array($supplierData['features'] ?? null)
                ? array_values($supplierData['features'])
                : [],
            'supplier_specs' => is_array($supplierData['specs'] ?? null)
                ? (array) $supplierData['specs']
                : [],
            'supplier_box_contents' => is_array($supplierData['box_contents'] ?? null)
                ? array_values($supplierData['box_contents'])
                : [],
            'short_tagline' => (string) ($supplierData['short_tagline'] ?? ''),
            'cta' => (string) config(
                'product_auto_create.cta',
                'Shop now at meetingstore.co.uk'
            ),
        ];
    }

    /**
     * Strip the brand prefix from the supplier name to derive a model name.
     * If supplier name = "Logitech MeetUp Video Conferencing System" and brand
     * = "Logitech", the model becomes "MeetUp Video Conferencing System".
     * Falls back to the full supplier name when no prefix match.
     */
    private function deriveModelName(string $name, string $brand): string
    {
        if ($name === '') {
            return '';
        }
        if ($brand !== '' && stripos($name, $brand) === 0) {
            return trim(substr($name, strlen($brand)));
        }

        return $name;
    }

    /**
     * D-01 formula: "{brand} {model} — {short_tagline}. {cta}" (≤160 chars).
     * Ellipsis appended when truncated.
     *
     * @param  array<string, mixed>  $v  Blade variables from buildTemplateVariables
     */
    private function buildMetaDescription(array $v): string
    {
        $brand = (string) ($v['brand_name'] ?? '');
        $model = (string) ($v['model_name'] ?? '');
        $tagline = (string) ($v['short_tagline'] ?? '');
        $cta = (string) ($v['cta'] ?? '');

        $leading = trim($brand.' '.$model);
        $parts = [];
        if ($leading !== '') {
            $parts[] = $leading;
        }
        if ($tagline !== '') {
            $parts[] = '— '.$tagline;
        }
        $base = implode(' ', $parts);

        if ($cta !== '') {
            $base = rtrim($base, '.').'. '.$cta;
        }

        if (mb_strlen($base) <= self::META_DESC_MAX) {
            return $base;
        }

        // Reserve 1 char for the ellipsis.
        return mb_substr($base, 0, self::META_DESC_MAX - 1).'…';
    }
}
