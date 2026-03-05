<?php declare(strict_types=1);

/**
 * Storing vectors in a compact binary form, e.g. in a BLOB column.
 *
 * Demonstrates: Vector::serialize(), Vector::deserialize()
 * Providers:    openai, gemini
 * Usage:        php examples/embeddings/storage.php [openai|gemini]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient(AIAccess\Embedding\Service::class);
assert($client instanceof AIAccess\Embedding\Service);

$vectors = $client->calculateEmbeddings(embeddingModel($client), ['Text to remember.']);
$binary = $vectors[0]->serialize();

printf(
	"%d dimensions, %d bytes serialized (%.1f bytes per dimension)\n",
	count($vectors[0]->toArray()),
	strlen($binary),
	strlen($binary) / count($vectors[0]->toArray()),
);

$restored = AIAccess\Embedding\Vector::deserialize($binary);
printf("restored vector matches itself: %.6f\n", $restored->cosineSimilarity($vectors[0]));
