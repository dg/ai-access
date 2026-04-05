<?php declare(strict_types=1);

use AIAccess\Provider;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


test('providers with a flat list return it as Model objects', function () {
	foreach ([Provider\OpenAI\Client::class, Provider\DeepSeek\Client::class, Provider\Grok\Client::class] as $class) {
		$http = (new FakeHttpClient)->queue(['data' => [
			['id' => 'model-a', 'created' => 1],
			['id' => 'model-b'],
			['no_id' => true],
		]]);

		$models = (new $class('k', $http))->listModels();

		Assert::count(2, $models, $class);
		Assert::same('model-a', $models[0]->id);
		Assert::same(['id' => 'model-a', 'created' => 1], $models[0]->raw);
	}
});


test('Claude follows has_more pagination', function () {
	$http = (new FakeHttpClient)
		->queue(['data' => [['id' => 'a'], ['id' => 'b']], 'has_more' => true, 'last_id' => 'b'])
		->queue(['data' => [['id' => 'c']], 'has_more' => false]);

	$models = (new Provider\Claude\Client('k', $http))->listModels();

	Assert::same(['a', 'b', 'c'], array_column($models, 'id'));
	Assert::contains('after_id=b', $http->lastRequest()['url']);
});


test('Claude stops when has_more is true but the cursor is missing', function () {
	$http = (new FakeHttpClient)->queue(['data' => [['id' => 'a']], 'has_more' => true]);

	Assert::same(['a'], array_column((new Provider\Claude\Client('k', $http))->listModels(), 'id'));
});


test('Gemini follows page tokens and strips the models/ prefix', function () {
	$http = (new FakeHttpClient)
		->queue(['models' => [['name' => 'models/gemini-a']], 'nextPageToken' => 'tok'])
		->queue(['models' => [['name' => 'models/gemini-b']]]);

	$models = (new Provider\Gemini\Client('k', $http))->listModels();

	Assert::same(['gemini-a', 'gemini-b'], array_column($models, 'id'));
	Assert::contains('pageToken=tok', $http->lastRequest()['url']);
});


test('listing is a GET, so no payload goes out', function () {
	$http = (new FakeHttpClient)->queue(['data' => []]);
	(new Provider\Grok\Client('k', $http))->listModels();

	Assert::null($http->lastRequest()['payload']);
});
