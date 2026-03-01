<?php declare(strict_types=1);

use AIAccess\Embedding\Vector;
use AIAccess\LogicException;
use AIAccess\Provider\OpenAI\Client;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';


test('calculateEmbeddings throws exception for empty input', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->makePartial();

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('text-embedding-ada-002', []),
		LogicException::class,
		'Input cannot be empty.',
	);
});


test('calculateEmbeddings throws exception for empty string in input', function () {
	$clientMock = Mockery::mock(Client::class);
	$clientMock->makePartial();

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('text-embedding-ada-002', ['text1', '']),
		LogicException::class,
		'All input elements must be non-empty strings.',
	);
});


test('calculateEmbeddings basic functionality', function () {
	$model = 'text-embedding-ada-002';
	$input = ['Hello world', 'Test embedding'];

	// Mock embedding values for each input
	$mockEmbedding1 = [0.1, 0.2, 0.3];
	$mockEmbedding2 = [0.4, 0.5, 0.6];

	$expectedResponse = [
		'data' => [
			['index' => 0, 'embedding' => $mockEmbedding1],
			['index' => 1, 'embedding' => $mockEmbedding2],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with('embeddings', Mockery::on(fn($payload) => $payload['model'] === $model && $payload['input'] === $input && !isset($payload['dimensions'])))
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input);

	Assert::count(2, $results);
	Assert::type(Vector::class, $results[0]);
	Assert::type(Vector::class, $results[1]);

	Assert::same($mockEmbedding1, $results[0]->toArray());
	Assert::same($mockEmbedding2, $results[1]->toArray());
});


test('calculateEmbeddings with dimensions parameter for text-embedding-3 model', function () {
	$model = 'text-embedding-3-small';
	$input = ['Dimension test'];
	$dimensions = 256;

	$mockEmbedding = array_fill(0, $dimensions, 0.1);
	$expectedResponse = [
		'data' => [
			['index' => 0, 'embedding' => $mockEmbedding],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->with('embeddings', Mockery::on(fn($payload) => $payload['model'] === $model && $payload['input'] === $input && $payload['dimensions'] === $dimensions))
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input, $dimensions);

	Assert::count(1, $results);
	Assert::type(Vector::class, $results[0]);
	Assert::count($dimensions, $results[0]->toArray());
});


test('calculateEmbeddings handles unordered response indices', function () {
	$model = 'text-embedding-ada-002';
	$input = ['First', 'Second', 'Third'];

	// Response with out-of-order indices
	$expectedResponse = [
		'data' => [
			['index' => 2, 'embedding' => [0.5, 0.6]],
			['index' => 0, 'embedding' => [0.1, 0.2]],
			['index' => 1, 'embedding' => [0.3, 0.4]],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn($expectedResponse);

	$results = $clientMock->calculateEmbeddings($model, $input);

	Assert::count(3, $results);

	// Results should be re-ordered based on index
	Assert::same([0.1, 0.2], $results[0]->toArray());
	Assert::same([0.3, 0.4], $results[1]->toArray());
	Assert::same([0.5, 0.6], $results[2]->toArray());
});


test('calculateEmbeddings fails when an item could not be embedded', function () {
	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn(['data' => [
			['index' => 0, 'embedding' => [0.1, 0.2]],
			['index' => 1, 'error' => ['message' => 'Too long']],
		]]);

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('text-embedding-3-small', ['a', 'b']),
		AIAccess\UnexpectedResponseException::class,
	);
});


test('calculateEmbeddings fails when the count does not match the input', function () {
	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn(['data' => [['index' => 0, 'embedding' => [0.1]]]]);

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('text-embedding-3-small', ['a', 'b']),
		AIAccess\UnexpectedResponseException::class,
	);
});


test('calculateEmbeddings handles API errors', function () {
	$model = 'text-embedding-ada-002';
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
		->andReturn(['unexpected' => true]);

	Assert::exception(
		fn() => $clientMock->calculateEmbeddings('text-embedding-3-small', ['a']),
		AIAccess\UnexpectedResponseException::class,
	);
});
