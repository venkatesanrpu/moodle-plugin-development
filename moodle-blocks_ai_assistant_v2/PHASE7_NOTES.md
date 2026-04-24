# Phase 7 Notes

Version: 2026042308

## Regression fixes completed
- Fixed block instance save logic to persist syllabus repository data from the normalized saved block config instead of reading unsanitized pre-save form properties.
- Removed the hard runtime require of `local/ai_functions_v2/lib.php` from the edit form so the form can render safely even if only the list function is available elsewhere in the runtime.
- Improved migration safety in `db/upgrade.php` by switching record copy operations to recordsets and adding a Phase 7 savepoint.

## Audit conclusion
- The biggest functional regression risk in Phase 6 was the config field-name mismatch between `config_*` edit-form elements and `instance_config_save()`. That has now been corrected.
- The biggest standards risk that still cannot be fully verified in this sandbox is runtime execution under real Moodle 5.1 + PHP 8.2, because PHP CLI and Moodle admin upgrade execution are not available here.
