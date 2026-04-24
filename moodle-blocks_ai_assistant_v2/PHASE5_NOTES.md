# Phase 5 Notes

Version: 2026042306

## Hardening completed
- Raised plugin version to `2026042306` and release to `0.5.0-alpha`.
- Rewrote `db/services.php` so all AJAX externals consistently declare the required capability.
- Expanded `db/upgrade.php` savepoints to cover the delivered Phase 3, Phase 4, and Phase 5 versions.
- Added `classes/privacy/provider.php` plus language strings so the plugin now declares stored personal data for Moodle privacy metadata.
- Hardened `classes/external/ask_mcq.php` for Moodle/PHP 8.2 style consistency by using declared globals, `$CFG->dirroot` loading, stricter payload decoding, and cleaner normalization.

## Remaining architectural review note
- The plugin still reuses `block_ai_assistant_history` and `block_ai_assistant_syllabus` table names instead of owning `block_ai_assistant_v2_*` tables. That preserves continuity with v1 but remains a structural deviation from normal Moodle plugin ownership and should be refactored in a later migration phase.
