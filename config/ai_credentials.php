<?php
/**
 * NexoraCopy Assistant - AI provider credentials.
 *
 * The support bot answers real questions with a live language model. By default
 * it uses a fully free, key-less OpenAI-compatible endpoint, so the assistant
 * works out of the box with no sign-up. Point it at any other free provider
 * (Google Gemini, Groq, OpenRouter, Cerebras, a local Ollama, ...) by setting
 * the environment variables below - or just edit the fallback values here.
 *
 *   AI_CHAT_ENDPOINT  Full URL that accepts an OpenAI-style chat completion POST.
 *   AI_API_KEY        Bearer token. Leave blank for key-less free providers.
 *   AI_MODEL          Model name understood by that endpoint.
 *   AI_ENABLED        '0' / 'false' turns the model off (pure rule-based bot).
 *
 * Free, OpenAI-compatible options:
 *   - Pollinations (default, no key):  https://text.pollinations.ai/openai
 *   - Google Gemini:  https://generativelanguage.googleapis.com/v1beta/openai/chat/completions   (model: gemini-2.0-flash)
 *   - Groq:           https://api.groq.com/openai/v1/chat/completions                             (model: llama-3.3-70b-versatile)
 *   - OpenRouter:     https://openrouter.ai/api/v1/chat/completions                               (model: meta-llama/llama-3.3-70b-instruct:free)
 *   - Local Ollama:   http://localhost:11434/v1/chat/completions                                  (model: llama3.1)
 */

function ai_env(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

define('AI_ENABLED', !in_array(strtolower(ai_env('AI_ENABLED', '1')), ['0', 'false', 'no', 'off'], true));
define('AI_CHAT_ENDPOINT', ai_env('AI_CHAT_ENDPOINT', 'https://text.pollinations.ai/openai'));
define('AI_API_KEY', ai_env('AI_API_KEY', ''));
define('AI_MODEL', ai_env('AI_MODEL', 'openai'));
define('AI_TIMEOUT', (int)ai_env('AI_TIMEOUT', '20'));
define('AI_SITE_NAME', ai_env('AI_SITE_NAME', 'NexoraCopy'));
