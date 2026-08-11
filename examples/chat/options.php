<?php declare(strict_types=1);

/**
 * How hard should the model think before answering?
 *
 * Demonstrates: Chat::setEffort()
 * Providers:    all
 * Usage:        php examples/chat/options.php [openai|claude|gemini|deepseek|grok]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Chat\Effort;

$client = createClient();
$question = 'In one sentence: why do programmers prefer dark mode?';

foreach ([Effort::Low, Effort::High] as $effort) {
	$response = $client->createChat()
		->setEffort($effort)
		->sendMessage($question);

	$usage = $response->getUsage();
	echo '--- effort ', $effort->name, " ---\n";
	echo $response->getText(), "\n";
	echo 'reasoning tokens: ', $usage->reasoningTokens ?? 'n/a', "\n";
	echo 'total tokens:     ', $usage?->getTotalTokens() ?? 'n/a', "\n\n";
}
