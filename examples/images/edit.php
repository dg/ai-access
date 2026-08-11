<?php declare(strict_types=1);

/**
 * Generating from reference images instead of from words alone.
 *
 * Demonstrates: Image\Service::generateImage() with references
 * Providers:    openai, gemini
 * Usage:        php examples/images/edit.php openai <reference.png> [more.png ...]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient(AIAccess\Image\Service::class);
if (!$client instanceof AIAccess\Provider\OpenAI\Client && !$client instanceof AIAccess\Provider\Gemini\Client) {
	fail('Only OpenAI and Gemini work from reference images.');
}

$paths = array_values(array_map(strval(...), array_slice($GLOBALS['argv'], 2)));
if (!$paths) {
	fail('Pass one or more reference images: php examples/images/edit.php openai cover.png');
}

$references = array_map(AIAccess\Media::fromFile(...), $paths);

// references are the provider's own argument, not part of Image\Service; OpenAI adds
// size, quality and background the same way
$image = $client->generateImage(
	'Keep the composition and palette, but make it a winter scene.',
	references: $references,
);

$file = sys_get_temp_dir() . '/aiaccess-edited.' . explode('/', $image->getMimeType())[1];
$image->save($file);

echo count($references), ' reference(s) -> ', number_format(strlen($image->getData()) / 1024), " kB\n";
echo "saved to $file\n";
