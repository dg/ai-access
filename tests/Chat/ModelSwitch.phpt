<?php declare(strict_types=1);

use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


test('a switched model travels on the wire and the history stays', function () {
	$cases = [
		'openai/chat' => fn($http) => (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('first-model'),
		'claude/chat' => fn($http) => (new AIAccess\Provider\Claude\Client('key', $http))->createChat('first-model'),
		'deepseek/chat' => fn($http) => (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('first-model'),
	];

	foreach ($cases as $name => $createChat) {
		$http = (new FakeHttpClient)->queue(fixture($name))->queue(fixture($name));
		$chat = $createChat($http);

		$chat->sendMessage('Q1');
		Assert::same('first-model', $http->lastPayload()['model']);

		$chat->setModel('second-model');
		$chat->sendMessage('Q2');

		Assert::same('second-model', $http->lastPayload()['model']);
		Assert::same('Q1', $chat->getMessages()[0]->getText());
	}
});


test('Gemini carries the model in the URL, so a switch has to rewrite it', function () {
	$answer = [
		'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'OK.']]], 'finishReason' => 'STOP']],
		'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
	];
	$http = (new FakeHttpClient)->queue($answer)->queue($answer);

	$chat = (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('first-model');
	$chat->sendMessage('Q1');
	Assert::contains('models/first-model:generateContent', $http->lastRequest()['url']);

	$chat->setModel('second-model');
	$chat->sendMessage('Q2');
	Assert::contains('models/second-model:generateContent', $http->lastRequest()['url']);
});
