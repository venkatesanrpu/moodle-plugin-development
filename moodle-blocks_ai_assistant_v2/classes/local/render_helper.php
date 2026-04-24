<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This file is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.

/**
 * Render helper: Markdown + Math-safe pipeline for AI responses.
 *
 * Pipeline:
 *   1. Extract math spans (protect from Markdown parser)
 *   2. Run Parsedown (Markdown → HTML)
 *   3. Restore math spans
 *   4. Sanitise with Moodle's clean_text()
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
     * @return string      Sanitised HTML ready for innerHTML.
     */
    public static function render(string $raw): string {
        if (trim($raw) === '') {
            return '';
        }

        // ── Step 1: extract math spans into a placeholder table ──────────
        $maths    = [];
        $counter  = 0;

        $protected = self::extract_math($raw, $maths, $counter);

        // ── Step 2: Parsedown ─────────────────────────────────────────────
        $pd = new \Parsedown();
        $pd->setSafeMode(false); // We sanitise with clean_text() below.
        $html = $pd->text($protected);

        // ── Step 3: restore math placeholders ────────────────────────────
        foreach ($maths as $placeholder => $math) {
            $html = str_replace($placeholder, $math, $html);
        }

        // ── Step 4: sanitise with Moodle's XSS cleaner ───────────────────
        // clean_text() strips dangerous tags/attributes but preserves
        // our math delimiters (\(...\), \[...\]) and inline MathJax markup.
        $html = clean_text($html, FORMAT_HTML);

        return $html;
    }

    /**
     * Extract math expressions and replace with opaque placeholders.
     * Order matters: longest/greediest patterns first.
     *
     * Handles:
     *   \[...\]   display math (multiline)
     *   $$...$$   display math (multiline)
     *   \(...\)   inline math
     *   $...$     inline math (single dollar, non-ambiguous)
     *
     * @param string $text     Input markdown.
     * @param array  &$maths   Map of placeholder → original expression.
     * @param int    &$counter Running counter for unique placeholders.
     * @return string          Text with math replaced by placeholders.
     */
    private static function extract_math(string $text, array &$maths, int &$counter): string {

        $placeholder_fn = function(string $math) use (&$maths, &$counter): string {
            $key = 'AIMATH' . $counter . 'PLACEHOLDER';
            $maths[$key] = $math;
            $counter++;
            return $key;
        };

        // 1. Display: \[...\]  (multiline)
        $text = preg_replace_callback(
            '/\\\\\[(.+?)\\\\\]/s',
            fn($m) => $placeholder_fn($m[0]),
            $text
        );

        // 2. Display: $$...$$  (multiline)
        $text = preg_replace_callback(
            '/\$\$(.+?)\$\$/s',
            fn($m) => $placeholder_fn($m[0]),
            $text
        );

        // 3. Inline: \(...\)
        $text = preg_replace_callback(
            '/\\\\\((.+?)\\\\\)/s',
            fn($m) => $placeholder_fn($m[0]),
            $text
        );

        // 4. Inline: $...$ (single dollar)
        // Only match when not preceded/followed by another $,
        // content is non-empty and doesn't span blank lines.
        $text = preg_replace_callback(
            '/(?<!\$)\$(?!\$)([^\n$]+?)(?<!\$)\$(?!\$)/',
            fn($m) => $placeholder_fn($m[0]),
            $text
        );

        return $text;
    }
}
