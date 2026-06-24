![AI Access for PHP](https://github.com/user-attachments/assets/f9b6702d-6d6b-49fd-96ff-a33c53e26c68)

[![Downloads this Month](https://img.shields.io/packagist/dm/ai-access/ai-access.svg)](https://packagist.org/packages/ai-access/ai-access)
[![Tests](https://github.com/dg/ai-access/workflows/Tests/badge.svg?branch=master)](https://github.com/dg/ai-access/actions)
[![Coverage Status](https://coveralls.io/repos/github/dg/ai-access/badge.svg?branch=master)](https://coveralls.io/github/dg/ai-access?branch=master)
[![Latest Stable Version](https://poser.pugx.org/ai-access/ai-access/v/stable)](https://github.com/dg/ai-access/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/dg/ai-access/blob/master/license.md)

 <!---->

**One clean PHP interface for OpenAI, Claude, Gemini, DeepSeek and Grok.** Write your AI integration once, switch providers by changing a single line.

AI Access comes from David Grudl, the author of [Nette](https://nette.org), [Latte](https://latte.nette.org) and [Tracy](https://tracy.nette.org), libraries that have powered tens of thousands of PHP applications for two decades. It is built with the same discipline that made those libraries popular: an API you can learn in five minutes and trust for years.

The ambition is simple to state and hard to deliver: **to be the best-designed AI library in the PHP ecosystem.** Judge for yourself:

```php
$client = new AIAccess\Provider\OpenAI\Client($apiKey);

$response = $client->createChat('gpt-5.6-luna')
	->sendMessage('Write a haiku about PHP.');

echo $response->getText();
```

Switching to Claude, Gemini, DeepSeek or Grok? Change the first line. Everything else stays.

 <!---->

Why AI Access
=============

**Zero dependencies.** Pure PHP and curl. No vendor SDKs, no HTTP framework, no transitive dependency conflicts with the rest of your project. `composer why` will thank you.

**Designed, not accreted.** Strict types everywhere, readonly value objects, named arguments instead of option arrays, and an exception hierarchy organized around the only question that matters in production: *should I retry?* Every design decision follows the philosophy proven in Nette: the library should be so intuitive that you rarely need this documentation.

**Honest abstraction.** The unified interface covers what providers genuinely share. Where they differ, AI Access does not pretend: provider-specific options are explicit, typed, named parameters on the provider's own class, so your IDE tells you exactly what each model supports instead of letting a silently ignored array key bite you in production.

**The whole workflow, not just chat.** Multi-turn conversations, system instructions, token usage tracking, batch processing at 50% cost, and embeddings with compact binary serialization built in.

| Capability | OpenAI | Claude | Gemini | DeepSeek | Grok |
|-------------------|:------:|:------:|:------:|:--------:|:----:|
| Chat              | ✓      | ✓      | ✓      | ✓        | ✓    |
| Reasoning effort  | ✓      | ✓      | ✓      | ✓        | ✓    |
| Tool calling      | ✓      | ✓      | ✓      | ✓        | ✓    |
| Image input       | ✓      | ✓      | ✓      | –        | ✓    |
| Document input    | ✓      | ✓      | ✓      | –        | –    |
| Structured output | ✓      | ✓      | ✓      | –        | ✓    |
| Image generation  | ✓      | –      | ✓      | –        | ✓    |
| Batch (50% off)   | ✓      | ✓      | ✓      | –        | –    |
| Embeddings        | ✓      | –      | ✓      | –        | –    |
| List models       | ✓      | ✓      | ✓      | ✓        | ✓    |

Plus a generic client for anything speaking the OpenAI dialect: Ollama,
Mistral, OpenRouter, Together, vLLM, Azure.

Where a dash appears, either the provider has no such API or AI Access does not
wrap it yet; xAI has batch and file endpoints that are not wrapped at all.
Gemini counts batches among its paid features, so they need a billing-enabled
Google project rather than a free-tier key.

 <!---->

Installation
============

```shell
composer require ai-access/ai-access
```

Requires PHP 8.3 or later.

 <!---->

Getting Started
===============

Create a client for your chosen provider. This is the only provider-specific line in your application:

```php
// pick one:
$client = new AIAccess\Provider\OpenAI\Client($apiKey);
$client = new AIAccess\Provider\Claude\Client($apiKey);
$client = new AIAccess\Provider\Gemini\Client($apiKey);
$client = new AIAccess\Provider\DeepSeek\Client($apiKey);
$client = new AIAccess\Provider\Grok\Client($apiKey);
```

Get your API keys here: [OpenAI](https://platform.openai.com/api-keys) · [Anthropic](https://console.anthropic.com/settings/keys) · [Google](https://aistudio.google.com/app/apikey) · [DeepSeek](https://platform.deepseek.com/api_keys) · [xAI](https://console.x.ai/team/default/api-keys)

In a real application you would register the client in a [DI container](https://doc.nette.org/en/dependency-injection); the examples below assume `$client` exists.

Pick a model. Model names are ordinary strings, so new models work the day the provider releases them, with no library update needed. A few current, cost-effective choices (August 2026):

| Provider | Chat model | Embedding model |
|----------|--------------------|-----------------|
| OpenAI   | `gpt-5.6-luna`     | `text-embedding-3-small` |
| Claude   | `claude-sonnet-5`  | – |
| Gemini   | `gemini-3.5-flash-lite` | `gemini-embedding-2` |
| DeepSeek | `deepseek-v4-flash` | – |
| Grok     | `grok-4.3`         | – |

 <!---->

Chat
====
▶ Full runnable examples: [examples/chat/](examples/chat)


```php
$chat = $client->createChat('gpt-5.6-luna');
$response = $chat->sendMessage('Write a short haiku about PHP.');

echo $response->getText() ?? 'No content generated';
```

`sendMessage()` sends the message, appends both your message and the model's reply to the conversation history, and returns a response object. Which means multi-turn conversation is nothing special, you just keep talking:

```php
$chat->sendMessage('What is the capital of France?');
$response = $chat->sendMessage('And what is a famous landmark there?');
```

You can also build history by hand, for example to restore a conversation or to provide few-shot examples, and then let the model continue:

```php
use AIAccess\Chat\Role;

$chat = $client->createChat($model);
$chat->addMessage('What is the capital of France?', Role::User);
$chat->addMessage('The capital of France is Paris.', Role::Model);
$chat->addMessage('What is a famous landmark there?', Role::User);

$response = $chat->sendMessage(); // no argument: continue from history
```

`$chat->getMessages()` returns the full history at any point.


System Instructions
-------------------

Set the model's persona or ground rules once; they apply for the whole conversation:

```php
$chat->setSystemInstruction('You are a helpful assistant that speaks like a pirate.');
```


Inspecting the Response
-----------------------

Besides the text, the response tells you *why* generation stopped and *what it cost*:

```php
use AIAccess\Chat\FinishReason;

if ($response->getFinishReason() !== FinishReason::Complete) {
	// TokenLimit, ContentFiltered, ToolCall...
	echo 'Stopped early: ', $response->getRawFinishReason();
}

$usage = $response->getUsage();
echo "Tokens: {$usage->inputTokens} in / {$usage->outputTokens} out";
echo "Reasoning: {$usage->reasoningTokens}, served from cache: {$usage->cacheReadTokens}";
```

Cache hit rates are the main cost lever with today's models, so `Usage` reports
them for every provider that exposes them.

And when you need something the abstraction does not cover, `$response->getRawResponse()` hands you the provider's complete decoded payload. The unified interface is a convenience, never a cage.


Model Options
-------------

How hard should the model think before answering? That is the one knob every
provider now has, and AI Access unifies it:

```php
use AIAccess\Chat\Effort;

$chat->setEffort(Effort::Low);   // fast and cheap
$chat->setEffort(Effort::High);  // slow and careful
```

This matters more than it looks. Providers are retiring `temperature` on their
newest reasoning models: Claude answers 400 on Opus 4.7 and on the whole Claude 5
line, GPT-5.1+ answers 400 unless reasoning effort is none, Gemini ignores it
silently, DeepSeek ignores it whenever thinking is on, which is by default.
Effort is what replaced it.

Older models may not have the dial and answer with an `ApiException`. That is
deliberate: the library sends what you asked for instead of maintaining a table
of model capabilities that would be stale within weeks.

Everything else is genuinely provider-specific, so AI Access exposes it as typed
named arguments on each provider's `Chat` class, with IDE autocompletion instead
of guesswork:

```php
$chat->setOptions(maxOutputTokens: 500, stopSequences: ['END']);  // Claude
$chat->setOptions(maxOutputTokens: 500, store: false);            // OpenAI
```

See the `setOptions()` signature in `src/Provider/*/Chat.php` for the full,
documented list. There is no shared option array on purpose: it would silently
swallow the names that do not apply to the provider you happen to be using.

▶ Full runnable example of the effort dial: [examples/chat/options.php](examples/chat/options.php)

 <!---->

Tool Calling
============

▶ Full runnable examples: [examples/tools/](examples/tools)

Describe a function, give it a handler, and the model can call your code:

```php
use AIAccess\Chat\Tool;

$chat->addTool(new Tool(
	name: 'get_weather',
	description: 'Returns the current weather for a city.',
	parameters: [
		'type' => 'object',
		'properties' => ['city' => ['type' => 'string']],
		'required' => ['city'],
	],
	handler: fn(array $args) => $weatherService->for($args['city']),
));

echo $chat->sendMessage('What should I wear in Brno today?')->getText();
```

That single call covers the whole exchange: the model asks for the tool, AI
Access runs your handler, sends the result back, and returns when the model is
done. Parallel calls, several rounds, whatever it takes.

The five providers disagree on every detail underneath. Tool definitions are
flat for one and nested for another; the key pairing a result with its call is
named differently everywhere, and Gemini matches by function name instead;
results travel as a leading content block, a flat item, a user turn, or one
message per result. None of that reaches your code.

**Mistakes the model makes are handed back to it**, not thrown at you: an
invented tool name, arguments that will not decode, arguments that do not match
your schema. It corrects itself on the next round. Your handler throwing is a
different matter and propagates, unless you ask for
`setToolLoop(catchErrors: true)`.

Prefer to drive the loop yourself? Leave the handler out and nothing happens
behind your back:

```php
$response = $chat->sendMessage('What is the weather in Brno?');

foreach ($response->getToolCalls() as $call) {
	$chat->addToolResult($call, $weatherService->for($call->arguments['city']));
}

echo $chat->sendMessage()->getText();
```

Because one exchange can span several requests, `$chat->getTotalUsage()` reports
what the whole thing cost; `$response->getUsage()` is only the last round.

 <!---->

Images and Documents
====================

▶ Full runnable example: [examples/multimodal/image-input.php](examples/multimodal/image-input.php)

A message is not only text. Pass a picture or a PDF alongside the words:

```php
use AIAccess\Media;

$response = $chat->sendMessage([
	'What is on this invoice?',
	Media::fromFile('invoice.pdf'),
]);
```

`Media::fromFile()` reads the mime type from the file itself, `Media::fromBinary()`
takes data you already hold. It is the same object image generation returns, so a
generated picture can go straight back into a conversation without touching the
disk.

Where a provider cannot take the content, DeepSeek having no vision model and
Grok no documents, you get a `LogicException` naming the mime type **before** the
request leaves, rather than a puzzling 400 afterwards.

The picture stays in the history, so follow-up questions still see it. It is also
sent again on every turn, which is what the APIs require and what you pay for, so
drop it from the history once you are done with it.

 <!---->

Structured Output
=================

▶ Full runnable example: [examples/structured-output/extraction.php](examples/structured-output/extraction.php)

Ask for data instead of prose. Hand the model a JSON Schema and read the result
as a PHP array:

```php
$chat->setResponseSchema([
	'type' => 'object',
	'properties' => [
		'name' => ['type' => 'string'],
		'founded' => ['type' => 'integer'],
	],
	'required' => ['name', 'founded'],
	'additionalProperties' => false,
]);

$data = $chat->sendMessage($text)->getJson();
echo $data['name'];
```

The four providers that support this want the schema in four different shapes,
one of them nested two levels deeper than the rest. You write it once.

DeepSeek has no schema enforcement, so it simply has no `setResponseSchema()`
method: your IDE and PHPStan say so before you run the code, instead of the API
saying so afterwards. That is the honest-abstraction principle in practice.

 <!---->

Batch Processing
================
▶ Full runnable examples: [examples/batch/](examples/batch)


When you do not need answers immediately, batch processing gets you the same models at **half the price**. Supported by OpenAI, Claude and Gemini; all three use completely different mechanics under the hood (a JSONL file upload, inline requests, and a long-running operation), and AIAccess hides that difference entirely:

```php
use AIAccess\Chat\Role;

$batch = $client->createBatch();

$chat = $batch->addChat($model, 'greeting-1');
$chat->setSystemInstruction('Be brief and friendly.');
$chat->addMessage('Hi!', Role::User);

$chat = $batch->addChat($model, 'translate-1');
$chat->addMessage('Translate to French: Hello world', Role::User);

$response = $batch->submit();   // returns immediately
$batchId = $response->getId();  // store it; results arrive within minutes to 24h
```

Later, from a cron job or queue worker:

```php
use AIAccess\Batch\Status;

$batch = $client->retrieveBatch($batchId);

if ($batch->getStatus() === Status::Completed) {
	foreach ($batch->getMessages() as $customId => $message) {
		echo "$customId: ", $message->getText(), "\n";
	}
}
```

`listBatches()` and `cancelBatch()` complete the toolkit.

 <!---->

Embeddings
==========
▶ Full runnable examples: [examples/embeddings/](examples/embeddings)


Embeddings turn text into numeric vectors that capture meaning, the foundation of semantic search, clustering, recommendations and RAG. Supported by OpenAI and Gemini:

```php
$vectors = $client->calculateEmbeddings('text-embedding-3-small', [
	'PHP is a popular general-purpose scripting language.',
	'Paris is the capital of France.',
]);

$similarity = $vectors[0]->cosineSimilarity($vectors[1]);
```

Each `Vector` serializes to a compact binary string, roughly four bytes per dimension, ideal for a database BLOB column:

```php
$binary = $vectors[0]->serialize();
// ...store, load...
$vector = AIAccess\Embedding\Vector::deserialize($binary);
```

Provider-specific options (OpenAI `dimensions`, Gemini `taskType`, ...) are again typed named arguments on the client's `calculateEmbeddings()` method.

 <!---->

Error Handling
==============
▶ Full runnable example: [examples/errors/handling.php](examples/errors/handling.php)


The exception hierarchy is organized around recovery strategy, so a `catch` block reads like an incident-response plan:

```
ServiceException                  base for everything the service can throw
├── ApiException                  the API returned an error (rate limit, invalid key...)
├── CommunicationException        network failure or unparseable response → retry may help
├── UnexpectedResponseException   response structure changed → log and investigate
└── TooManyRoundsException        the tool loop hit its round limit → the last response rides along
LogicException                    a bug in your code → fix it in development
IOException                       a local file could not be read or written
```

```php
try {
	$response = $chat->sendMessage('...');

} catch (AIAccess\ApiException $e) {
	// the provider said no; $e->getCode() carries the HTTP status
	if ($e->getCode() === 429) {
		// rate limited: back off and retry later
	}

} catch (AIAccess\CommunicationException $e) {
	// network hiccup: safe to retry

} catch (AIAccess\ServiceException $e) {
	// anything else service-related
}
```

`LogicException` (wrong arguments, calling methods in the wrong order) is deliberately outside the `ServiceException` tree: it signals a programming error you want to crash loudly in development, not something to catch in production. It extends PHP's own `\LogicException`, which is where any PHP developer expects to find it. `IOException` sits outside for a similar reason: a file that cannot be read or written (`Media::fromFile()`, saving media, uploading a batch) is a local filesystem problem, not something the service said, and it extends PHP's `\RuntimeException`.

 <!---->

[Support Me](https://github.com/sponsors/dg)
============

Do you like AI Access? Are you looking forward to new features?

[![Buy me a coffee](https://files.nette.org/icons/donation-3.svg)](https://github.com/sponsors/dg)

Thank you!
