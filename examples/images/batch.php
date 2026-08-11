<?php declare(strict_types=1);

/**
 * Queues a pile of pictures at roughly half the price.
 *
 * Demonstrates: Batch\Service::createBatch(), Batch::addImageRequest()
 * Providers:    openai, gemini
 * Usage:        php examples/images/batch.php [openai|gemini]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient(AIAccess\Batch\Service::class);
assert($client instanceof AIAccess\Batch\Service);
$batch = $client->createBatch();

// batching pictures is not part of the shared interface, because Claude cannot draw
if (!$batch instanceof AIAccess\Provider\OpenAI\Batch && !$batch instanceof AIAccess\Provider\Gemini\Batch) {
	fail('This provider does not batch images. Supported: openai, gemini.');
}

$prompts = [
	'lighthouse' => 'A lighthouse made of stacked books, storm clouds behind it, painterly style.',
	'harbour' => 'A harbour at dawn, the same painterly style.',
	'cliff' => 'An empty cliff path under the same storm clouds, the same painterly style.',
];

foreach ($prompts as $customId => $prompt) {
	$batch->addImageRequest($customId, $prompt);
}

$response = $batch->submit();

echo 'batch id: ', $response->getId(), "\n";
echo 'status:   ', $response->getStatus()->name, "\n\n";
echo "Pictures arrive within minutes to 24 hours. Collect them with:\n";
echo '  php examples/batch/results.php ', $GLOBALS['argv'][1] ?? 'openai', ' ', $response->getId(), "\n";
