<?php declare(strict_types=1);

use AIAccess\ApiException;
use AIAccess\CommunicationException;
use AIAccess\Http\Client as HttpClient;
use AIAccess\Http\Response;
use AIAccess\Provider\DeepSeek\Client;
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
			'https://api.deepseek.com/completions',
			$expectedPayload,
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);
	$result = $client->callApi('completions', $expectedPayload);

	Assert::same($expectedResponse, $result);
});


test('Authorization header is correctly set', function () {
	$apiKey = 'test-api-key-12345';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::any(),
			Mockery::any(),
			Mockery::on(fn($headers) => $headers['Authorization'] === 'Bearer ' . $apiKey),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->callApi('completions', []);
});


test('setOptions changes base URL', function () {
	$customBaseUrl = 'https://custom.deepseek.com/v2';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			$customBaseUrl . '/completions',
			Mockery::any(),
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client('test-api-key', $httpClient);
	$client->setOptions($customBaseUrl);
	$client->callApi('completions', []);
});


test('Base URL formatting handles trailing slashes correctly', function () {
	$httpClient = Mockery::mock(HttpClient::class);

	// With trailing slash
	$httpClient->expects()
		->fetch(
			'https://api.test.com/completions',
			Mockery::any(),
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client('test-api-key', $httpClient);
	$client->setOptions('https://api.test.com/');
	$client->callApi('completions', []);
});


test('API error response throws ApiException', function () {
	$errorResponse = [
		'error' => [
			'message' => 'Invalid API key',
			'type' => 'authentication_error',
		],
	];

	$mockResponse = Mockery::mock(Response::class);
	$mockResponse->allows()
		->getStatusCode()
		->andReturn(401);
	$mockResponse->allows()
		->getData()
		->andReturn($errorResponse);

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::type('string'),
			Mockery::type('array'),
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('invalid-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('completions', ['model' => 'deepseek-coder']),
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
	$httpClient->expects()->fetch(Mockery::any(), Mockery::any(), Mockery::any())
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('completions', []),
		ApiException::class,
		'DeepSeek API error (HTTP 500)',
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
		$httpClient->expects()->fetch(Mockery::any(), Mockery::any(), Mockery::any())
			->andReturn($mockResponse);

		$client = new Client('test-api-key', $httpClient);

		Assert::exception(
			fn() => $client->callApi('completions', []),
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
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('completions', ['model' => 'deepseek-coder']),
		ApiException::class,
		'Invalid JSON response from DeepSeek API',
	);
});


test('Network error propagates from HTTP client', function () {
	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::type('string'),
			Mockery::type('array'),
			Mockery::type('array'),
		)
		->once()
		->andThrow(new CommunicationException('Connection timeout', 408));

	$client = new Client('test-api-key', $httpClient);

	Assert::exception(
		fn() => $client->callApi('completions', ['model' => 'deepseek-coder']),
		CommunicationException::class,
		'Connection timeout',
	);
});
