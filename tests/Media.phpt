<?php declare(strict_types=1);

use AIAccess\Media;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('carries data, mime type and the raw response', function () {
	$media = new Media('binary', 'image/png', ['raw' => true]);

	Assert::same('binary', $media->getData());
	Assert::same('image/png', $media->getMimeType());
	Assert::same(['raw' => true], $media->getRawResponse());
});


test('fromBinary leaves the raw response empty', function () {
	$media = Media::fromBinary('binary', 'application/pdf');

	Assert::same('binary', $media->getData());
	Assert::same('application/pdf', $media->getMimeType());
	Assert::null($media->getRawResponse());
});


test('fromFile detects the mime type', function () {
	$file = getTempDir() . '/pixel.gif';
	file_put_contents($file, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));

	$media = Media::fromFile($file);

	Assert::same('image/gif', $media->getMimeType());
	Assert::same(file_get_contents($file), $media->getData());
});


test('fromFile reports a missing file', function () {
	Assert::exception(
		fn() => Media::fromFile(getTempDir() . '/nope.png'),
		AIAccess\IOException::class,
	);
});


test('base64 is computed lazily and cached', function () {
	$media = new Media('binary', 'image/png');

	Assert::same(base64_encode('binary'), $media->getBase64());
	Assert::same(base64_encode('binary'), $media->getBase64());
});


test('save writes the file', function () {
	$file = getTempDir() . '/image.png';
	(new Media('binary', 'image/png'))->save($file);

	Assert::same('binary', file_get_contents($file));
});


test('save reports an unwritable path', function () {
	Assert::exception(
		fn() => (new Media('x', 'image/png'))->save(getTempDir() . '/nope/deep/image.png'),
		AIAccess\IOException::class,
	);
});
