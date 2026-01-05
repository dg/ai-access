<?php declare(strict_types=1);

use AIAccess\ApiException;
use AIAccess\CommunicationException;
use AIAccess\Http\Client as HttpClient;
use AIAccess\Http\Response;
use AIAccess\Provider\OpenAI\Client;
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
			'https://api.openai.com/v1/chat/completions',
			$expectedPayload,
			Mockery::type('array'),
		)
		->once()
		->andReturn($mockResponse);

	$client = new Client('test-api-key', $httpClient);
	$result = $client->callApi('chat/completions', $expectedPayload);

	Assert::same($expectedResponse, $result);
});


test('API headers are correctly set', function () {
	$apiKey = 'openai-api-key-12345';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::any(),
			Mockery::any(),
			Mockery::on(fn($headers) => $headers['Authorization'] === 'Bearer ' . $apiKey
					&& !isset($headers['OpenAI-Organization'])),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->callApi('chat/completions', []);
});


test('Organization ID is included in headers when set', function () {
	$apiKey = 'openai-api-key-12345';
	$orgId = 'org-abcdefg12345';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			Mockery::any(),
			Mockery::any(),
			Mockery::on(fn($headers) => $headers['Authorization'] === 'Bearer ' . $apiKey
					&& $headers['OpenAI-Organization'] === $orgId),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client($apiKey, $httpClient);
	$client->setOptions(null, $orgId);
	$client->callApi('chat/completions', []);
});


test('setOptions changes base URL and organization ID', function () {
	$customBaseUrl = 'https://custom.openai.com/v2';
	$orgId = 'org-custom12345';

	$httpClient = Mockery::mock(HttpClient::class);
	$httpClient->expects()
		->fetch(
			$customBaseUrl . '/chat/completions',
			Mockery::any(),
			Mockery::on(fn($headers) => $headers['OpenAI-Organization'] === $orgId),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client('test-api-key', $httpClient);
	$client->setOptions($customBaseUrl, $orgId);
	$client->callApi('chat/completions', []);
});


test('Base URL formatting handles trailing slashes correctly', function () {
	$httpClient = Mockery::mock(HttpClient::class);

	// With trailing slash
	$httpClient->expects()
		->fetch(
			'https://api.test.com/chat/completions',
			Mockery::any(),
			Mockery::any(),
		)
		->once()
		->andReturn(mockSuccessResponse());

	$client = new Client('test-api-key', $httpClient);
	$client->setOptions('https://api.test.com/');
	$client->callApi('chat/completions', []);
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
		fn() => $client->callApi('chat/completions', ['model' => 'gpt-4']),
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
		fn() => $client->callApi('chat/completions', []),
		ApiException::class,
		'OpenAI API error (HTTP 500)',
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
			fn() => $client->callApi('chat/completions', []),
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
		fn() => $client->callApi('chat/completions', ['model' => 'gpt-4']),
		ApiException::class,
		'Invalid JSON response from OpenAI API',
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
		fn() => $client->callApi('chat/completions', ['model' => 'gpt-4']),
		CommunicationException::class,
		'Connection timeout',
	);
});
