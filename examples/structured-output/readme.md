# Structured output

Sometimes you do not want prose, you want a record. Give the model a JSON
Schema and it answers with data that fits it, which you read as a PHP array.

```shell
php examples/structured-output/extraction.php openai
php examples/structured-output/extraction.php claude
```

Supported by OpenAI, Claude, Gemini and Grok. DeepSeek has a JSON mode but no
schema enforcement, so `setResponseSchema()` throws there and points at
`setOptions(responseFormat: ['type' => 'json_object'])` instead. That mode wants
the word "json" somewhere in the conversation, or the API refuses the request.

## extraction.php

The classic use: unstructured text in, typed record out.

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

Under the hood the four providers want this in four different shapes, one of
them nested two levels deeper than the others, and Gemini quietly deprecated
its original form in favour of a new field. You write the schema once.

`getJson()` returns `null` when there was no text at all, and throws
`UnexpectedResponseException` when what came back is not valid JSON. Ask for a
schema and you will not normally see either, but a model that refuses the
request still refuses it.

Keep `additionalProperties: false` and list everything in `required`: several
providers insist on both in strict mode.
