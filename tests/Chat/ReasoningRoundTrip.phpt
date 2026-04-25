<?php declare(strict_types=1);

use AIAccess\Chat\ReasoningPart;
use AIAccess\Chat\Role;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


test('claude replays thinking blocks with their signature', function () {
	$http = (new FakeHttpClient)
		->queue(fixture('claude/thinking'))
		->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');

	$first = $chat->sendMessage('27 * 453?');
	Assert::type('string', $first->getReasoning());
	Assert::notContains((string) $first->getReasoning(), (string) $first->getText());

	$chat->sendMessage('And doubled?');
	$assistant = $http->lastPayload()['messages'][1];

	Assert::same('assistant', $assistant['role']);
	Assert::same('thinking', $assistant['content'][0]['type']);
	Assert::same(fixture('claude/thinking')['content'][0]['signature'], $assistant['content'][0]['signature']);
	Assert::same('text', $assistant['content'][1]['type']);
});


test('a foreign payload is never replayed, and leaves no empty turn behind', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');

	$chat->addMessage('Question', Role::User);
	$chat->addMessage([new ReasoningPart('thought', 'gemini', ['thoughtSignature' => 'x'])], Role::Model);
	$chat->sendMessage('Next');

	$messages = $http->lastPayload()['messages'];
	Assert::same(['Question', 'Next'], array_column($messages, 'content'));
});


test('gemini drops an empty text turn, which its API rejects', function () {
	$http = (new FakeHttpClient)->queue(['candidates' => [['content' => ['parts' => [['text' => 'x']]]]]]);
	$chat = (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('m');

	$chat->addMessage('Question', Role::User);
	$chat->addMessage('Answer', Role::Model);
	$chat->addMessage('', Role::User);
	$chat->sendMessage('Next');

	$texts = array_map(fn($turn) => $turn['parts'][0]['text'], $http->lastPayload()['contents']);
	Assert::same(['Question', 'Answer', 'Next'], $texts);
});


test('gemini drops a turn it cannot replay, and says the roles stopped alternating', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('gemini-3.5-flash-lite');

	$chat->addMessage('Question', Role::User);
	$chat->addMessage([new ReasoningPart('thought', 'claude', ['type' => 'thinking'])], Role::Model);

	// dropping the model turn leaves two user turns side by side, which Gemini rejects
	Assert::error(
		fn() => $chat->sendMessage('Next'),
		E_USER_WARNING,
		'%a%alternating roles%a%',
	);

	Assert::count(2, $http->lastPayload()['contents']);
	foreach ($http->lastPayload()['contents'] as $content) {
		Assert::notSame([], $content['parts']);
	}
});


test('claude replays thinking blocks exactly where they arrived', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');

	// interleaved thinking puts blocks between tool calls; reordering them breaks the signature chain
	$chat->addMessage('Question', Role::User);
	$chat->addMessage([
		new ReasoningPart('thought', 'claude', ['type' => 'thinking', 'thinking' => 'thought', 'signature' => 'SIG']),
		new AIAccess\Chat\TextPart('Answer'),
		new ReasoningPart('more', 'claude', ['type' => 'thinking', 'thinking' => 'more', 'signature' => 'SIG2']),
	], Role::Model);
	$chat->sendMessage('Next');

	$content = $http->lastPayload()['messages'][1]['content'];
	Assert::same(['thinking', 'text', 'thinking'], array_column($content, 'type'));
	Assert::same('SIG2', $content[2]['signature']);
});


test('deepseek keeps reasoning readable but out of the payload', function () {
	$http = (new FakeHttpClient)
		->queue(fixture('deepseek/thinking'))
		->queue(fixture('deepseek/chat'));
	$chat = (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('deepseek-v4-flash');

	$response = $chat->sendMessage('Say OK.');
	Assert::type('string', $response->getReasoning());

	$chat->sendMessage('Again');
	foreach ($http->lastPayload()['messages'] as $message) {
		Assert::false(isset($message['reasoning_content']));
		Assert::notSame('', $message['content']);
	}
	// the reasoning-only turn carries nothing sendable, so it is not in the payload at all
	Assert::same(['Say OK.', 'Again'], array_column($http->lastPayload()['messages'], 'content'));
});


test('a turn carrying only reasoning still lands in the history', function () {
	$http = (new FakeHttpClient)->queue(fixture('deepseek/thinking'));
	$chat = (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('deepseek-v4-flash');

	$response = $chat->sendMessage('Say OK.');

	Assert::null($response->getText());
	Assert::count(2, $chat->getMessages());
	Assert::same(Role::Model, $chat->getMessages()[1]->getRole());
	Assert::same('', $chat->getMessages()[1]->getText());
});
