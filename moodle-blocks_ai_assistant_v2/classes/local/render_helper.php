<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Render helper: Parsedown + math extraction, NO HTMLPurifier/clean_text.
 *
 * Phase 7_6f — fixes:
 *   1. Replaces clean_text() with strip_tags() allowlist so backslashes
 *      inside MathJax delimiters \( \[ $$ survive intact.
 *   2. Math tokens are extracted before Parsedown and restored after,
 *      preventing Parsedown from mangling LaTeX.
 *
 * @package    block_ai_assistant_v2
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/Parsedown.php');

/**
 * Converts raw LLM output (Markdown + LaTeX) to safe HTML.
 */
class render_helper {

    /**
     * HTML tags that Parsedown can emit — all others are stripped.
     * strip_tags() does NOT encode text content so backslashes survive.
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
     * Render raw LLM text to safe HTML.
     *
     * Pipeline:
     *   1. Strip <think>...</think> chain-of-thought blocks.
     *   2. Extract math delimiters into placeholders (protects them from Parsedown).
     *   3. Run Parsedown (Markdown → HTML).
     *   4. Restore math placeholders verbatim.
     *   5. Sanitise with strip_tags() allowlist (preserves text node content).
     *   6. Remove on* event attributes and javascript:/data: href/src values.
     *
     * @param  string $raw  Raw LLM response text.
     * @return string       Safe HTML ready for innerHTML injection.
     */
    public static function render(string $raw): string {
        if (trim($raw) === '') {
            return '';
        }

        // Step 1: Strip chain-of-thought blocks.
        $raw = preg_replace('/<think>[\s\S]*?<\/think>/is', '', $raw);

        // Step 2: Extract math tokens → placeholders.
        $placeholders = [];
        $counter      = 0;
        $protected    = self::extract_math($raw, $placeholders, $counter);

        // Step 3: Parsedown — Markdown → HTML.
        $pd = new \Parsedown();
        $pd->setSafeMode(false); // Sanitised in step 5.
        $html = $pd->text($protected);

        // Step 4: Restore math placeholders verbatim.
        if (!empty($placeholders)) {
            $html = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $html
            );
        }

        // Step 5 (KEY FIX): strip_tags() allowlist — NOT clean_text() / HTMLPurifier.
        // Text nodes (including \( \[ $$ delimiters) are UNTOUCHED by strip_tags().
        $html = strip_tags($html, self::SAFE_TAGS);

        // Step 6a: Remove on* event handler attributes.
        $html = preg_replace(
            '/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i',
            '',
            $html
        );

        // Step 6b: Neutralise javascript: and data: in href / src.
        $html = preg_replace(
            '/\s+(href|src)\s*=\s*["\']?\s*(javascript|data)\s*:/i',
            ' $1="#"',
            $html
        );

        return $html;
    }

    /**
     * Extract math expressions into %%AIMATH_N%% placeholders.
     *
     * Order matters — longer delimiters must be matched before shorter ones.
     *   Display: \[...\]  then  $$...$$
     *   Inline:  \(...\)  then  $...$
     *
     * %% fences are not special to Parsedown so they pass through unchanged.
     *
     * @param  string  $text         Input text.
     * @param  array   &$placeholders Map of placeholder → original math string.
     * @param  int     &$counter     Running index for unique placeholder names.
     * @return string                Text with math replaced by placeholders.
     */
    private static function extract_math(
        string $text,
        array  &$placeholders,
        int    &$counter
    ): string {

        $store = static function (string $math) use (&$placeholders, &$counter): string {
            $key = '%%AIMATH_' . $counter . '%%';
            $placeholders[$key] = $math;
            $counter++;
            return $key;
        };

        // Display: \[...\]
        $text = preg_replace_callback(
            '/\\\\\[[\s\S]+?\\\\\]/s',
            static fn($m) => $store($m[0]),
            $text
        );

        // Display: $$...$$
        $text = preg_replace_callback(
            '/\$\$[\s\S]+?\$\$/s',
            static fn($m) => $store($m[0]),
            $text
        );

        // Inline: \(...\)
        $text = preg_replace_callback(
            '/\\\\\([\s\S]+?\\\\\)/s',
            static fn($m) => $store($m[0]),
            $text
        );

        // Inline: $...$ (single dollar, non-greedy, no newlines inside)
        $text = preg_replace_callback(
            '/(?<!\$)\$(?!\$)(\S[^\n$]*?\S|\S)\$(?!\$)/',
            static fn($m) => $store($m[0]),
            $text
        );

        return $text;
    }
}
