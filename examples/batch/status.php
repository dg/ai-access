<?php declare(strict_types=1);

/**
 * Checks whether a submitted batch has finished.
 *
 * Demonstrates: Batch\Service::retrieveBatch(), Batch\Response::getStatus()
 * Providers:    openai, claude, gemini
 * Usage:        php examples/batch/status.php [openai|claude|gemini] <batch-id>
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Batch\Status;

$client = createClient(AIAccess\Batch\Service::class);
assert($client instanceof AIAccess\Batch\Service);

$batchId = arg(1) ?? fail('Pass the batch id: php examples/batch/status.php <provider> <batch-id>');
$batch = $client->retrieveBatch($batchId);
$status = $batch->getStatus();

echo 'status:  ', $status->name, "\n";
echo 'created: ', $batch->getCreatedAt()?->format('Y-m-d H:i:s') ?? 'n/a', "\n";
echo 'ended:   ', $batch->getCompletedAt()?->format('Y-m-d H:i:s') ?? '-', "\n";

if ($error = $batch->getError()) {
	echo "note:    $error\n";
}

echo "\n", match ($status) {
	Status::Completed => "Done. Fetch the answers with batch/results.php.\n",
	Status::InProgress => "Still running, check again later.\n",
	default => "Nothing more will come out of this batch.\n",
};
