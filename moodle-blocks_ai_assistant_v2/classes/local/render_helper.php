<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Render helper: Markdown + Math-safe pipeline for AI responses.
 *
 * Pipeline:
 *   1. Extract math spans (protect ALL four delimiter styles from Parsedown)
 *   2. Run Parsedown (Markdown → HTML)
 *   3. Restore math spans verbatim
 *   4. Sanitise with Moodle's clean_text()
 *
 * phase7_6b changes:
 *   - Placeholder tokens now use unique boundary markers (%%AIMATH_N%%) that
 *     are guaranteed not to appear in real markdown or HTML — avoids accidental
 *     str_replace collisions with Parsedown output.
 *   - Regex order: display delimiters (greedy) before inline (non-greedy).
 *   - \[...\] and \(...\) patterns fixed: PHP double-quoted string escaping
 *     was silently stripping one backslash level, breaking the regex match.
 *     Now uses single-quoted strings + explicit preg_quote-safe patterns.
 *   - $...$ single-dollar pattern tightened: no newlines, not adjacent to
 *     another $, minimum 1 non-space character inside.
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
     * Render raw LLM markdown (with LaTeX) into safe HTML.
     *
     * @param string $raw  Raw markdown text from LLM / DB botresponse column.
     * @return string      Sanitised HTML ready for innerHTML assignment.
     */
    public static function render(string $raw): string {
        if (trim($raw) === '') {
            return '';
        }

        // ── Step 1: extract and protect math expressions ─────────────────
        $placeholders = [];
        $counter      = 0;

        $protected = self::extract_math($raw, $placeholders, $counter);

        // ── Step 2: Parsedown — Markdown → HTML ───────────────────────────
        $pd = new \Parsedown();
        $pd->setSafeMode(false); // XSS cleaned by clean_text() in Step 4.
        $html = $pd->text($protected);

        // ── Step 3: restore math placeholders verbatim ───────────────────
        // Use str_replace with arrays for a single-pass replacement.
        if (!empty($placeholders)) {
            $html = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $html
            );
        }

        // ── Step 4: sanitise with Moodle's XSS cleaner ───────────────────
        // clean_text() strips dangerous tags/attributes but preserves
        // \(...\) and \[...\] verbatim (they contain no HTML).
        $html = clean_text($html, FORMAT_HTML);

        return $html;
    }

    /**
     * Extract math expressions, replace with opaque placeholders.
     *
     * Pattern priority (longest/display first, then inline):
     *   1. \[...\]   — display math   (multiline OK)
     *   2. $$...$$   — display math   (multiline OK)
     *   3. \(...\)   — inline math    (multiline OK)
     *   4. $...$     — inline math    (single line, non-ambiguous)
     *
     * Placeholders look like: %%AIMATH_0%%  %%AIMATH_1%%  etc.
     * The %% fences ensure they survive Parsedown unchanged.
     *
     * @param string $text          Input markdown.
     * @param array  &$placeholders Map: placeholder → original expression.
     * @param int    &$counter      Running counter for unique keys.
     * @return string               Text with math replaced by placeholders.
     */
    private static function extract_math(string $text, array &$placeholders, int &$counter): string {

        $store = function(string $math) use (&$placeholders, &$counter): string {
            $key = '%%AIMATH_' . $counter . '%%';
            $placeholders[$key] = $math;
            $counter++;
            return $key;
        };

        // 1. Display: \[...\]  — note single-quoted strings to avoid PHP eating backslashes.
        //    Regex: literal backslash + '[' ... literal backslash + ']', multiline content.
        $text = preg_replace_callback(
            '/\\\\\[(.+?)\\\\\]/s',
            fn($m) => $store($m[0]),
            $text
        );

        // 2. Display: $$...$$  (must come before single-$ to avoid partial matches).
        $text = preg_replace_callback(
            '/\$\$(.+?)\$\$/s',
            fn($m) => $store($m[0]),
            $text
        );

        // 3. Inline: \(...\)
        $text = preg_replace_callback(
            '/\\\\\((.+?)\\\\\)/s',
            fn($m) => $store($m[0]),
            $text
        );

        // 4. Inline: $...$
        //    Rules: not preceded/followed by $, at least one non-space char inside,
        //    no newlines inside (avoids catching paragraph breaks as math).
        $text = preg_replace_callback(
            '/(?<!\$)\$(?!\$)(\S[^\n$]*?\S|\S)\$(?!\$)/',
            fn($m) => $store($m[0]),
            $text
        );

        return $text;
    }
}
