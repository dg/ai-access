<?php declare(strict_types=1);

/**
 * Reading the answer while the model is still writing it.
 *
 * Demonstrates: Chat::sendMessageStream(), TextStream::getResponse()
 * Providers:    openai, claude, gemini, deepseek, grok
 * Usage:        php examples/chat/streaming.php [provider]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient();
$chat = $client->createChat();

$stream = $chat->sendMessageStream('Explain in three sentences why PHP is still everywhere.');

// nothing has been sent yet; the request starts with the first read
foreach ($stream as $delta) {
	echo $delta;
	flush();
}

echo "\n\n";

// the stream is a response like any other once it is done
$response = $stream->getResponse();
echo 'finished as ', $response->getFinishReason()->value,
	', ', $response->getUsage()?->outputTokens, " output tokens\n";
