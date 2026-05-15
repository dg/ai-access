<?php declare(strict_types=1);

/**
 * Letting the model call your code.
 *
 * Demonstrates: Chat::addTool() with a handler, the automatic tool loop
 * Providers:    openai, claude, gemini, deepseek, grok
 * Usage:        php examples/tools/weather.php [provider]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Chat\Tool;

$client = createClient();
$chat = $client->createChat(chatModel($client));

$chat->addTool(new Tool(
	name: 'get_weather',
	description: 'Returns the current weather for a city.',
	parameters: [
		'type' => 'object',
		'properties' => [
			'city' => ['type' => 'string', 'description' => 'City name, e.g. Brno'],
		],
		'required' => ['city'],
	],
	handler: fn(array $args) => match (strtolower($args['city'])) {
		'brno' => ['temperature' => 18, 'sky' => 'clear'],
		'reykjavik' => ['temperature' => 4, 'sky' => 'rain'],
		default => ['error' => 'No station in ' . $args['city']],
	},
));

// one call, however many rounds it takes: the library calls the handler and
// sends the result back until the model is done
$response = $chat->sendMessage('Compare the weather in Brno and Reykjavik. Use the tool.');

echo $response->getText(), "\n\n";

echo 'Rounds in the history: ', count($chat->getMessages()), "\n";
echo 'Tokens for the whole exchange: ', $chat->getTotalUsage()?->getTotalTokens(), "\n";
