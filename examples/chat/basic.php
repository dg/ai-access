<?php declare(strict_types=1);

/**
 * The simplest possible chat: send a message, read the answer.
 *
 * Demonstrates: Chat\Service::createChat(), Chat::sendMessage(), Response::getText()
 * Providers:    all
 * Usage:        php examples/chat/basic.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient();
$chat = $client->createChat(chatModel($client));

$response = $chat->sendMessage('Write a one-sentence haiku about PHP.');

echo $response->getText(), "\n";

$usage = $response->getUsage();
echo "\n", $usage?->inputTokens, ' tokens in / ', $usage?->outputTokens, " out\n";
