<?php declare(strict_types=1);

/**
 * A model running on your own machine, through the same interface.
 *
 * Demonstrates: OpenAICompatible\Client against a local server
 * Usage:        php examples/providers/ollama.php [model]
 */

require __DIR__ . '/../bootstrap.php';

// no API key: the client then sends no authorization header, which is what
// local servers expect
$client = new AIAccess\Provider\OpenAICompatible\Client('', 'http://localhost:11434/v1');

$response = $client->createChat($GLOBALS['argv'][1] ?? 'llama3.2')
	->sendMessage('Write a one-sentence haiku about PHP.');

echo $response->getText(), "\n";
