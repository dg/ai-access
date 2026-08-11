<?php declare(strict_types=1);

/**
 * Comparing texts by meaning rather than by words.
 *
 * Demonstrates: Embedding\Service::calculateEmbeddings(), Vector::cosineSimilarity()
 * Providers:    openai, gemini
 * Usage:        php examples/embeddings/similarity.php [openai|gemini]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient(AIAccess\Embedding\Service::class);
assert($client instanceof AIAccess\Embedding\Service);

$texts = [
	'The cat is sleeping on the sofa.',
	'A kitten naps on the couch.',
	'PHP 8.3 introduced typed class constants.',
];

$vectors = $client->calculateEmbeddings($texts);

foreach ([[0, 1], [0, 2]] as [$a, $b]) {
	printf(
		"%.4f  %s <-> %s\n",
		$vectors[$a]->cosineSimilarity($vectors[$b]),
		$texts[$a],
		$texts[$b],
	);
}
