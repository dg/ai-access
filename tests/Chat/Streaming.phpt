<?php declare(strict_types=1);

use AIAccess\Chat\FinishReason;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


/** Replays a captured transcript in pieces that do not respect event boundaries. */
function streamingChat(string $provider, FakeHttpClient $http, int $pieceSize = 57): AIAccess\Chat\Chat
{
	$raw = file_get_contents(__DIR__ . "/../fixtures/$provider/stream.sse.txt");
	$http->queueStream(str_split($raw, $pieceSize));

	return match ($provider) {
		'claude' => (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5'),
		'openai' => (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('gpt-5.6-luna'),
		'gemini' => (new AIAccess\Provider\Gemini\Client('key', $http))->createChat('gemini-3.5-flash-lite'),
		'deepseek' => (new AIAccess\Provider\DeepSeek\Client('key', $http))->createChat('deepseek-v4-flash'),
		'grok' => (new AIAccess\Provider\Grok\Client('key', $http))->createChat('grok-4.3'),
	};
}


$providers = ['claude', 'openai', 'gemini', 'deepseek', 'grok'];


test('every provider streams the answer, whole and in order', function () use ($providers) {
	foreach ($providers as $provider) {
		$chat = streamingChat($provider, new FakeHttpClient);

		$pieces = [];
		foreach ($chat->sendMessageStream('Say: one two three') as $delta) {
			$pieces[] = $delta;
		}

		Assert::notSame([], $pieces, $provider);
		Assert::contains('one two three', strtolower(implode('', $pieces)), $provider);
	}
});


test('an answer long enough arrives in several pieces', function () {
	// claude answered this short prompt in a single delta, so it proves nothing here
	foreach (['openai', 'deepseek', 'grok'] as $provider) {
		$chat = streamingChat($provider, new FakeHttpClient);
		$pieces = iterator_to_array($chat->sendMessageStream('Say: one two three'));

		Assert::true(count($pieces) > 1, "$provider streams more than one piece");
	}
});


test('the finished stream is an ordinary response', function () use ($providers) {
	foreach ($providers as $provider) {
		$chat = streamingChat($provider, new FakeHttpClient);
		$stream = $chat->sendMessageStream('Say: one two three');

		$text = $stream->getText();
		$response = $stream->getResponse();

		Assert::same($text, $response->getText(), $provider);
		Assert::same(FinishReason::Complete, $response->getFinishReason(), $provider);
		Assert::true($response->getUsage()->outputTokens > 0, "$provider reports usage");
	}
});


test('a streamed turn lands in the history like any other', function () use ($providers) {
	foreach ($providers as $provider) {
		$chat = streamingChat($provider, new FakeHttpClient);
		$chat->sendMessageStream('Say: one two three')->getText();

		$messages = $chat->getMessages();
		Assert::count(2, $messages, $provider);
		Assert::same(AIAccess\Chat\Role::Model, $messages[1]->getRole(), $provider);
		Assert::notSame('', $messages[1]->getText(), $provider);
		Assert::true($chat->getTotalUsage()->outputTokens > 0, $provider);
	}
});


test('nothing is sent until the stream is read', function () {
	$http = new FakeHttpClient;
	$chat = streamingChat('claude', $http);

	$stream = $chat->sendMessageStream('Say: one two three');
	Assert::same(0, $http->count());

	$stream->getText();
	Assert::same(1, $http->count());
});


test('stopping early is reported as cancelled', function () {
	$http = new FakeHttpClient;
	$chat = streamingChat('claude', $http);

	$response = $chat->sendMessage('Say: one two three', onStream: fn() => false);

	Assert::same(FinishReason::Cancelled, $response->getFinishReason());
});


test('stopping half way and then asking for the response sends no second request', function () {
	$http = new FakeHttpClient;
	$chat = streamingChat('openai', $http);

	$stream = $chat->sendMessageStream('Say: one two three');
	$read = 0;
	foreach ($stream as $delta) {
		if (++$read >= 2) {
			break;
		}
	}

	// the rest of the very same stream is read, rather than the model being asked again
	$response = $stream->getResponse();

	Assert::same(1, $http->count());
	Assert::same('one two three', $response->getText());
});


test('a cancelled stream does not run tools or ask again', function () {
	$event = fn(string $name, array $data) => "event: $name\ndata: " . json_encode($data) . "\n\n";

	// the tool call arrives complete, and the caller stops on the text that follows it
	$sse = $event('message_start', ['type' => 'message_start', 'message' => ['id' => 'm', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 5]]])
		. $event('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'wipe_database', 'input' => []]])
		. $event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"confirm":true}']])
		. $event('content_block_stop', ['index' => 0])
		. $event('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
		. $event('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Wiping now.']])
		. $event('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]]);

	$http = new FakeHttpClient;
	foreach (range(1, 5) as $ignored) {
		$http->queueStream([$sse]);
	}

	$ran = 0;
	$chat = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5');
	$chat->addTool(new AIAccess\Chat\Tool('wipe_database', 'destroys things', handler: function () use (&$ran) {
		return ++$ran;
	}));

	$response = $chat->sendMessage('Clean up', onStream: fn() => false);

	Assert::same(0, $ran);
	Assert::same(1, $http->count());
	Assert::same(FinishReason::Cancelled, $response->getFinishReason());
});


test('the callback form gets the same pieces', function () {
	$pieces = [];
	$chat = streamingChat('openai', new FakeHttpClient);

	$response = $chat->sendMessage('Say: one two three', onStream: function (string $delta) use (&$pieces) {
		$pieces[] = $delta;
	});

	Assert::true(count($pieces) > 1);
	Assert::same(implode('', $pieces), $response->getText());
});


test('a failed stream keeps reporting what went wrong', function () {
	$http = (new FakeHttpClient)->queueStreamError(['error' => ['message' => 'rate limit exceeded']], 429);
	$stream = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5')
		->sendMessageStream('Say something');

	Assert::exception(fn() => iterator_to_array($stream), AIAccess\ApiException::class, 'rate limit exceeded');

	// and again, rather than leaking a FiberError that explains nothing
	Assert::exception(fn() => $stream->getResponse(), AIAccess\ApiException::class, 'rate limit exceeded');
	Assert::exception(fn() => $stream->getText(), AIAccess\ApiException::class, 'rate limit exceeded');
});


test('a connection lost mid-stream is reported as such, twice over', function () {
	$http = new class extends FakeHttpClient {
		public function fetch(
			string $url,
			string|array|AIAccess\Http\FormData|null $payload = null,
			array $headers = [],
			?string $method = null,
			?Closure $onChunk = null,
		): AIAccess\Http\Response
		{
			$onChunk("event: content_block_delta\ndata: " . json_encode([
				'index' => 0,
				'delta' => ['type' => 'text_delta', 'text' => 'half an answer'],
			]) . "\n\n");
			throw new AIAccess\CommunicationException('connection reset');
		}
	};

	$stream = (new AIAccess\Provider\Claude\Client('key', $http))->createChat('claude-sonnet-5')
		->sendMessageStream('Say something');

	$delivered = [];
	Assert::exception(
		function () use ($stream, &$delivered) {
			foreach ($stream as $delta) {
				$delivered[] = $delta;
			}
		},
		AIAccess\CommunicationException::class,
		'connection reset',
	);

	Assert::same(['half an answer'], $delivered);
	Assert::exception(fn() => $stream->getResponse(), AIAccess\CommunicationException::class, 'connection reset');
});


test('the generic client streams too, without inventing options for the endpoint', function () {
	// its accumulator is its own copy, so the DeepSeek tests say nothing about it
	$raw = file_get_contents(__DIR__ . '/../fixtures/deepseek/stream.sse.txt');
	$http = (new FakeHttpClient)->queueStream(str_split($raw, 57));
	$chat = (new AIAccess\Provider\OpenAICompatible\Client('key', 'https://host/v1/', $http))->createChat('llama');

	$stream = $chat->sendMessageStream('Say: one two three');
	$text = $stream->getText();

	Assert::contains('one two three', strtolower($text));
	Assert::same($text, $stream->getResponse()->getText());

	// stream_options is an OpenAI invention; an unknown dialect must not meet it uninvited
	$payload = $http->lastPayload();
	Assert::true($payload['stream']);
	Assert::false(isset($payload['stream_options']));
});


test('the generic client asks for usage when told to', function () {
	$raw = file_get_contents(__DIR__ . '/../fixtures/deepseek/stream.sse.txt');
	$http = (new FakeHttpClient)->queueStream(str_split($raw, 57));
	$chat = (new AIAccess\Provider\OpenAICompatible\Client('key', 'https://host/v1/', $http))->createChat('llama');
	$chat->setOptions(custom: ['stream_options' => ['include_usage' => true]]);

	$chat->sendMessageStream('Say: one two three')->getText();

	Assert::same(['include_usage' => true], $http->lastPayload()['stream_options']);
});


test('a stream that fails inside HTTP 200 throws the same way a plain call does', function () {
	$failure = ['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'model overloaded'], 'output' => []];

	$sse = 'event: response.output_text.delta' . "\ndata: " . json_encode(['type' => 'response.output_text.delta', 'delta' => 'Thin']) . "\n\n"
		. 'event: response.failed' . "\ndata: " . json_encode(['type' => 'response.failed', 'response' => $failure]) . "\n\n";

	$streamed = (new FakeHttpClient)->queueStream([$sse]);
	$plain = (new FakeHttpClient)->queue($failure);

	// the streamed terminal event carries the same failure, so it cannot end up as a silent
	// empty answer just because it arrived in pieces
	foreach (['streamed' => $streamed, 'plain' => $plain] as $label => $http) {
		$chat = (new AIAccess\Provider\OpenAI\Client('key', $http))->createChat('gpt-5.6-luna');
		Assert::exception(
			fn() => $label === 'streamed'
				? $chat->sendMessage('x', onStream: fn() => null)
				: $chat->sendMessage('x'),
			AIAccess\ApiException::class,
			'model overloaded (server_error)',
			null,
			$label,
		);
	}
});


test('a stream can only be read once', function () {
	$chat = streamingChat('gemini', new FakeHttpClient);
	$stream = $chat->sendMessageStream('Say: one two three');
	$stream->getText();

	Assert::exception(
		fn() => iterator_to_array($stream),
		AIAccess\LogicException::class,
		'The stream has already been read.',
	);
});


test('reasoning is streamed apart from the answer', function () {
	// grok reasons before answering, and the reasoning must not reach the text
	$chat = streamingChat('grok', new FakeHttpClient);
	$stream = $chat->sendMessageStream('Say: one two three');
	$text = $stream->getText();

	$reasoning = $stream->getResponse()->getReasoning();
	Assert::type('string', $reasoning);
	Assert::notContains($reasoning, $text);
});
