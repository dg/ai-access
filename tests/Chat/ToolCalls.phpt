<?php declare(strict_types=1);

use AIAccess\Chat\Role;
use AIAccess\Chat\Tool;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


$weather = new Tool(
	name: 'get_weather',
	description: 'Returns the current weather for a city.',
	parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
);

$clients = [
	'claude' => fn($http) => new AIAccess\Provider\Claude\Client('key', $http),
	'openai' => fn($http) => new AIAccess\Provider\OpenAI\Client('key', $http),
	'gemini' => fn($http) => new AIAccess\Provider\Gemini\Client('key', $http),
	'deepseek' => fn($http) => new AIAccess\Provider\DeepSeek\Client('key', $http),
	'grok' => fn($http) => new AIAccess\Provider\Grok\Client('key', $http),
];


test('every provider reports the same tool call from its own wire format', function () use ($clients, $weather) {
	foreach ($clients as $name => $factory) {
		$http = (new FakeHttpClient)->queue(fixture($name . '/tool-call'));
		$chat = $factory($http)->createChat('model');
		$chat->addTool($weather);

		$response = $chat->sendMessage('Weather in Brno?');
		$calls = $response->getToolCalls();

		Assert::count(1, $calls, $name);
		Assert::same('get_weather', $calls[0]->name, $name);
		Assert::same(['city' => 'Brno'], $calls[0]->arguments, $name);
		Assert::notSame('', $calls[0]->callId, $name);
		Assert::null($calls[0]->argumentsError, $name);
		Assert::same(AIAccess\Chat\FinishReason::ToolCall, $response->getFinishReason(), $name);
	}
});


test('a tool call turn and its result survive into the next request', function () use ($clients, $weather) {
	foreach ($clients as $name => $factory) {
		$http = (new FakeHttpClient)
			->queue(fixture($name . '/tool-call'))
			->queue(fixture($name . '/tool-call'));
		$chat = $factory($http)->createChat('model');
		$chat->addTool($weather);

		$call = $chat->sendMessage('Weather in Brno?')->getToolCalls()[0];
		$chat->addToolResult($call, ['temperature' => 18]);
		$chat->sendMessage();

		$sent = json_encode($http->lastPayload());
		Assert::contains($call->callId, $sent, $name);
		Assert::contains('18', $sent, $name);
		Assert::contains('get_weather', $sent, $name);
	}
});


test('tool definitions reach the payload in each dialect', function () use ($clients, $weather) {
	$expected = [
		'claude' => ['tools', 0, 'input_schema'],
		'openai' => ['tools', 0, 'parameters'],
		'gemini' => ['tools', 0, 'functionDeclarations', 0, 'parametersJsonSchema'],
		'deepseek' => ['tools', 0, 'function', 'parameters'],
		'grok' => ['tools', 0, 'function', 'parameters'],
	];

	foreach ($clients as $name => $factory) {
		$http = (new FakeHttpClient)->queue(fixture($name . '/tool-call'));
		$chat = $factory($http)->createChat('model');
		$chat->addTool($weather);
		$chat->sendMessage('Weather in Brno?');

		$node = $http->lastPayload();
		foreach ($expected[$name] as $key) {
			Assert::hasKey($key, $node, $name);
			$node = $node[$key];
		}
		Assert::same(['city' => ['type' => 'string']], $node['properties'], $name);
	}
});


test('parallel results are gathered into a single turn', function () use ($clients) {
	$http = (new FakeHttpClient)->queue(fixture('claude/tool-call'));
	$chat = $clients['claude']($http)->createChat('model');

	$call = $chat->sendMessage('Weather in Brno?')->getToolCalls()[0];
	$chat->addToolResult($call, 'first');
	$chat->addToolResult($call->callId, 'second');

	$messages = $chat->getMessages();
	Assert::same(Role::Tool, end($messages)->getRole());
	Assert::count(2, end($messages)->getParts());
});


test('an id answers the newest call wearing it, not one settled rounds ago', function () use ($clients) {
	$http = (new FakeHttpClient)->queue(fixture('claude/tool-call'));
	$chat = $clients['claude']($http)->createChat('model');
	$call = $chat->sendMessage('Weather in Brno?')->getToolCalls()[0];
	$chat->addToolResult($call, 'first round');

	// Gemini usually sends no id at all, so the same one comes round again
	$chat->addMessage(new AIAccess\Chat\ToolCallPart($call->callId, 'get_time', []), Role::Model);
	$chat->addToolResult($call->callId, 'second round');

	$messages = $chat->getMessages();
	Assert::same('get_time', end($messages)->getParts()[0]->name);
});


test('an error result is marked as one, whatever the dialect offers', function () use ($clients, $weather) {
	foreach ($clients as $name => $factory) {
		$http = (new FakeHttpClient)
			->queue(fixture($name . '/tool-call'))
			->queue(fixture($name . '/tool-call'));
		$chat = $factory($http)->createChat('model');
		$chat->addTool($weather);

		$call = $chat->sendMessage('Weather in Brno?')->getToolCalls()[0];
		$chat->addToolResult($call, 'City not found', isError: true);
		$chat->sendMessage();

		$sent = json_encode($http->lastPayload());
		// Claude has is_error, Gemini nests it under "error", the rest only have the text
		Assert::true(
			str_contains($sent, 'is_error') || str_contains($sent, '"error"') || str_contains($sent, 'ERROR: '),
			$name,
		);
	}
});


test('gemini keeps the call id so parallel calls stay apart', function () use ($clients, $weather) {
	$raw = fixture('gemini/tool-call');
	$raw['candidates'][0]['content']['parts'][] = [
		'functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Praha'], 'id' => 'SECOND'],
	];

	$http = (new FakeHttpClient)->queue($raw)->queue(fixture('gemini/tool-call'));
	$chat = $clients['gemini']($http)->createChat('model');
	$chat->addTool($weather);

	$calls = $chat->sendMessage('Weather in Brno and Praha?')->getToolCalls();
	Assert::count(2, $calls);
	foreach ($calls as $i => $call) {
		$chat->addToolResult($call, ['index' => $i]);
	}
	$chat->sendMessage();

	$responses = array_column($http->lastPayload()['contents'][2]['parts'], 'functionResponse');
	Assert::same([$calls[0]->callId, 'SECOND'], array_column($responses, 'id'));
});


test('reasoning goes back only alongside a tool call', function () use ($clients, $weather) {
	foreach (['deepseek', 'grok'] as $name) {
		$http = (new FakeHttpClient)
			->queue(fixture($name . '/tool-call'))
			->queue(fixture($name . '/tool-call'));
		$chat = $clients[$name]($http)->createChat('model');
		$chat->addTool($weather);

		$call = $chat->sendMessage('Weather in Brno?')->getToolCalls()[0];
		$chat->addToolResult($call, 'sunny');
		$chat->sendMessage();

		$assistant = null;
		foreach ($http->lastPayload()['messages'] as $message) {
			if (isset($message['tool_calls'])) {
				$assistant = $message;
			}
		}
		Assert::notNull($assistant, $name);
		Assert::type('string', $assistant['reasoning_content'], $name);
	}
});


test('an unknown call id is refused', function () use ($clients) {
	$chat = $clients['claude'](new FakeHttpClient)->createChat('model');

	Assert::exception(
		fn() => $chat->addToolResult('call_nonexistent', 'result'),
		AIAccess\LogicException::class,
		"No tool call 'call_nonexistent' in the history.",
	);
});


test('forcing a tool requires it to be registered', function () use ($clients, $weather) {
	$chat = $clients['claude'](new FakeHttpClient)->createChat('model');

	Assert::exception(
		fn() => $chat->setToolChoice('nope'),
		AIAccess\LogicException::class,
		"Unknown tool 'nope'.",
	);

	$chat->addTool($weather)->setToolChoice('get_weather');
	$chat->addMessage('Weather in Brno?', Role::User);
	Assert::same('get_weather', $chat->buildPayload()['tool_choice']['name']);
});


test('arguments that are not valid JSON are reported, not thrown', function () {
	$raw = fixture('openai/tool-call');
	foreach ($raw['output'] as $i => $item) {
		if (($item['type'] ?? null) === 'function_call') {
			$raw['output'][$i]['arguments'] = '{"city": ';
		}
	}

	$response = new AIAccess\Provider\OpenAI\ChatResponse($raw);
	$call = $response->getToolCalls()[0];

	Assert::same([], $call->arguments);
	Assert::contains('not valid JSON', (string) $call->argumentsError);
});
