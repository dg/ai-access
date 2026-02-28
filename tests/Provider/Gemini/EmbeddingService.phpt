<?php declare(strict_types=1);

use AIAccess\Embedding\Vector;
use AIAccess\LogicException;
use AIAccess\Provider\Gemini\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('calculateEmbeddings rejects empty input', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->makePartial();
	$clientMock->shouldNotReceive('callApi');

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('gemini-embedding-2', []),
		AIAccess\LogicException::class,
		'Input cannot be empty.',
	);
});


test('calculateEmbeddings throws exception for empty string in input', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->makePartial();

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('embedding-001', ['text1', '']),
		LogicException::class,
		'All input elements must be non-empty strings.',
	);
});


test('calculateEmbeddings basic functionality', function () {
	$model = 'embedding-001';
	$input = ['Hello world', 'Test embedding'];

	// Mock embedding values for each input
	$mockValues1 = [0.1, 0.2, 0.3];
	$mockValues2 = [0.4, 0.5, 0.6];

	$expectedResponse = [
		'embeddings' => [
			['values' => $mockValues1],
			['values' => $mockValues2],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("models/{$model}:batchEmbedContents", Mockery::on(function ($payload) use ($model, $input) {
			// Verify payload structure
			if (!isset($payload['requests']) || count($payload['requests']) !== 2) {
				return false;
			}

			// Check each request has correct model and content
			foreach ($payload['requests'] as $index => $request) {
				if ($request['model'] !== "models/{$model}" ||
					$request['content']['parts'][0]['text'] !== $input[$index]) {
					return false;
				}
			}

			return true;
		}))
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input);

	Assert::count(2, $results);
	Assert::type(Vector::class, $results[0]);
	Assert::type(Vector::class, $results[1]);

	Assert::same($mockValues1, $results[0]->toArray());
	Assert::same($mockValues2, $results[1]->toArray());
});


test('calculateEmbeddings with taskType parameter', function () {
	$model = 'embedding-001';
	$input = ['Query text'];
	$taskType = 'RETRIEVAL_QUERY';

	$mockValues = [0.1, 0.2, 0.3];
	$expectedResponse = [
		'embeddings' => [
			['values' => $mockValues],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("models/{$model}:batchEmbedContents", Mockery::on(fn($payload) => $payload['requests'][0]['embedContentConfig']['taskType'] === $taskType))
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input, $taskType);

	Assert::count(1, $results);
	Assert::type(Vector::class, $results[0]);
});


test('calculateEmbeddings with title for document retrieval', function () {
	$model = 'embedding-001';
	$input = ['Document content'];
	$taskType = 'RETRIEVAL_DOCUMENT';
	$title = 'Document Title';

	$mockValues = [0.1, 0.2, 0.3];
	$expectedResponse = [
		'embeddings' => [
			['values' => $mockValues],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("models/{$model}:batchEmbedContents", Mockery::on(fn($payload) => $payload['requests'][0]['embedContentConfig']['taskType'] === $taskType && $payload['requests'][0]['embedContentConfig']['title'] === $title))
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input, $taskType, $title);

	Assert::count(1, $results);
	Assert::type(Vector::class, $results[0]);
});


test('calculateEmbeddings with outputDimensionality parameter', function () {
	$model = 'embedding-001';
	$input = ['Dimension test'];
	$dimensionality = 128;

	$mockValues = array_fill(0, $dimensionality, 0.1);
	$expectedResponse = [
		'embeddings' => [
			['values' => $mockValues],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with("models/{$model}:batchEmbedContents", Mockery::on(fn($payload) => $payload['requests'][0]['embedContentConfig']['outputDimensionality'] === $dimensionality))
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input, null, null, $dimensionality);

	Assert::count(1, $results);
	Assert::type(Vector::class, $results[0]);
	Assert::count($dimensionality, $results[0]->toArray());
});


test('calculateEmbeddings rejects title without RETRIEVAL_DOCUMENT', function () {
	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->shouldNotReceive('callApi');

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('gemini-embedding-2', ['text'], 'RETRIEVAL_QUERY', 'Title'),
		AIAccess\LogicException::class,
	);
});


test('calculateEmbeddings fails when the count does not match the input', function () {
	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn(['embeddings' => [['values' => [0.1]], ['values' => [0.2]]]]);

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('gemini-embedding-2', ['Text 1', 'Text 2', 'Text 3']),
		AIAccess\UnexpectedResponseException::class,
	);
});


test('calculateEmbeddings handles API errors', function () {
	$model = 'embedding-001';
	$input = ['Error test'];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andThrow(new AIAccess\ApiException('API error', 500));

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings($model, $input),
		AIAccess\ApiException::class,
		'API error',
	);
});


test('calculateEmbeddings fails on a malformed API response', function () {
	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn(['unexpected_key' => 'unexpected_value']);

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('gemini-embedding-2', ['Text']),
		AIAccess\UnexpectedResponseException::class,
	);
});
