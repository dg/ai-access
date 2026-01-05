<?php declare(strict_types=1);

use AIAccess\Http\FormData;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('FormData addField', function () {
	$form = new FormData;
	$form->addField('name', 'test');

	$items = $form->getItems();
	Assert::count(1, $items);
	Assert::same(['value' => 'test'], $items['name']);
});


test('FormData addFile with existing file', function () {
	$tempFile = getTempDir() . '/formdata-test.txt';
	file_put_contents($tempFile, 'test content');

	$form = new FormData;
	$form->addFile('file', $tempFile, 'custom-name.txt', 'text/plain');

	$items = $form->getItems();
	Assert::count(1, $items);
	Assert::same(
		['path' => $tempFile, 'name' => 'custom-name.txt', 'mime' => 'text/plain'],
		$items['file'],
	);

	unlink($tempFile);
});


test('FormData addFile with auto-detected filename', function () {
	$tempFile = getTempDir() . '/formdata-test.txt';
	file_put_contents($tempFile, 'test content');

	$form = new FormData;
	$form->addFile('file', $tempFile);

	$items = $form->getItems();
	Assert::count(1, $items);
	Assert::same(
		['path' => $tempFile, 'name' => 'formdata-test.txt', 'mime' => null],
		$items['file'],
	);

	unlink($tempFile);
});


test('FormData addFileContent', function () {
	$form = new FormData;
	$form->addFileContent('file', 'test content', 'test.txt', 'text/plain');

	$items = $form->getItems();
	Assert::count(1, $items);
	Assert::same(
		['content' => 'test content', 'name' => 'test.txt', 'mime' => 'text/plain'],
		$items['file'],
	);
});


test('FormData method chaining', function () {
	$form = new FormData;
	$result = $form
		->addField('field1', 'value1')
		->addFileContent('file1', 'content', 'file.txt', 'text/plain');

	Assert::same($form, $result);

	$items = $form->getItems();
	Assert::count(2, $items);
});


test('FormData invalid file path', function () {
	$form = new FormData;

	Assert::exception(
		fn() => $form->addFile('file', '/non/existent/path.txt'),
		AIAccess\LogicException::class,
		'File not found or not readable: /non/existent/path.txt',
	);
});
