# Phase 6 Notes

Version: 2026042307

## Standards migration completed
- Replaced `db/install.xml` ownership of shared v1 tables with plugin-owned `block_ai_assistant_v2_history` and `block_ai_assistant_v2_syllabus` tables.
- Added repository-based table resolution so the block can read and write v2-owned tables while still falling back to v1 tables if the migration has not run yet.
- Expanded `db/upgrade.php` to create the v2-owned tables and copy existing rows from the old v1 tables on upgrade when needed.
- Updated syllabus loading and saving to use `syllabus_repository`, including block instance save handling.
- Improved history previews so stored `mcq_agent` rows show a readable MCQ summary instead of raw JSON in the history panel.

## Result
- New installs follow Moodle plugin table-ownership standards.
- Existing installs can migrate forward without losing v1 history and syllabus records.
