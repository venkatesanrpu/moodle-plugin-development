<?php
/**
 * Render helper: Markdown + Math-safe pipeline for AI responses.
 *
 * phase7_6d changes:
 *   - REMOVED clean_text() / HTMLPurifier — it encoded backslashes inside text
 *     nodes, destroying \( \[ MathJax delimiters before they reached the browser.
 *   - REPLACED with strip_tags() allowlist + manual on/javascript: scrub.
 *     strip_tags() does NOT touch text-node content → backslashes survive intact.
 *   - render() is now the single canonical rendering function used by both
 *     the live-chat path (render_response.php) and history pre-rendering
 *     (get_history.php). No second AJAX round-trip required.
 *
 * Pipeline:
 *   1. Strip <think>…</think> chain-of-thought blocks.
 *   2. Extract math spans → opaque placeholders (protect from Parsedown).
 *   3. Parsedown: Markdown → HTML.
 *   4. Restore math placeholders verbatim.
 *   5. strip_tags() with allowlist  (text nodes, incl. \( \[, UNTOUCHED).
 *   6. Regex scrub of on* event attrs and javascript:/data: href/src values.
 *
 * @package   block_ai_assistant_v2
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/Parsedown.php');

class render_helper {

    /**
     * Tags emitted by Parsedown that are safe to keep.
     * strip_tags() removes everything NOT in this list,
     * but leaves text-node content (including backslashes) untouched.
     */
    private const SAFE_TAGS =
        '<p><br><strong><b><em><i><u><s><del><ins>' .
        '<h1><h2><h3><h4><h5><h6>' .
        '<ul><ol><li><dl><dt><dd>' .
        '<blockquote><pre><code><kbd><samp><var>' .
        '<table><thead><tbody><tfoot><tr><th><td><caption>' .
        '<a><img><hr><sup><sub><mark><abbr><span><div>' .
        '<details><summary>';

    /**
     * Render raw LLM markdown (with LaTeX) into safe HTML.
     *
     * @param string $raw  Raw markdown text from LLM / DB botresponse column.
     * @return string      Sanitised HTML ready for innerHTML assignment.
     */
    public static function render(string $raw): string {
        if (trim($raw) === '') {
            return '';
        }

        // ── Step 1: strip chain-of-thought blocks ─────────────────────────
        $raw = preg_replace('/<think>[\s\S]*?<\/think>/is', '', $raw);

        // ── Step 2: extract and protect math expressions ──────────────────
        $placeholders = [];
        $counter      = 0;
        $protected    = self::extract_math($raw, $placeholders, $counter);

        // ── Step 3: Parsedown — Markdown → HTML ───────────────────────────
        $pd = new \Parsedown();
        $pd->setSafeMode(false); // XSS handled in steps 5-6 below.
        $html = $pd->text($protected);

        // ── Step 4: restore math placeholders verbatim ────────────────────
        if (!empty($placeholders)) {
            $html = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $html
            );
        }

        // ── Step 5: strip_tags() with allowlist ───────────────────────────
        // KEY FIX: strip_tags() does NOT encode text-node content.
        // HTMLPurifier (used by clean_text()) was entity-encoding backslashes
        // inside text nodes, turning \( into &#92;( and breaking MathJax.
        $html = strip_tags($html, self::SAFE_TAGS);

        // ── Step 6: scrub dangerous attribute patterns ────────────────────
        // Remove on* event handlers (onclick, onload, onerror, etc.)
        $html = preg_replace(
            '/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i',
            '',
            $html
        );
        // Neutralise javascript: and data: in href / src attributes.
        $html = preg_replace(
            '/(\s+(?:href|src)\s*=\s*["\']?)\s*(?:javascript|data)\s*:/i',
            '$1#',
            $html
        );

        return $html;
    }

    /**
     * Extract math expressions and replace with opaque placeholders.
     *
     * Pattern priority (display first, then inline):
     *   1. \[...\]   display math  (multiline OK)
     *   2. $$...$$   display math  (multiline OK)
     *   3. \(...\)   inline math   (multiline OK)
     *   4. $...$     inline math   (single-line, non-ambiguous)
     *
     * Placeholders: %%AIMATH_N%%  — survive Parsedown and strip_tags() unchanged.
     *
     * @param string $text          Input markdown.
     * @param array  &$placeholders Map: placeholder → original expression.
     * @param int    &$counter      Running counter for unique keys.
     * @return string               Text with math replaced by placeholders.
     */
    private static function extract_math(
        string $text,
        array  &$placeholders,
        int    &$counter
    ): string {

        $store = static function(string $math) use (&$placeholders, &$counter): string {
            $key = '%%AIMATH_' . $counter . '%%';
            $placeholders[$key] = $math;
            $counter++;
            return $key;
        };

        // 1. Display \[...\]
        $text = preg_replace_callback(
            '/\\\\\[(.+?)\\\\\]/s',
            static fn($m) => $store($m[0]),
            $text
        );

        // 2. Display $$...$$
        $text = preg_replace_callback(
            '/\$\$(.+?)\$\$/s',
            static fn($m) => $store($m[0]),
            $text
        );

        // 3. Inline \(...\)
        $text = preg_replace_callback(
            '/\\\\\((.+?)\\\\\)/s',
            static fn($m) => $store($m[0]),
            $text
        );

        // 4. Inline $...$  (no newlines, not adjacent to another $)
        $text = preg_replace_callback(
            '/(?<!\$)\$(?!\$)(\S[^\n$]*?\S|\S)\$(?!\$)/',
            static fn($m) => $store($m[0]),
            $text
        );

        return $text;
    }
}
