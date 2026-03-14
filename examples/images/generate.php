<?php declare(strict_types=1);

/**
 * Turning a prompt into a picture.
 *
 * Demonstrates: Image\Service::generateImage(), Media::save()
 * Providers:    openai, grok
 * Usage:        php examples/images/generate.php [openai|grok]
 */

require __DIR__ . '/../bootstrap.php';

$client = createClient(AIAccess\Image\Service::class);
assert($client instanceof AIAccess\Image\Service);

$image = $client->generateImage(
	imageModel($client),
	'A lighthouse made of stacked books, storm clouds behind it, painterly style.',
);

// the two providers return different formats, so the mime type decides the name
$extension = explode('/', $image->getMimeType())[1];
$file = sys_get_temp_dir() . '/aiaccess-lighthouse.' . $extension;
$image->save($file);

echo $image->getMimeType(), ', ', number_format(strlen($image->getData()) / 1024), " kB\n";
echo "saved to $file\n";
