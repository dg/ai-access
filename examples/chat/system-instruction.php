<?php declare(strict_types=1);

/**
 * A system instruction sets the rules for the whole conversation.
 *
 * Demonstrates: Chat::setSystemInstruction()
 * Providers:    all
 * Usage:        php examples/chat/system-instruction.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient();
$chat = $client->createChat(chatModel($client));

$chat->setSystemInstruction('You are a ship captain from the 18th century. Answer in one short sentence.');

echo $chat->sendMessage('How is the weather today?')->getText(), "\n";
echo $chat->sendMessage('And what about tomorrow?')->getText(), "\n";
