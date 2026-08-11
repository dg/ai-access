<?php declare(strict_types=1);

/**
 * A multi-turn conversation. The history is kept for you.
 *
 * Demonstrates: Chat::sendMessage(), Chat::addMessage(), Chat::getMessages()
 * Providers:    all
 * Usage:        php examples/chat/conversation.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Chat\Role;

$client = createClient();
$chat = $client->createChat();

echo "> What is the capital of France?\n";
echo $chat->sendMessage('What is the capital of France?')->getText(), "\n\n";

// the model still knows what "there" refers to
echo "> And what is a famous landmark there?\n";
echo $chat->sendMessage('And what is a famous landmark there?')->getText(), "\n\n";

echo "--- history ---\n";
foreach ($chat->getMessages() as $message) {
	echo $message->getRole()->name, ': ', mb_substr($message->getText(), 0, 60), "...\n";
}

// history can also be built by hand and continued with sendMessage() without arguments
$restored = $client->createChat();
$restored->addMessage('My favourite number is 42.', Role::User);
$restored->addMessage('Noted, 42 it is.', Role::Model);
$restored->addMessage('What is my favourite number?', Role::User);

echo "\n--- restored conversation ---\n";
echo $restored->sendMessage()->getText(), "\n";
