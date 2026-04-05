<?php declare(strict_types=1);

/**
 * Getting an array back instead of prose.
 *
 * Demonstrates: Chat::setResponseSchema(), Response::getJson()
 * Providers:    openai, claude, gemini, grok
 * Usage:        php examples/structured-output/extraction.php [openai|claude|gemini|grok]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient();
$chat = $client->createChat(chatModel($client));

if (!method_exists($chat, 'setResponseSchema')) {
	fail('This provider has no JSON schema mode. Try openai, claude, gemini or grok.');
}

$chat->setResponseSchema([
	'type' => 'object',
	'properties' => [
		'name' => ['type' => 'string'],
		'founded' => ['type' => 'integer'],
		'languages' => ['type' => 'array', 'items' => ['type' => 'string']],
	],
	'required' => ['name', 'founded', 'languages'],
	'additionalProperties' => false,
]);

$response = $chat->sendMessage(
	'Zend Technologies was founded in 1999 by Andi Gutmans and Zeev Suraski, '
	. 'the authors of the PHP engine. Extract the company.',
);

$data = $response->getJson();

echo $data['name'], ' (', $data['founded'], ")\n";
echo 'languages: ', implode(', ', $data['languages']), "\n";
