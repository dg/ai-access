<?php declare(strict_types=1);

use AIAccess\ApiException;
use AIAccess\CommunicationException;
use AIAccess\Http\Client as HttpClient;
use AIAccess\Http\Response;
use AIAccess\Provider\Claude\Client;
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
			'https://api.anthropic.com/messages',
			$expectedPayload,
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);
	$result = $client->callApi('messages', $expectedPayload);

	Assert::same($expectedResponse, $result);
});


test('API headers are correctly set', function () {
	$apiKey = 'test-api-key-12345';
	$apiVersion = '2023-06-01';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::any(),
			Mockery::any(),
			Mockery::on(fn($headers) => $headers['x-api-key'] === $apiKey && $headers['Anthropic-Version'] === $apiVersion),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->callApi('messages', []);
});


test('setOptions changes base URL and API version', function () {
	$customBaseUrl = 'https://custom.anthropic.com/v2';
	$customApiVersion = 'custom-version-123';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			$customBaseUrl . '/messages',
			Mockery::any(),
			Mockery::on(fn($headers) => $headers['Anthropic-Version'] === $customApiVersion),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client('test-api-key', $httpClient);
	$client->setOptions($customBaseUrl, $customApiVersion);
	$client->callApi('messages', []);
});


test('Base URL formatting handles trailing slashes correctly', function () {
	$httpClient = Mockery::mock(HttpClient::class);

	// With trailing slash
	$httpClient->expects()
		->fetch(
			'https://api.test.com/messages',
			Mockery::any(),
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client('test-api-key', $httpClient);
	$client->setOptions('https://api.test.com/');
	$client->callApi('messages', []);
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
		fn() => $client->callApi('messages', ['model' => 'claude-3-sonnet-20240229']),
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
		fn() => $client->callApi('messages', []),
		ApiException::class,
		'Claude API error (HTTP 500)',
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
			fn() => $client->callApi('messages', []),
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
		fn() => $client->callApi('messages', ['model' => 'claude-3-sonnet-20240229']),
		ApiException::class,
		'Invalid JSON response from Claude API',
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
		fn() => $client->callApi('messages', ['model' => 'claude-3-sonnet-20240229']),
		CommunicationException::class,
		'Connection timeout',
	);
});


test('URL handling for absolute and relative endpoints', function () {
	// Test relative endpoint
	$mockResponse1 = Mockery::mock(Response::class);
	$mockResponse1->allows()->getStatusCode()->andReturn(200);
	$mockResponse1->allows()->getData()->andReturn(['success' => true]);

	// Test absolute endpoint
	$mockResponse2 = Mockery::mock(Response::class);
	$mockResponse2->allows()->getStatusCode()->andReturn(200);
	$mockResponse2->allows()->getData()->andReturn(['success' => true]);

	$httpClient = Mockery::mock(HttpClient::class);

	// The base URL should be prepended to the relative endpoint
	$httpClient->expects()
		->fetch(
			'https://api.anthropic.com/messages',
			Mockery::type('array'),
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse1);

	// The absolute URL should be used as-is
	$httpClient->expects()
		->fetch(
			'https://custom.anthropic.com/messages',
			Mockery::type('array'),
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse2);

	$client = new Client('test-api-key', $httpClient);

	// Test relative endpoint (should use base URL)
	$result1 = $client->callApi('messages', ['prompt' => 'test']);
	Assert::same(['success' => true], $result1);

	// Test absolute endpoint (should not use base URL)
	$result2 = $client->callApi('https://custom.anthropic.com/messages', ['prompt' => 'test']);
	Assert::same(['success' => true], $result2);
});
