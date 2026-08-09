<?php declare(strict_types=1);

/**
 * Reads the answers of a finished batch, keyed by the custom id you chose.
 *
 * Demonstrates: Batch\Response::getResults()
 * Providers:    openai, claude, gemini
 * Usage:        php examples/batch/results.php [openai|claude|gemini] <batch-id>
 */

require __DIR__ . '/../bootstrap.php';

use AIAccess\Batch\Status;

$client = createClient(AIAccess\Batch\Service::class);
assert($client instanceof AIAccess\Batch\Service);

$batchId = arg(1) ?? fail('Pass the batch id: php examples/batch/results.php <provider> <batch-id>');
$batch = $client->retrieveBatch($batchId);

// a cancelled or expired job still hands over what it finished, so only a running one is early
if ($batch->getStatus() === Status::InProgress) {
	fail('Batch is not finished yet, it is ' . $batch->getStatus()->name . '.');
}

// results arrive one by one as they are downloaded, so a batch of pictures never has to
// fit in memory; each carries either the answer or the reason that one request failed
foreach ($batch->getResults() as $customId => $result) {
	if ($result->message === null) {
		echo $customId, ' FAILED: ', $result->error, "\n";
		continue;
	}

	if ($result->message->getText() !== '') {
		echo $customId, ': ', $result->message->getText(), "\n";
	}

	// a batch of images answers with pictures rather than words, in the very same messages
	foreach ($result->message->getMedia() as $i => $media) {
		$file = sys_get_temp_dir() . "/aiaccess-$customId-$i." . explode('/', $media->getMimeType())[1];
		$media->save($file);
		echo $customId, ': saved to ', $file, "\n";
	}
}
