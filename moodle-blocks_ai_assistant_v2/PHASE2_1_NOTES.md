# Phase 2.1 Contract Stabilization

Version: 2026042303

## Changes made

- Added `classes/local/agent_repository.php` to validate that the selected agent exists in `local_ai_functions_v2_agents`, decode `config_data`, and confirm that the required `notes_agent` function is configured.
- Added `classes/local/sse_response.php` so stream event emission uses one consistent helper for `status`, `done`, and `error` events.
- Updated `classes/external/ask_agent.php` to validate the `notes_agent` function contract before creating a pending history row.
- Updated `stream.php` to validate the same contract before dispatch and to emit normalized `status`, `done`, and `error` SSE events.
- Hardened `amd/src/widget.js` to close previous streams safely, disable input while streaming, ignore expected EventSource close noise, and save final response metadata after successful completion.
- Left history and MCQ features for later phases so this package focuses only on block-to-local-plugin contract stabilization.

## Contract assumptions now enforced

- Agent config table: `local_ai_functions_v2_agents`
- Required notes function key: `notes_agent`
- Expected local entrypoint: `local_ai_functions_v2_call_endpoint($agentkey, 'notes_agent', $payload)`
- Existing block history table reused: `block_ai_assistant_history`
- Existing syllabus table reused: `block_ai_assistant_syllabus`
