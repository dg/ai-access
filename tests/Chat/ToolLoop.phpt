<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\Chat\Tool;
use AIAccess\Chat\ToolCallPart;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


/** A response with the tool call from the fixture, then a plain answer. */
function loopingChat(FakeHttpClient $http): AIAccess\Provider\Claude\Chat
{
	$http->queue(fixture('claude/tool-call'))->queue(fixture('claude/chat'));
	return (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
}


test('a tool with a handler is called and the loop finishes on its own', function () {
	$seen = null;
	$chat = loopingChat($http = new FakeHttpClient);
	$chat->addTool(new Tool(
		name: 'get_weather',
		description: 'Weather',
		parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
		handler: function (array $args) use (&$seen) {
			$seen = $args;
			return ['temperature' => 18];
		},
	));

	$response = $chat->sendMessage('Weather in Brno?');

	Assert::same(['city' => 'Brno'], $seen);
	Assert::contains('OK', $response->getText());
	Assert::same(2, $http->count());

	$roles = array_map(fn($m) => $m->getRole(), $chat->getMessages());
	Assert::same([Role::User, Role::Model, Role::Tool, Role::Model], $roles);
});


test('without a handler the loop does not start', function () {
	$chat = loopingChat($http = new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather'));

	$response = $chat->sendMessage('Weather in Brno?');

	Assert::same(AIAccess\Chat\FinishReason::ToolCall, $response->getFinishReason());
	Assert::count(1, $response->getToolCalls());
	Assert::same(1, $http->count());
});


test('usage adds up over the rounds', function () {
	$chat = loopingChat($http = new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 'sunny'));

	$response = $chat->sendMessage('Weather in Brno?');

	$last = $response->getUsage();
	$total = $chat->getTotalUsage();
	Assert::true($total->inputTokens > $last->inputTokens);
	Assert::same([], $total->raw);
});


test('an invented tool comes back to the model as an error, not an exception', function () {
	$raw = fixture('claude/tool-call');
	foreach ($raw['content'] as $i => $block) {
		if (($block['type'] ?? null) === 'tool_use') {
			$raw['content'][$i]['name'] = 'get_stock_price';
		}
	}

	$http = (new FakeHttpClient)->queue($raw)->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 'sunny'));

	$chat->sendMessage('Stock price?');

	$sent = json_encode($http->lastPayload());
	Assert::contains('Unknown tool', $sent);
	Assert::contains('is_error', $sent);
});


test('arguments that do not match the schema are reported to the model', function () {
	$raw = fixture('claude/tool-call');
	foreach ($raw['content'] as $i => $block) {
		if (($block['type'] ?? null) === 'tool_use') {
			$raw['content'][$i]['input'] = ['city' => 42];
		}
	}

	$called = false;
	$http = (new FakeHttpClient)->queue($raw)->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');
	$chat->addTool(new Tool(
		name: 'get_weather',
		description: 'Weather',
		parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
		handler: function () use (&$called) {
			$called = true;
			return 'sunny';
		},
	));

	$chat->sendMessage('Weather?');

	Assert::false($called);
	Assert::contains('must be of type string', json_encode($http->lastPayload()));
});


test('a throwing handler propagates unless catching is asked for', function () {
	$boom = fn() => throw new RuntimeException('database is down');

	$chat = loopingChat(new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather', handler: $boom));
	Assert::exception(fn() => $chat->sendMessage('Weather?'), RuntimeException::class, 'database is down');

	$chat = loopingChat($http = new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather', handler: $boom))->setToolLoop(catchErrors: true);
	$chat->sendMessage('Weather?');
	Assert::contains('database is down', json_encode($http->lastPayload()));
});


test('catching tool errors does not extend to bugs in the handler', function () {
	$chat = loopingChat(new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn(array $args) => strlen($args)))
		->setToolLoop(catchErrors: true);

	// a TypeError is the handler's own bug; hiding it in the conversation would be worse
	Assert::exception(fn() => $chat->sendMessage('Weather?'), TypeError::class);
});


test('usage keeps adding up across separate calls', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'))->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');

	$chat->sendMessage('first');
	$after = $chat->getTotalUsage()->getTotalTokens();
	$chat->sendMessage('second');

	Assert::same($after * 2, $chat->getTotalUsage()->getTotalTokens());

	// spent tokens are not undone by forgetting the conversation
	$chat->clearMessages();
	Assert::same($after * 2, $chat->getTotalUsage()->getTotalTokens());
});


test('tool rounds and validate share one budget', function () {
	$http = new FakeHttpClient;
	$http->queue(fixture('claude/tool-call'))->queue(fixture('claude/chat'))->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 'sunny'))->setToolLoop(maxRounds: 2);

	// round one is the tool call, round two its answer; validate would need a third
	$e = Assert::exception(
		fn() => $chat->sendMessage('Weather?', validate: fn() => 'Say more.'),
		AIAccess\TooManyRoundsException::class,
		'The conversation did not settle within 2 rounds.',
	);
	Assert::same(2, $http->count());

	// the caller can still see how far the loop got
	Assert::same('OK.', $e->lastResponse->getText());
});


test('a loop that will not settle is stopped', function () {
	$http = new FakeHttpClient;
	for ($i = 0; $i < 10; $i++) {
		$http->queue(fixture('claude/tool-call'));
	}
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 'sunny'))->setToolLoop(maxRounds: 3);

	Assert::exception(
		fn() => $chat->sendMessage('Weather?'),
		AIAccess\TooManyRoundsException::class,
		'The conversation did not settle within 3 rounds.',
	);
	Assert::same(3, $http->count());
});


test('validate sends the model its own homework back', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/chat'))->queue(fixture('claude/chat'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');

	$attempts = 0;
	$chat->sendMessage('Say something', validate: function ($response) use (&$attempts) {
		return ++$attempts === 1 ? 'Add the price, please.' : null;
	});

	Assert::same(2, $attempts);
	Assert::same(2, $http->count());
	Assert::contains('Add the price, please.', json_encode($http->lastPayload()));
});


test('a failure in a later round keeps what the earlier rounds produced', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/tool-call'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('m');
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 'sunny'));

	// the queue runs dry in the second round
	Assert::exception(fn() => $chat->sendMessage('Weather?'), Throwable::class);

	$roles = array_map(fn($m) => $m->getRole(), $chat->getMessages());
	Assert::same([Role::User, Role::Model, Role::Tool], $roles);
});


test('a failure in the first round leaves the history untouched', function () {
	$chat = (new AIAccess\Provider\Claude\Client('key', new FakeHttpClient))->createChat('m');

	Assert::exception(fn() => $chat->sendMessage('Weather?'), Throwable::class);
	Assert::same([], $chat->getMessages());
});


test('a handler may answer with a scalar', function () {
	$chat = loopingChat($http = new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 18.5));

	$chat->sendMessage('Weather?');

	Assert::contains('18.5', json_encode($http->lastPayload()));
});


test('the round limit has to make sense', function () {
	$chat = (new AIAccess\Provider\Claude\Client('key', new FakeHttpClient))->createChat('m');

	Assert::exception(
		fn() => $chat->setToolLoop(maxRounds: 0),
		AIAccess\LogicException::class,
		'At least one round is needed.',
	);
});


test('the handler receives the whole call, not just the arguments', function () {
	$chat = loopingChat(new FakeHttpClient);
	$seen = null;
	$chat->addTool(new Tool('get_weather', 'Weather', handler: function (array $args, ToolCallPart $call) use (&$seen) {
		$seen = $call;
		return 'sunny';
	}));

	$chat->sendMessage('Weather?');

	Assert::type(ToolCallPart::class, $seen);
	Assert::same('get_weather', $seen->name);
});


test('a forced tool is demanded once, not in every round', function () {
	$chat = loopingChat($http = new FakeHttpClient);
	$chat->addTool(new Tool('get_weather', 'Weather', handler: fn() => 'sunny'));
	$chat->setToolChoice('get_weather');

	$response = $chat->sendMessage('Weather?');

	// round one forces the tool, round two must be free to answer, or the loop never settles
	Assert::contains('OK', $response->getText());
	Assert::same(2, $http->count());
	Assert::same(['type' => 'tool', 'name' => 'get_weather'], $http->requests[0]['payload']['tool_choice']);
	Assert::false(isset($http->requests[1]['payload']['tool_choice']));

	// the setting itself survives for the next message
	$http->queue(fixture('claude/tool-call'))->queue(fixture('claude/chat'));
	$chat->sendMessage('And tomorrow?');
	Assert::same(['type' => 'tool', 'name' => 'get_weather'], $http->requests[2]['payload']['tool_choice']);
});


test('a throwing handler still answers every call of the round', function () {
	$http = new FakeHttpClient;
	$http->queue([
		'id' => 'msg', 'role' => 'assistant', 'model' => 'm',
		'content' => [
			['type' => 'tool_use', 'id' => 't1', 'name' => 'alpha', 'input' => ['x' => 1]],
			['type' => 'tool_use', 'id' => 't2', 'name' => 'beta', 'input' => ['x' => 2]],
		],
		'stop_reason' => 'tool_use',
		'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
	]);
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new Tool('alpha', 'A', handler: fn() => 'ok'));
	$chat->addTool(new Tool('beta', 'B', handler: fn() => throw new RuntimeException('boom')));

	Assert::exception(fn() => $chat->sendMessage('Go'), RuntimeException::class, 'boom');

	// the history holds a result for both calls, so the conversation can continue
	$messages = $chat->getMessages();
	$results = end($messages)->getParts();
	Assert::count(2, $results);
	Assert::same('ok', $results[0]->content);
	Assert::false($results[0]->isError);
	Assert::true($results[1]->isError);

	$http->queue(fixture('claude/chat'));
	$chat->sendMessage();
	$blocks = $http->lastPayload()['messages'][2]['content'];
	Assert::same(['t1', 't2'], array_column($blocks, 'tool_use_id'));
});


test('validate waits while tool calls are the caller\'s to answer', function () {
	$http = (new FakeHttpClient)->queue(fixture('claude/tool-call'));
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new Tool('get_weather', 'Weather')); // no handler, the caller runs it

	$validated = 0;
	$response = $chat->sendMessage('Weather?', validate: function () use (&$validated) {
		$validated++;
		return 'Say more.';
	});

	// feedback after an unanswered tool_use would make the next request invalid
	Assert::same(0, $validated);
	Assert::same(AIAccess\Chat\FinishReason::ToolCall, $response->getFinishReason());
	Assert::same(1, $http->count());
});
