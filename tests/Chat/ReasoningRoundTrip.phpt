<?php declare(strict_types=1);

use AIAccess\Chat\ReasoningPart;
use AIAccess\Chat\Role;
use AIAccess\Chat\TextPart;
use AIAccess\Chat\ToolCallPart;
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
	Assert::notContains((string) $first->getReasoning(), $first->getText());

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


test('openai leaves no empty turn behind either', function () {
	$http = (new FakeHttpClient)->queue(fixture('openai/chat'))->queue(fixture('openai/chat'));
	$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('m');

	$chat->addMessage('Question', Role::User);
	$chat->addMessage([new ReasoningPart('thought', 'gemini', ['thoughtSignature' => 'x'])], Role::Model);
	$chat->addMessage('', Role::User);
	$chat->sendMessage('Next');

	Assert::same(['Question', 'Next'], array_column($http->lastPayload()['input'], 'content'));

	// a text part with nothing in it does not make an item of its own next to a call
	$chat->clearMessages();
	$chat->addMessage([new TextPart(''), new ToolCallPart('call_1', 'get_time', [])], Role::Model);
	$chat->sendMessage('Again');

	$types = array_map(fn($item) => $item['type'] ?? $item['role'], $http->lastPayload()['input']);
	Assert::same(['function_call', 'user'], $types);
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


test('no provider puts an empty text block next to real content', function () {
	// measured at Anthropic: "text content blocks must be non-empty" is a 400
	$cases = [
		'openai' => [['output' => []], 'input', fn($turn) => $turn['content']],
		'claude' => [['content' => []], 'messages', fn($turn) => $turn['content']],
		'gemini' => [['candidates' => []], 'contents', fn($turn) => $turn['parts']],
		'grok' => [['choices' => []], 'messages', fn($turn) => $turn['content']],
	];

	foreach ($cases as $name => [$answer, $key, $blocksOf]) {
		$http = (new FakeHttpClient)->queue($answer);
		$chat = match ($name) {
			'openai' => (new AIAccess\Provider\OpenAI\Client('k', $http))->createChat('m'),
			'claude' => (new AIAccess\Provider\Claude\Client('k', $http))->createChat('m'),
			'gemini' => (new AIAccess\Provider\Gemini\Client('k', $http))->createChat('m'),
			'grok' => (new AIAccess\Provider\Grok\Client('k', $http))->createChat('m'),
		};
		$chat->addMessage([new TextPart(''), AIAccess\Media::fromBinary('PNG', 'image/png')], Role::User);
		$chat->sendMessage();

		$blocks = $blocksOf($http->lastPayload()[$key][0]);
		Assert::count(1, $blocks, $name);
		Assert::same([], array_filter($blocks, fn($block) => ($block['text'] ?? null) === ''), $name);
	}
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


test('deepseek keeps reasoning readable but out of a plain chat payload', function () {
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

	Assert::same('', $response->getText());
	Assert::count(2, $chat->getMessages());
	Assert::same(Role::Model, $chat->getMessages()[1]->getRole());
	Assert::same('', $chat->getMessages()[1]->getText());
});
