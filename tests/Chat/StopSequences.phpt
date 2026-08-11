<?php declare(strict_types=1);

use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


function geminiAnswer(): array
{
	return [
		'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'OK.']]], 'finishReason' => 'STOP']],
		'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
	];
}


test('one option name reaches whatever the wire calls it', function () {
	$cases = [
		[fixture('claude/chat'), fn($http) => (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m'), fn($p) => $p['stop_sequences']],
		[fixture('deepseek/chat'), fn($http) => (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('m'), fn($p) => $p['stop']],
		[fixture('grok/chat'), fn($http) => (new AIAccess\Provider\Grok\Client('key', $http))->createChat('m'), fn($p) => $p['stop']],
		[geminiAnswer(), fn($http) => (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('m'), fn($p) => $p['generationConfig']['stopSequences']],
	];

	foreach ($cases as [$answer, $createChat, $read]) {
		$http = (new FakeHttpClient)->queue($answer);
		$chat = $createChat($http);
		$chat->setOptions(stopSequences: ['STOP', 'END']);
		$chat->sendMessage('Q');

		Assert::same(['STOP', 'END'], $read($http->lastPayload()));
	}
});


test('a single sequence needs no array', function () {
	// the chat/completions dialect takes a bare string, the others insist on a list,
	// which is the library's business rather than the caller's
	$cases = [
		[fixture('claude/chat'), fn($http) => (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m'), fn($p) => $p['stop_sequences'], ['STOP']],
		[geminiAnswer(), fn($http) => (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('m'), fn($p) => $p['generationConfig']['stopSequences'], ['STOP']],
		[fixture('deepseek/chat'), fn($http) => (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('m'), fn($p) => $p['stop'], 'STOP'],
	];

	foreach ($cases as [$answer, $createChat, $read, $expected]) {
		$http = (new FakeHttpClient)->queue($answer);
		$chat = $createChat($http);
		$chat->setOptions(stopSequences: 'STOP');
		$chat->sendMessage('Q');

		Assert::same($expected, $read($http->lastPayload()));
	}
});
