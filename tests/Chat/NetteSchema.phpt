<?php declare(strict_types=1);

use AIAccess\Chat\Tool;
use AIAccess\LogicException;
use AIAccess\Provider;
use AIAccess\UnexpectedResponseException;
use Nette\Schema\Expect;
use Tester\Assert;
use Tests\Support\FakeHttpClient;

require __DIR__ . '/../bootstrap.php';


final class Order
{
	public function __construct(
		public string $name,
		public float $amount,
		public ?string $note = null,
	) {
	}
}


test('a Nette schema goes out as JSON Schema', function () {
	$http = (new FakeHttpClient)->queue(['content' => [['type' => 'text', 'text' => '{"name":"Jan","amount":1.5}']]]);
	$schema = Expect::structure([
		'name' => Expect::string()->required()->description('Full name'),
		'amount' => Expect::float()->min(0)->required(),
		'note' => Expect::string()->nullable(),
	]);

	(new Provider\Claude\Client('k', $http))->createChat('m')->setResponseSchema($schema)->sendMessage('x');

	Assert::same(
		[
			'type' => 'object',
			'properties' => [
				'name' => ['type' => 'string', 'description' => 'Full name'],
				'amount' => ['type' => 'number', 'minimum' => 0],
				'note' => ['type' => ['string', 'null']],
			],
			'required' => ['name', 'amount'],
			'additionalProperties' => false,
		],
		$http->lastPayload()['output_config']['format']['schema'],
	);
});


test('getJson() returns what the schema yields', function () {
	$http = (new FakeHttpClient)
		->queue(['content' => [['type' => 'text', 'text' => '{"name":"Jan","amount":1.5}']]])
		->queue(['content' => [['type' => 'text', 'text' => '{"name":"Jan","amount":1.5}']]])
		->queue(['content' => [['type' => 'text', 'text' => '{"name":"Jan","amount":1.5}']]]);
	$client = new Provider\Claude\Client('k', $http);

	$response = $client->createChat('m')
		->setResponseSchema(Expect::structure(['name' => Expect::string(), 'amount' => Expect::float()]))
		->sendMessage('x');
	Assert::equal((object) ['name' => 'Jan', 'amount' => 1.5], $response->getJson());

	$response = $client->createChat('m')
		->setResponseSchema(Expect::structure(['name' => Expect::string(), 'amount' => Expect::float()])->castTo('array'))
		->sendMessage('x');
	Assert::same(['name' => 'Jan', 'amount' => 1.5], $response->getJson());

	$response = $client->createChat('m')
		->setResponseSchema(Expect::from(new Order('', 0.0)))
		->sendMessage('x');
	Assert::equal(new Order('Jan', 1.5), $response->getJson());
});


test('an answer that does not fit the schema is an unexpected response', function () {
	$http = (new FakeHttpClient)->queue(['content' => [['type' => 'text', 'text' => '{"name":"Jan","amount":"a lot"}']]]);
	$response = (new Provider\Claude\Client('k', $http))->createChat('m')
		->setResponseSchema(Expect::structure(['name' => Expect::string(), 'amount' => Expect::float()]))
		->sendMessage('x');

	$e = Assert::exception(
		fn() => $response->getJson(),
		UnexpectedResponseException::class,
		"Response does not match the schema: The item 'amount' expects to be float, 'a lot' given.",
	);
	Assert::type(Nette\Schema\ValidationException::class, $e->getPrevious());
});


test('a strict provider refuses an optional key up front, a lenient one does not', function () {
	$schema = Expect::structure(['name' => Expect::string()->required(), 'note' => Expect::string()]);
	$http = new FakeHttpClient;

	Assert::exception(
		fn() => (new Provider\OpenAI\Client('k', $http))->createChat('m')->setResponseSchema($schema),
		LogicException::class,
		"Key 'note' is optional, but the provider's strict mode needs every key required; make it required() or nullable(), with Expect::from() through its second argument.",
	);
	Assert::exception(
		fn() => (new Provider\Grok\Client('k', $http))->createChat('m')->setResponseSchema($schema),
		LogicException::class,
		"Key 'note' is optional, but the provider's strict mode needs every key required; make it required() or nullable(), with Expect::from() through its second argument.",
	);
	Assert::exception(
		fn() => (new Provider\OpenAI\Client('k', $http))->createChat('m')->setResponseSchema(Expect::structure([
			'items' => Expect::listOf(Expect::structure(['id' => Expect::int()]))->required(),
		])),
		LogicException::class,
		"Key 'items.items.id' is optional, but the provider's strict mode needs every key required; make it required() or nullable(), with Expect::from() through its second argument.",
	);

	Assert::exception(
		fn() => (new Provider\OpenAI\Client('k', $http))->createChat('m')->setResponseSchema(Expect::structure([
			'tags' => Expect::arrayOf('string')->required(),
		])),
		LogicException::class,
		"Object 'tags.' allows additional properties (arrayOf(), otherItems()), which the provider's strict mode does not; use structure() or listOf().",
	);

	(new Provider\Claude\Client('k', $http))->createChat('m')->setResponseSchema($schema);
	(new Provider\OpenAI\Client('k', $http))->createChat('m')->setResponseSchema(
		Expect::structure(['name' => Expect::string()->required(), 'note' => Expect::string()->nullable()->required()]),
	);
	Assert::same(0, $http->count());
});


test('a raw response format set later replaces the schema, on the wire and for the answer', function () {
	$http = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => '{"a":"not an int"}']]]]);
	$response = (new Provider\Grok\Client('k', $http))->createChat('m')
		->setResponseSchema(Expect::structure(['a' => Expect::int()->required()]))
		->setOptions(responseFormat: ['type' => 'json_object'])
		->sendMessage('x');

	Assert::same(['type' => 'json_object'], $http->lastPayload()['response_format']);
	Assert::same(['a' => 'not an int'], $response->getJson());
});


test('a streamed answer is validated the same way', function () {
	$sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => '{"a":'], 'finish_reason' => null]]]) . '

'
		. 'data: ' . json_encode(['choices' => [['delta' => ['content' => '1}'], 'finish_reason' => 'stop']]]) . '

'
		. 'data: [DONE]

';
	$http = (new FakeHttpClient)->queueStream([$sse]);
	$stream = (new Provider\Grok\Client('k', $http))->createChat('m')
		->setResponseSchema(Expect::structure(['a' => Expect::int()->required()]))
		->sendMessageStream('x');
	foreach ($stream as $chunk) {
	}

	Assert::equal((object) ['a' => 1], $stream->getResponse()->getJson());
});


test('a JSON Schema array is left alone', function () {
	$http = (new FakeHttpClient)->queue(['choices' => [['message' => ['content' => '{"a":1}']]]]);
	$schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]];
	$response = (new Provider\Grok\Client('k', $http))->createChat('m')->setResponseSchema($schema)->sendMessage('x');

	Assert::same($schema, $http->lastPayload()['response_format']['json_schema']['schema']);
	Assert::same(['a' => 1], $response->getJson());
});


test('a tool with a Nette schema hands the handler what the schema yields', function () {
	$seen = null;
	$http = (new FakeHttpClient)->queue(fixture('claude/tool-call'))->queue(fixture('claude/chat'));
	$chat = (new Provider\Claude\Client('key', $http))->createChat('m');
	$chat->addTool(new Tool(
		name: 'get_weather',
		description: 'Weather',
		parameters: Expect::structure([
			'city' => Expect::string()->required(),
			'units' => Expect::anyOf('celsius', 'fahrenheit')->default('celsius'),
		]),
		handler: function (stdClass $args) use (&$seen) {
			$seen = $args;
			return 'sunny';
		},
	));

	$chat->sendMessage('Weather in Brno?');

	Assert::equal((object) ['city' => 'Brno', 'units' => 'celsius'], $seen);
	Assert::same(
		[
			'type' => 'object',
			'properties' => ['city' => ['type' => 'string'], 'units' => ['type' => 'string', 'enum' => ['celsius', 'fahrenheit']]],
			'required' => ['city'],
			'additionalProperties' => false,
		],
		$http->lastPayload()['tools'][0]['input_schema'],
	);
});


test('arguments the schema refuses go back to the model with its message', function () {
	$raw = fixture('claude/tool-call');
	foreach ($raw['content'] as $i => $block) {
		if (($block['type'] ?? null) === 'tool_use') {
			$raw['content'][$i]['input'] = ['city' => 42, 'extra' => true];
		}
	}
	$http = (new FakeHttpClient)->queue($raw)->queue(fixture('claude/chat'));
	$chat = (new Provider\Claude\Client('key', $http))->createChat('m');
	$called = false;
	$chat->addTool(new Tool(
		'get_weather',
		'Weather',
		Expect::structure(['city' => Expect::string()->required()]),
		function () use (&$called) {
			$called = true;
		},
	));

	$chat->sendMessage('Weather?');

	Assert::false($called);
	$sent = json_encode($http->lastPayload());
	Assert::contains("Unexpected item 'extra'.", $sent);
	Assert::contains("The item 'city' expects to be string, 42 given.", $sent);
	Assert::contains('is_error', $sent);
});


test('a strict tool is checked against the strict rules when it is created', function () {
	Assert::exception(
		fn() => new Tool('t', 'x', Expect::structure(['a' => Expect::int()]), strict: true),
		LogicException::class,
		"Key 'a' is optional, but the provider's strict mode needs every key required; make it required() or nullable(), with Expect::from() through its second argument.",
	);
	$tool = new Tool('t', 'x', Expect::structure(['a' => Expect::int()]));
	Assert::same(['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'required' => [], 'additionalProperties' => false], $tool->parameters);
	Assert::type(Nette\Schema\Elements\Structure::class, $tool->schema);
	Assert::null((new Tool('t'))->schema);
});
