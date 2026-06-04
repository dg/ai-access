<?php declare(strict_types=1);

/**
 * Reads the answers of a finished batch, keyed by the custom id you chose.
 *
 * Demonstrates: Batch\Response::getMessages(), Batch\Response::getErrors()
 * Providers:    openai, claude, gemini
 * Usage:        php examples/batch/results.php [openai|claude|gemini] <batch-id>
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Batch\Status;

$client = createClient(AIAccess\Batch\Service::class);
assert($client instanceof AIAccess\Batch\Service);

$batchId = arg(1) ?? fail('Pass the batch id: php examples/batch/results.php <provider> <batch-id>');
$batch = $client->retrieveBatch($batchId);

if ($batch->getStatus() !== Status::Completed) {
	fail('Batch is not finished yet, it is ' . $batch->getStatus()->name . '.');
}

foreach ($batch->getMessages() ?? [] as $customId => $message) {
	echo $customId, ': ', $message->getText(), "\n";
}

// requests that failed individually do not throw, they are collected here
foreach ($batch->getErrors() as $customId => $error) {
	echo $customId, ' FAILED: ', $error, "\n";
}
