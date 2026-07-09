<?php

declare(strict_types=1);

namespace App\Domain\ProductAutoCreate\Services;

use App\Domain\Integrations\Clients\ClaudeClient;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Claude-vision gatekeeper for sourced product images.
 *
 * Given the processed WebP bytes of a candidate image and the product facts
 * (brand, model/MPN, title), asks Claude (via the sanctioned ClaudeClient —
 * budget logging + the one vision-capable Anthropic entry point) whether the
 * image is a clean, on-listing main image of the CORRECT product.
 *
 * Validation policy (operator decision 2026-05-24 — "reject only watermarks &
 * overlays"): ACCEPT a clean product shot even when the device itself shows
 * physical branding, model text, port labels or on-screen UI in a demo. REJECT
 * only: watermarks, overlaid promotional/price/website text, a competing
 * retailer's logo/badge, stock-photo watermarks, or a wrong/again-unrelated
 * product (accessory-only, box-only when the product isn't shown, lifestyle
 * collage where the product isn't clearly the subject).
 *
 * Returns a plain array (no DB, no storage) so the command stays the
 * orchestrator. Never throws — a vision error returns accept=false with a
 * reason so the caller moves to the next candidate.
 */
final class ProductImageVisionValidator
{
    public function __construct(
        private ClaudeClient $claude,
    ) {}

    /**
     * @param  string  $webpBytes  Processed (≤1200px) WebP image bytes.
     * @param  array{brand?:string, mpn?:string, title?:string, category?:string}  $product
     * @return array{accept: bool, reason: string, cost_pence: int, verdict: array<string, mixed>|null}
     */
    public function validate(string $webpBytes, array $product): array
    {
        $userMessage = new UserMessage(
            $this->userText($product),
            [Image::fromRawContent($webpBytes, 'image/webp')],
        );

        try {
            $resp = $this->claude->generate(
                systemPrompt: $this->systemPrompt(),
                messages: [$userMessage],
                maxTokens: 400,
                temperature: 0.0,
            );
        } catch (\Throwable $e) {
            return [
                'accept' => false,
                'reason' => 'vision call failed: '.$e->getMessage(),
                'cost_pence' => 0,
                'verdict' => null,
            ];
        }

        $verdict = $this->parseJson($resp->text);
        if ($verdict === null) {
            return [
                'accept' => false,
                'reason' => 'could not parse vision verdict',
                'cost_pence' => $resp->costPence,
                'verdict' => null,
            ];
        }

        // Strict AND: correct product, usable shot, and none of the reject
        // signals. Missing keys default to the rejecting value (fail closed).
        $accept = ($verdict['is_correct_product'] ?? false) === true
            && ($verdict['is_usable'] ?? false) === true
            && ($verdict['has_watermark'] ?? true) === false
            && ($verdict['has_overlay_text'] ?? true) === false
            && ($verdict['has_competitor_branding'] ?? true) === false;

        return [
            'accept' => $accept,
            'reason' => (string) ($verdict['reason'] ?? ''),
            'cost_pence' => $resp->costPence,
            'verdict' => $verdict,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a strict e-commerce image reviewer for Meeting Store (meetingstore.co.uk),
        a UK audio-visual / video-conferencing retailer. You judge whether a single candidate
        image is suitable as the MAIN product photo for a specific product.

        You are given the expected product (brand, model/MPN, title) and ONE image. Decide:

        1. is_correct_product — does the image clearly show THIS product (matching brand/model/type)?
           Reject if it is a different product, an accessory only, a bare retail box when the
           product itself is not shown, or an unrelated/lifestyle image where the product is not
           the clear subject.
        2. is_usable — is it a clean, professional product shot suitable as a main listing image
           (product clearly visible, reasonable framing, not a tiny thumbnail, not a collage)?
        3. has_watermark — is there a watermark or semi-transparent stamp overlaid on the image?
        4. has_overlay_text — is there text ADDED ON TOP of the image (promotional text, prices,
           "SALE", a website/URL, arrows/callouts)? IMPORTANT: text that is PHYSICALLY PART of the
           product is NOT overlay text — e.g. the brand printed on the device, the model name on
           the bezel, port labels, or content shown on the product's own screen in a demo. Those
           are fine; do NOT flag them.
        5. has_competitor_branding — does it carry another retailer's logo, watermark, or badge
           (i.e. it was lifted from a competitor's site)?

        Return ONLY a single valid JSON object — no prose, no markdown fences — with EXACTLY these keys:
          "is_correct_product": boolean,
          "is_usable": boolean,
          "has_watermark": boolean,
          "has_overlay_text": boolean,
          "has_competitor_branding": boolean,
          "confidence": number between 0 and 1,
          "reason": a short (max ~20 words) explanation of the decision.

        Be conservative: when genuinely unsure whether the product matches, set is_correct_product false.
        PROMPT;
    }

    /**
     * @param  array{brand?:string, mpn?:string, title?:string, category?:string}  $product
     */
    private function userText(array $product): string
    {
        $facts = [
            'brand' => (string) ($product['brand'] ?? ''),
            'model_mpn' => (string) ($product['mpn'] ?? ''),
            'title' => (string) ($product['title'] ?? ''),
            'category' => (string) ($product['category'] ?? ''),
        ];

        return "Expected product:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ."\n\nReview the attached image and return the JSON verdict.";
    }

    /**
     * Tolerant JSON extraction (strips code fences / stray prose).
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $text);

        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $text, $mm) === 1) {
            $decoded = json_decode($mm[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
