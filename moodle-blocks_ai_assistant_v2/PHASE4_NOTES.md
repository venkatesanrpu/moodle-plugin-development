# Phase 4 Notes

Version: 2026042305

## Added
- Integrated a dedicated MCQ practice panel into the main widget shell.
- Added `classes/external/ask_mcq.php` to validate the `mcq_agent` contract and call `local_ai_functions_v2_call_endpoint()` without streaming.
- Extended `prompt_builder.php` with an MCQ JSON prompt contract.
- Replaced the placeholder MCQ AMD module with a working generator, renderer, and answer-check flow.
- Registered `block_ai_assistant_v2_ask_mcq` in `db/services.php` and widened the shell layout to support history, MCQs, and notes together.
