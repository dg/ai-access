<?php declare(strict_types=1);

/**
 * What the provider is actually offering right now.
 *
 * Demonstrates: listModels(), Model::$id, Model::$raw
 * Providers:    all
 * Usage:        php examples/models/list.php [openai|claude|gemini|deepseek|grok] [filter]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient();
assert(method_exists($client, 'listModels'));

$filter = arg(1);
$models = $client->listModels();

foreach ($models as $model) {
	if ($filter === null || str_contains($model->id, $filter)) {
		echo $model->id, "\n";
	}
}

echo "\n", count($models), " models offered\n";

// the model the examples default to should be among them; if it is not,
// it was retired and everything else here is about to break
$default = chatModel($client);
echo 'default model ', $default, ': ',
	in_array($default, array_column($models, 'id'), strict: true) ? "available\n" : "NOT OFFERED\n";
