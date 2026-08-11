<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use AIAccess\Chat\Role;
use AIAccess\Chat\Tool;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


function openaiStreamingChat(FakeHttpClient $http): AIAccess\Chat\Chat
{
	$raw = fixtureRaw('openai/stream.sse.txt');
	$http->queueStream(str_split($raw, 57));
	return (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('gpt-5.6-luna');
}


function claudeEvent(string $name, array $data): string
{
	return "event: $name\ndata: " . json_encode($data) . "\n\n";
}


test('a broken-off foreach continues without repeating a chunk', function () {
	$stream = openaiStreamingChat(new FakeHttpClient)->sendMessageStream('Say: one two three');

	$pieces = [];
	foreach ($stream as $delta) {
		$pieces[] = $delta;
		if (count($pieces) >= 2) {
			break;
		}
	}
	foreach ($stream as $delta) {
		$pieces[] = $delta;
	}

	Assert::same($stream->getResponse()->getText(), implode('', $pieces));
});


test('getText() answers in full no matter how much was already read', function () {
	$stream = openaiStreamingChat(new FakeHttpClient)->sendMessageStream('Say: one two three');

	foreach ($stream as $delta) {
		break; // read one chunk and walk away
	}

	Assert::same('one two three', $stream->getText());
});


test('a cancelled stream leaves a history the next request can use', function () {
	$sse = claudeEvent('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. claudeEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'wipe_database', 'input' => []]])
		. claudeEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"confirm":true}']])
		. claudeEvent('content_block_stop', ['index' => 0])
		. claudeEvent('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
		. claudeEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Wiping now.']])
		. claudeEvent('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]]);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new Tool('wipe_database', 'destroys things', handler: fn() => 'gone'));

	$chat->sendMessage('Clean up', onStream: fn() => false);

	// the dangling call was answered with an error result, so the history stays valid
	$messages = $chat->getMessages();
	$last = end($messages);
	Assert::same(Role::Tool, $last->getRole());
	Assert::true($last->getParts()[0]->isError);

	// and the follow-up request carries a complete call/result pair
	$http->queue(fixture('claude/chat'));
	$chat->sendMessage('Never mind.');
	$sent = json_encode($http->lastPayload());
	Assert::contains('tool_result', $sent);
	Assert::contains('"tool_use_id":"t1"', $sent);
});


test('a cancel does not answer calls the caller runs by hand', function () {
	$sse = claudeEvent('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. claudeEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'wipe_database', 'input' => []]])
		. claudeEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"confirm":true}']])
		. claudeEvent('content_block_stop', ['index' => 0])
		. claudeEvent('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
		. claudeEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Wiping now.']]);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new Tool('wipe_database', 'destroys things')); // no handler

	$chat->sendMessage('Clean up', onStream: fn() => false);

	// the exchange belongs to the caller, so no error result appears behind their back
	$messages = $chat->getMessages();
	Assert::same(Role::Model, end($messages)->getRole());
});


test('claude text blocks stream apart the way getText() reads them', function () {
	$sse = claudeEvent('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. claudeEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
		. claudeEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'First block.']])
		. claudeEvent('content_block_stop', ['index' => 0])
		. claudeEvent('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
		. claudeEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Second block.']])
		. claudeEvent('content_block_stop', ['index' => 1])
		. claudeEvent('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]]);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');

	$pieces = [];
	$response = $chat->sendMessage('Two blocks', onStream: function (string $delta) use (&$pieces) {
		$pieces[] = $delta;
	});

	// the newline getText() puts between blocks must be streamed too, or the two disagree
	Assert::same("First block.\nSecond block.", $response->getText());
	Assert::same($response->getText(), implode('', $pieces));
});


test('an incomplete thinking block is dropped rather than replayed with a broken signature', function () {
	$sse = claudeEvent('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. claudeEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']])
		. claudeEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'half a thought']])
		. claudeEvent('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
		. claudeEvent('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]);
	// cancelled before content_block_stop delivered the signature

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');

	$response = $chat->sendMessage('x', onStream: fn(string $t) => $t === 'Hi' ? false : null);

	Assert::same(FinishReason::Cancelled, $response->getFinishReason());
	foreach ($response->getMessage()->getParts() as $part) {
		Assert::false($part instanceof AIAccess\Chat\ReasoningPart);
	}
});


test('an error event inside an HTTP 200 stream throws instead of truncating', function () {
	$sse = claudeEvent('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. claudeEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
		. claudeEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Half an ans']])
		. claudeEvent('error', ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');

	Assert::exception(
		fn() => $chat->sendMessage('x', onStream: fn() => null),
		AIAccess\ApiException::class,
		'Overloaded',
	);
});


test('openai stream that just goes quiet is a communication error, not a complete answer', function () {
	$sse = 'event: response.output_text.delta' . "\ndata: "
		. json_encode(['type' => 'response.output_text.delta', 'delta' => 'Half']) . "\n\n";

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('gpt-5.6-luna');

	Assert::exception(
		fn() => $chat->sendMessage('x', onStream: fn() => null),
		AIAccess\CommunicationException::class,
		'%a%without a terminal event%a%',
	);
});


test('openai error event carries the failure out of the stream', function () {
	$sse = 'event: error' . "\ndata: "
		. json_encode(['type' => 'error', 'code' => 'server_error', 'message' => 'The server had an error']) . "\n\n";

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('gpt-5.6-luna');

	Assert::exception(
		fn() => $chat->sendMessage('x', onStream: fn() => null),
		AIAccess\ApiException::class,
		'The server had an error',
	);
});


test('a cancelled openai stream keeps the text the caller already saw', function () {
	$event = fn(array $data) => 'event: ' . $data['type'] . "\ndata: " . json_encode($data) . "\n\n";
	$sse = $event(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => 'First piece'])
		. $event(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => ' and more']);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('gpt-5.6-luna');

	$response = $chat->sendMessage('x', onStream: fn() => false);

	Assert::same(FinishReason::Cancelled, $response->getFinishReason());
	Assert::same('First piece', $response->getText());
	$messages = $chat->getMessages();
	Assert::same('First piece', end($messages)->getText());
});


test('parallel tool calls are assembled from chat/completions deltas by index', function () {
	$chunk = fn(array $delta, ?string $finish = null) => 'data: ' . json_encode([
		'id' => 'c', 'object' => 'chat.completion.chunk', 'model' => 'm',
		'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
	]) . "\n\n";

	$sse = $chunk(['role' => 'assistant'])
		. $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'alpha', 'arguments' => '']]]])
		. $chunk(['tool_calls' => [['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'beta', 'arguments' => '']]]])
		. $chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"x":']]]])
		. $chunk(['tool_calls' => [['index' => 1, 'function' => ['arguments' => '{"y":"lo']]]])
		. $chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '1}']]]])
		. $chunk(['tool_calls' => [['index' => 1, 'function' => ['arguments' => 'ng"}']]]])
		. $chunk([], 'tool_calls')
		. "data: [DONE]\n\n";

	$http = (new FakeHttpClient)->queueStream(str_split($sse, 48));
	$chat = (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('deepseek-v4');

	$response = $chat->sendMessage('x', onStream: fn() => null);

	$calls = $response->getToolCalls();
	Assert::count(2, $calls);
	Assert::same(['call_a', 'call_b'], array_map(fn($c) => $c->callId, $calls));
	Assert::same(['alpha', 'beta'], array_map(fn($c) => $c->name, $calls));
	Assert::same([['x' => 1], ['y' => 'long']], array_map(fn($c) => $c->arguments, $calls));
	Assert::same(FinishReason::ToolCall, $response->getFinishReason());
});


test('a tool call cut off mid-arguments is dropped, not run with what survived', function () {
	// no content_block_stop: the arguments never finished arriving
	$sse = claudeEvent('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. claudeEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'wipe_database', 'input' => []]])
		. claudeEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"confi']]);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new Tool('wipe_database', 'destroys things', handler: fn() => 'wiped'));

	$response = $chat->sendMessage('Clean up', onStream: fn() => null);

	Assert::same([], $response->getToolCalls());
	Assert::same(AIAccess\Chat\FinishReason::Unknown, $response->getFinishReason());
});


test('the chat/completions dialect drops a half-written call the same way', function () {
	$chunk = fn(array $delta) => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta]]]) . "\n\n";
	$sse = $chunk(['role' => 'assistant'])
		. $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'wipe_database', 'arguments' => '']]]])
		. $chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"confi']]]]);

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$response = (new AIAccess\Provider\Grok\Client('key', $http))->createChat('m')
		->sendMessage('Clean up', onStream: fn() => null);

	Assert::same([], $response->getToolCalls());
	// a stream that stopped without a finish_reason did not finish
	Assert::same(AIAccess\Chat\FinishReason::Unknown, $response->getFinishReason());
});


test('once the stream did finish, malformed arguments stay the model to answer for', function () {
	$chunk = fn(array $delta, ?string $finish = null) => 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]]) . "\n\n";
	$sse = $chunk(['role' => 'assistant'])
		. $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => 'not json']]]])
		. $chunk([], 'tool_calls');

	$http = (new FakeHttpClient)->queueStream([$sse]);
	$response = (new AIAccess\Provider\Grok\Client('key', $http))->createChat('m')
		->sendMessage('Q', onStream: fn() => null);

	// kept, so the tool loop can hand the model its own mistake back
	Assert::count(1, $response->getToolCalls());
});
