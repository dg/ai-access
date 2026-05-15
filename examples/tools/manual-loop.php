<?php declare(strict_types=1);

/**
 * The same thing with the loop in your hands.
 *
 * Demonstrates: Response::getToolCalls(), Chat::addToolResult()
 * Providers:    openai, claude, gemini, deepseek, grok
 * Usage:        php examples/tools/manual-loop.php [provider]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Chat\Tool;

$client = createClient();
$chat = $client->createChat(chatModel($client));

// no handler, so the library hands the calls to you instead of running them
$chat->addTool(new Tool(
	name: 'get_weather',
	description: 'Returns the current weather for a city.',
	parameters: [
		'type' => 'object',
		'properties' => ['city' => ['type' => 'string']],
		'required' => ['city'],
	],
));

$response = $chat->sendMessage('What is the weather in Brno? Use the tool.');

// driving the loop yourself means owning the stop condition too
for ($round = 0; $round < 5 && $calls = $response->getToolCalls(); $round++) {
	foreach ($calls as $call) {
		echo "model asks: $call->name(", json_encode($call->arguments), ")\n";
		$chat->addToolResult($call, ['temperature' => 18, 'sky' => 'clear']);
	}
	$response = $chat->sendMessage();
}

echo "\n", $response->getText(), "\n";
