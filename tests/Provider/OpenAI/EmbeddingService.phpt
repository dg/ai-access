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


test('calculateEmbeddings warns when using dimensions with non-text-embedding-3 model', function () {
	$model = 'text-embedding-ada-002';
	$input = ['Test'];
	$dimensions = 256;

	$mockEmbedding = [0.1, 0.2, 0.3];
	$expectedResponse = [
		'data' => [
			['index' => 0, 'embedding' => $mockEmbedding],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn($expectedResponse);

	Assert::error(function () use ($clientMock, $model, $input, $dimensions) {
		$results = $clientMock->calculateEmbeddings($model, $input, $dimensions);

		// Should still return results despite the warning
		Assert::count(1, $results);
	}, E_USER_WARNING, "The 'dimensions' parameter is only supported for text-embedding-3 models.");
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


test('calculateEmbeddings warns about errors in individual embeddings', function () {
	$model = 'text-embedding-ada-002';
	$input = ['Valid text', 'Error text'];

	// Response with one valid embedding and one error
	$expectedResponse = [
		'data' => [
			['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
			['index' => 1, 'error' => ['message' => 'Content policy violation']],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn($expectedResponse);

	Assert::error(function () use ($clientMock, $model, $input) {
		$results = $clientMock->calculateEmbeddings($model, $input);

		// Should still return the valid embedding
		Assert::count(1, $results);
		Assert::same([0.1, 0.2, 0.3], $results[0]->toArray());
	}, [
		[E_USER_WARNING, 'Error processing input at index 1: Content policy violation'],
		[E_USER_WARNING, 'Number of returned embeddings (1) does not match the number of inputs (2). Check for errors in the raw response.'],
	]);
});


test('calculateEmbeddings warns when embedding count mismatches input count', function () {
	$model = 'text-embedding-ada-002';
	$input = ['Text 1', 'Text 2', 'Text 3'];

	// Only return 2 embeddings for 3 inputs
	$expectedResponse = [
		'data' => [
			['index' => 0, 'embedding' => [0.1, 0.2]],
			['index' => 1, 'embedding' => [0.3, 0.4]],
		],
	];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn($expectedResponse);

	Assert::error(function () use ($clientMock, $model, $input) {
		$results = $clientMock->calculateEmbeddings($model, $input);

		// Should still return the available embeddings
		Assert::count(2, $results);
	}, E_USER_WARNING, 'Number of returned embeddings (2) does not match the number of inputs (3). Check for errors in the raw response.');
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


test('calculateEmbeddings handles malformed API response', function () {
	$model = 'text-embedding-ada-002';
	$input = ['Malformed test'];

	// Response without 'data' array
	$malformedResponse = ['other' => 'data'];

	$clientMock = Mockery::mock(Client::class)->makePartial();
	$clientMock->expects('callApi')
		->once()
		->andReturn($malformedResponse);

	Assert::error(function () use ($clientMock, $model, $input) {
		$results = $clientMock->calculateEmbeddings($model, $input);

		// Should return empty array for malformed response
		Assert::count(0, $results);
	}, E_USER_WARNING, 'Number of returned embeddings (0) does not match the number of inputs (1). Check for errors in the raw response.');
});
