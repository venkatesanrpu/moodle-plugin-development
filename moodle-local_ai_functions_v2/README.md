# moodle-local_ai_functions_v2

A Moodle local plugin that stores AI agent definitions securely and routes function calls through a modular dispatcher.

## Initial scope

- `ask_agent` for study notes and similar long-form responses.
- `mcq` for structured exam-style question generation.

## Design goals

- Moodle 4.5 minimum, compatible with Moodle 5.x.
- Keep secrets and provider endpoints in the backend.
- Make the dispatcher module-based so new functions like `websearch` can be added without changing the core runtime contract.
- Normalize provider responses so the block plugin does not depend on provider-specific formats.

## Configuration JSON example

```json
{
  "providers": {
    "notes_provider": {
      "type": "openai_compatible",
      "endpoint": "https://example.com/v1/chat/completions",
      "api_key": "replace-me",
      "api_style": "chat_completions"
    },
    "mcq_provider": {
      "type": "openai_compatible",
      "endpoint": "https://example.com/v1/responses",
      "api_key": "replace-me",
      "api_style": "responses"
    }
  },
  "functions": {
    "ask_agent": {
      "module": "ask_agent",
      "provider": "notes_provider",
      "model": "mistral-large-3",
      "stream": true,
      "max_output_tokens": 4000,
      "temperature": 0.3
    },
    "mcq": {
      "module": "mcq",
      "provider": "mcq_provider",
      "model": "gpt-4.1",
      "stream": false,
      "max_output_tokens": 2000,
      "temperature": 0.2
    }
  }
}
```

## Public runtime entrypoint

Callers should include `local/ai_functions_v2/libagent.php` and call:

```php
local_ai_functions_v2_call_endpoint($agentkey, $functionkey, $payload);
```

## Normalised payload examples

### ask_agent

```php
[
  'system_prompt' => '...',
  'user_prompt' => 'Explain aromaticity',
  'stream' => true,
  'options' => [
    'max_output_tokens' => 5000,
  ],
]
```

### mcq

```php
[
  'system_prompt' => '...',
  'user_prompt' => 'Generate MCQs on thermodynamics',
  'stream' => false,
  'difficulty' => 'advanced',
  'json_schema' => [...],
]
```
