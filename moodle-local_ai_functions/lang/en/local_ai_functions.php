<?php
// FILE: moodle/local/ai_functions/lang/en/local_ai_functions.php
$string['pluginname'] = 'AI Function Agents';
$string['manage_agents_heading'] = 'Manage AI Function Agents';
$string['add_new_agent'] = 'Add new agent';
$string['edit_agent_heading'] = 'Edit Agent';
$string['add_agent_heading'] = 'Add New Agent';
$string['edit_agent_title'] = 'Edit Agent';
$string['agent_name'] = 'Agent Name';
$string['agent_key'] = 'Agent Key';
$string['agentname'] = 'Agent Name';
$string['agentkey'] = 'Agent Key';
$string['agent_endpoint'] = 'Base Endpoint URL';
$string['config_data'] = 'Endpoint URL and Function Keys (JSON)';
$string['configdata'] = 'Endpoint URL and Function Keys (JSON)';
$string['functions_configured'] = 'Founctions Configured (JSON)';
$string['actions'] = 'Actions';
$string['error_invalid_json'] = 'Error: The text you entered is not valid JSON.';

// --- UPDATED: Detailed Help Strings ---
$string['agentkey_help'] = '<h3>Agent Key</h3><p>This is a short, unique keyword for this entire agent configuration. The Moodle AI Assistant Block will use this key to identify which set of functions to use.</p><p><b>Rules:</b></p><ul><li>No spaces or special characters.</li><li>Should be descriptive.</li></ul><p><b>Example:</b> <code>chemistry_ai</code></p>';

$string['agent_endpoint_help'] = '<h3>Base Endpoint URL</h3><p>Enter the base URL for your Azure Function App. This is the main address that all the individual functions live under.</p><p><b>Important:</b> The URL must end with a forward slash (`/`).</p><p><b>Example:</b> <code>https://my-moodle-functions.azurewebsites.net/api/</code></p>';

$string['configdata_help'] = '<h3>Function Keys (JSON)</h3><p>This field stores the names of your individual Azure Functions and their corresponding access keys. The format must be valid JSON.</p>
<p>The "key" in each pair (e.g., "mcq") must exactly match the name of your Azure Function. This is also the value you will use in the `data-function` attribute of your Moodle links.</p>
<p>The "value" is the specific Function Key provided by Azure for that function.</p>
<p><b>Example:</b></p>
<pre>
{
	"ask_agent": 
		{
			"endpoint": "https://kimi-k2.ai/api/v1/chat/completions,
			"api_key": "sk-RO..."
		},
	"youtube_summarize": 
		{
			"endpoint": "https://kimi-k2.ai/api/v1/chat/completions,
			"api_key": "sk-RO..."
		},
	"websearch": 
		{
			"endpoint": "https://kimi-k2.ai/api/v1/chat/completions,
			"api_key": "sk-RO..."
		},
	"mcq": 
		{
			"endpoint": "https://kimi-k2.ai/api/v1/chat/completions,
			"api_key": "sk-RO..."
		}
}
</pre>';

