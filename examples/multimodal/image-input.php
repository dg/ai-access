<?php declare(strict_types=1);

/**
 * Showing the model a picture instead of describing it.
 *
 * Demonstrates: Media::fromFile() as part of a message
 * Providers:    openai, claude, gemini, grok
 * Usage:        php examples/multimodal/image-input.php [provider] [image.png]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Media;

$client = createClient();
$chat = $client->createChat(chatModel($client));

$path = arg(2) ?? __DIR__ . '/sample.png';
if (!is_file($path)) {
	fail('Pass an image: php examples/multimodal/image-input.php ' . (arg(1) ?? 'openai') . ' photo.jpg');
}

// a message is text, or media, or both; the provider's own wire format is not your problem
$response = $chat->sendMessage([
	'What is in this picture? Answer in one sentence.',
	Media::fromFile($path),
]);

echo $response->getText(), "\n";

// the picture stays in the history, so follow-up questions still see it
echo $chat->sendMessage('And what colour dominates it?')->getText(), "\n";
