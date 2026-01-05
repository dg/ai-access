<?php declare(strict_types=1);

use AIAccess\CommunicationException;
use AIAccess\Http\CurlClient;
use AIAccess\Http\CurlMocker;
use AIAccess\Http\FormData;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/curl_functions.php';


setUp(fn() => CurlMocker::reset());

test('GET request returns proper response', function () {
	$headers = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
	$body = '{"status":"success"}';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 200;
	CurlMocker::$contentType = 'application/json';

	$client = new CurlClient;
	$response = $client->fetch('https://api.example.com/data');

	Assert::same(200, $response->getStatusCode());
	Assert::same('application/json', $response->getHeader('content-type'));
	Assert::same(['status' => 'success'], $response->getData());
});


test('POST request with JSON data', function () {
	$headers = "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\n\r\n";
	$body = '{"id":123,"name":"Test"}';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 201;
	CurlMocker::$contentType = 'application/json';

	$client = new CurlClient;
	$payload = ['name' => 'Test', 'description' => 'Test description'];
	$response = $client->fetch('https://api.example.com/items', $payload);

	Assert::same(201, $response->getStatusCode());
	Assert::same(['id' => 123, 'name' => 'Test'], $response->getData());
});


test('FormData upload handling', function () {
	$tempFile = getTempDir() . '/upload-test.txt';
	file_put_contents($tempFile, 'Test file content');

	$headers = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
	$body = '{"uploaded":true,"file_size":17}';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 200;
	CurlMocker::$contentType = 'application/json';

	$formData = new FormData;
	$formData->addField('description', 'Test file upload')
		->addFile('file', $tempFile)
		->addFileContent('inline_content', 'Inline content', 'content.txt');

	$client = new CurlClient;
	$response = $client->fetch('https://api.example.com/upload', $formData);

	Assert::same(200, $response->getStatusCode());
	Assert::same(['uploaded' => true, 'file_size' => 17], $response->getData());

	unlink($tempFile);
});


test('Multiple headers with same name', function () {
	$headers = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nSet-Cookie: token=123\r\nSet-Cookie: session=abc\r\n\r\n";
	$body = '{}';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 200;

	$client = new CurlClient;
	$response = $client->fetch('https://api.example.com/session');

	$cookie = $response->getHeader('set-cookie');
	Assert::same('token=123', $cookie);

	$cookies = $response->getHeaders('set-cookie');
	Assert::type('array', $cookies);
	Assert::count(2, $cookies);
	Assert::contains('token=123', $cookies);
	Assert::contains('session=abc', $cookies);
});


test('Non-JSON response handling', function () {
	$headers = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n";
	$body = 'Plain text response';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 200;
	CurlMocker::$contentType = 'text/plain';

	$client = new CurlClient;
	$response = $client->fetch('https://api.example.com/text');

	Assert::same('Plain text response', $response->getData());
	Assert::same('text/plain', $response->getHeader('content-type'));
});


test('Network error handling', function () {
	CurlMocker::$response = false;
	CurlMocker::$errno = 6; // CURLE_COULDNT_RESOLVE_HOST
	CurlMocker::$error = 'Could not resolve host: api.example.com';

	$client = new CurlClient;

	Assert::exception(
		fn() => $client->fetch('https://api.example.com/data'),
		CommunicationException::class,
		'cURL request failed: [6] Could not resolve host: api.example.com',
	);
});


test('Invalid JSON response handling', function () {
	$headers = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
	$body = '{invalid json:data}';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 200;
	CurlMocker::$contentType = 'application/json';

	$client = new CurlClient;

	Assert::exception(
		fn() => $client->fetch('https://api.example.com/data'),
		CommunicationException::class,
		'Invalid JSON response from API: Syntax error',
	);
});

test('Custom options and proxy setting', function () {
	$headers = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";
	$body = '{"status":"success"}';
	CurlMocker::$response = $headers . $body;
	CurlMocker::$headerSize = strlen($headers);
	CurlMocker::$httpCode = 200;

	$client = new CurlClient;
	$client->setOptions(
		connectTimeout: 5,
		requestTimeout: 30,
		proxy: 'http://proxy.example.com:8080',
	);

	$response = $client->fetch('https://api.example.com/data');
	Assert::same(200, $response->getStatusCode());
});
