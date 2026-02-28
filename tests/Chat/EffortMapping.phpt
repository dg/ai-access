<?php declare(strict_types=1);

use AIAccess\Chat\Effort;
use AIAccess\Provider;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


/** @return mixed[] */
function payloadFor(string $clientClass, Effort $effort, FakeHttpClient $http): array
{
	$client = new $clientClass('k', $http);
	$client->createChat('m')->setEffort($effort)->sendMessage('x');
	return $http->lastPayload();
}


function replies(int $count): FakeHttpClient
{
	$http = new FakeHttpClient;
	for ($i = 0; $i < $count; $i++) {
		$http->queue([
			'content' => [['type' => 'text', 'text' => 'x']],
			'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'x']]]],
			'candidates' => [['content' => ['parts' => [['text' => 'x']]]]],
			'choices' => [['message' => ['content' => 'x']]],
		]);
	}
	return $http;
}


test('Claude sends effort, and disables thinking for None without touching effort', function () {
	$http = replies(3);

	$payload = payloadFor(Provider\Claude\Client::class, Effort::None, $http);
	Assert::same(['type' => 'disabled'], $payload['thinking']);
	Assert::false(isset($payload['output_config']));

	Assert::same('low', payloadFor(Provider\Claude\Client::class, Effort::Low, $http)['output_config']['effort']);
	Assert::same('xhigh', payloadFor(Provider\Claude\Client::class, Effort::XHigh, $http)['output_config']['effort']);
});


test('OpenAI maps the whole ladder onto reasoning.effort', function () {
	$http = replies(6);
	$expected = ['none', 'low', 'medium', 'high', 'xhigh', 'max'];

	foreach (Effort::cases() as $i => $effort) {
		$payload = payloadFor(Provider\OpenAI\Client::class, $effort, $http);
		Assert::same($expected[$i], $payload['reasoning']['effort']);
	}
});


test('Gemini uses a thinking budget of zero for None and levels otherwise', function () {
	$http = replies(3);

	$none = payloadFor(Provider\Gemini\Client::class, Effort::None, $http);
	Assert::same(['thinkingBudget' => 0], $none['generationConfig']['thinkingConfig']);

	$low = payloadFor(Provider\Gemini\Client::class, Effort::Low, $http);
	Assert::same(['thinkingLevel' => 'LOW'], $low['generationConfig']['thinkingConfig']);

	$max = payloadFor(Provider\Gemini\Client::class, Effort::Max, $http);
	Assert::same(['thinkingLevel' => 'HIGH'], $max['generationConfig']['thinkingConfig']);
});


test('Grok collapses the top of the ladder onto high', function () {
	$http = replies(3);

	Assert::same('none', payloadFor(Provider\Grok\Client::class, Effort::None, $http)['reasoning_effort']);
	Assert::same('medium', payloadFor(Provider\Grok\Client::class, Effort::Medium, $http)['reasoning_effort']);
	Assert::same('high', payloadFor(Provider\Grok\Client::class, Effort::Max, $http)['reasoning_effort']);
});


test('nothing is sent when the user did not ask for it', function () {
	$http = replies(5);

	foreach ([Provider\Claude\Client::class, Provider\OpenAI\Client::class, Provider\Gemini\Client::class,
		Provider\DeepSeek\Client::class, Provider\Grok\Client::class] as $class) {
		$client = new $class('k', $http);
		$client->createChat('m')->sendMessage('x');
		$payload = $http->lastPayload();

		Assert::false(isset($payload['thinking']), $class);
		Assert::false(isset($payload['reasoning']), $class);
		Assert::false(isset($payload['reasoning_effort']), $class);
		Assert::false(isset($payload['output_config']), $class);
		Assert::false(isset($payload['generationConfig']['thinkingConfig']), $class);
	}
});
