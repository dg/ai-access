<?php declare(strict_types=1);

/**
 * Queues several independent requests at roughly half the price.
 *
 * Demonstrates: Batch\Service::createBatch(), Batch::addChat(), Batch::submit()
 * Providers:    openai, claude, gemini
 * Usage:        php examples/batch/submit.php [openai|claude|gemini]
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Chat\Role;

$client = createClient(AIAccess\Batch\Service::class);
assert($client instanceof AIAccess\Batch\Service);
$model = chatModel($client);

$batch = $client->createBatch();

$questions = [
	'capital-fr' => 'What is the capital of France?',
	'capital-jp' => 'What is the capital of Japan?',
	'capital-cz' => 'What is the capital of Czechia?',
];

foreach ($questions as $customId => $question) {
	$chat = $batch->addChat($model, $customId);
	$chat->setSystemInstruction('Answer with the city name only.');
	$chat->addMessage($question, Role::User);
}

$response = $batch->submit();

echo 'batch id: ', $response->getId(), "\n";
echo 'status:   ', $response->getStatus()->name, "\n\n";
echo "Results arrive within minutes to 24 hours. Check with:\n";
echo '  php examples/batch/status.php ', $GLOBALS['argv'][1] ?? 'openai', ' ', $response->getId(), "\n";
