<?php declare(strict_types=1);

use AIAccess\ApiException;
use AIAccess\CommunicationException;
use AIAccess\Http\Client as HttpClient;
use AIAccess\Http\Response;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


// Helper function to create mock success response
function mockSuccessResponse(array $data = ['success' => true]): Response
{
	$response = Mockery::mock(Response::class);
	$response->allows()->getStatusCode()->andReturn(200);
	$response->allows()->getData()->andReturn($data);
	return $response;
}


test('Successful API call returns correct data', function () {
	$expectedResponse = ['foo' => 123];
	$expectedPayload = ['bar' => 123];

	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()
		->getStatusCode()
		->andReturn(200);
	$mockResponse->allows()
		->getData()
		->andReturn($expectedResponse);

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::on(fn($url) => str_starts_with($url, 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=')),
			$expectedPayload,
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);
	$result = $client->callApi('models/gemini-pro:generateContent', $expectedPayload);

	Assert::same($expectedResponse, $result);
});


test('API key is correctly appended to URL', function () {
	$apiKey = 'test-api-key-12345';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::on(fn($url) => str_contains($url, '?key=' . $apiKey)),
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->callApi('models/gemini-pro:generateContent', []);
});


test('setOptions changes base URL', function () {
	$customBaseUrl = 'https://custom-gemini.googleapis.com/v2';
	$apiKey = 'test-api-key';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			$customBaseUrl . '/models/gemini-pro:generateContent?key=' . $apiKey,
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->setOptions($customBaseUrl);
	$client->callApi('models/gemini-pro:generateContent', []);
});


test('Base URL formatting handles trailing slashes correctly', function () {
	$apiKey = 'test-api-key';
	$httpClient = Mockery::mock(HttpClient::class);

	$httpClient->expects()
		->fetch(
			'https://api.test.com/models/gemini-pro:generateContent?key=' . $apiKey,
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->setOptions('https://api.test.com/');
	$client->callApi('models/gemini-pro:generateContent', []);
});


test('API error response throws ApiException', function () {
	$errorResponse = [
		'error' => [
			'message' => 'Invalid API key',
			'status' => 'INVALID_ARGUMENT',
		],
	];

	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()
		->getStatusCode()
		->andReturn(400);
	$mockResponse->allows()
		->getData()
		->andReturn($errorResponse);

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::type('string'),
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('invalid-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('models/gemini-pro:generateContent', []),
		ApiException::class,
		'Invalid API key',
	);
});


test('Error without message shows generic error', function () {
	$errorResponse = ['error' => []]; // No message field

	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()->getStatusCode()->andReturn(500);
	$mockResponse->allows()->getData()->andReturn($errorResponse);

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::any(),
			Mockery::any(),
		)
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('models/gemini-pro:generateContent', []),
		ApiException::class,
		'Gemini API error (HTTP 500)',
	);
});


test('Various HTTP error codes are handled properly', function () {
	$errorCodes = [
		403 => 'Permission denied',
		404 => 'Resource not found',
		429 => 'Rate limit exceeded',
	];

	foreach ($errorCodes as $code => $message) {
		$errorResponse = [
			'error' => [
				'message' => $message,
			],
		];

		$mockResponse = Mockery::mock(Response::class);
		$mockResponse->allows()->getStatusCode()->andReturn($code);
		$mockResponse->allows()->getData()->andReturn($errorResponse);

		$httpClient = Mockery::mock(HttpClient::class);
		$httpClient->expects()
			->fetch(
				Mockery::any(),
				Mockery::any(),
			)
			->andReturn($mockResponse);

		$client = new Client('test-api-key', $httpClient);

		Assert::exception(
			fn() => $client->callApi('models/gemini-pro:generateContent', []),
			ApiException::class,
			$message,
		);
	}
});


test('Non-array response throws ApiException', function () {
	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()
		->getStatusCode()
		->andReturn(200);
	$mockResponse->allows()
		->getData()
		->andReturn('This is not a valid JSON response');

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::type('string'),
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('models/gemini-pro:generateContent', []),
		ApiException::class,
		'Invalid JSON response from Gemini API',
	);
});


test('Network error propagates from HTTP client', function () {
	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::type('string'),
			Mockery::type('array'),
		)
		->once()
		->andThrow(new CommunicationException('Connection timeout', 408));

	$client = new Client('test-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('models/gemini-pro:generateContent', []),
		CommunicationException::class,
		'Connection timeout',
	);
});
