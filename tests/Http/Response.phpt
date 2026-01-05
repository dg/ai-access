<?php declare(strict_types=1);

use AIAccess\Http\Response;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('Response constructor and getters', function () {
	$statusCode = 200;
	$headers = ['content-type' => ['application/json']];
	$data = ['message' => 'ok'];

	$response = new Response($statusCode, $headers, $data);

	Assert::same($statusCode, $response->getStatusCode());
	Assert::same($data, $response->getData());
});


test('Response header lookup (case-insensitive)', function () {
	$headers = [
		'content-type' => ['application/json'],
		'x-rate-limit' => ['100'],
		'set-cookie' => ['a=1', 'b=2'],
	];

	$response = new Response(200, $headers, []);

	Assert::same('application/json', $response->getHeader('Content-Type'));
	Assert::same('application/json', $response->getHeader('content-type'));
	Assert::same('a=1', $response->getHeader('Set-Cookie'));
	Assert::same(['a=1', 'b=2'], $response->getHeaders('Set-Cookie'));
	Assert::null($response->getHeader('X-Not-Present'));
	Assert::same([], $response->getHeaders('X-Not-Present'));
});
