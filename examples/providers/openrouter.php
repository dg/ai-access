<?php declare(strict_types=1);

/**
 * One key, hundreds of models.
 *
 * Demonstrates: OpenAICompatible\Client with extra headers
 * Usage:        OPENROUTER_API_KEY=... php examples/providers/openrouter.php [model]
 */

require __DIR__ . '/../bootstrap.php';

$key = getenv('OPENROUTER_API_KEY') ?: fail('Set OPENROUTER_API_KEY in examples/.env');

$client = new AIAccess\Provider\OpenAICompatible\Client($key, 'https://openrouter.ai/api/v1');
$client->setOptions(extraHeaders: [
	'HTTP-Referer' => 'https://github.com/dg/ai-access',
	'X-Title' => 'AI Access example',
]);

$response = $client->createChat($GLOBALS['argv'][1] ?? 'meta-llama/llama-4-scout')
	->sendMessage('Write a one-sentence haiku about PHP.');

echo $response->getText(), "\n";
echo $response->getUsage()?->getTotalTokens(), " tokens\n";
