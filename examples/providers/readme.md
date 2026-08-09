# Other providers

Most services that are not one of the five built-in ones speak the OpenAI chat
dialect anyway. `OpenAICompatible\Client` takes a base URL and talks to any of
them, so Ollama on your laptop, Mistral, OpenRouter, Together, vLLM and Azure
all work without a new provider class.

```shell
php examples/providers/ollama.php        # needs a local Ollama
php examples/providers/openrouter.php    # needs OPENROUTER_API_KEY
```

Unlike the rest, these examples do not get their client from the shared
bootstrap: the point is that you construct it yourself with the URL of the
service you want.

## ollama.php

A local model, no API key, nothing sent anywhere. The empty key is meaningful:
it tells the client to skip the authorization header entirely, which is what
local servers expect.

```php
$client = new AIAccess\Provider\OpenAICompatible\Client('', 'http://localhost:11434/v1');
```

## openrouter.php

One key, hundreds of models behind it. OpenRouter also likes a few identifying
headers, which is what `extraHeaders` is for.

## Azure and other dialects

Azure wants the key in its own header instead of a bearer token:

```php
$client = new AIAccess\Provider\OpenAICompatible\Client($key, 'https://<resource>.openai.azure.com/openai/v1');
$client->setOptions(authHeader: 'api-key', authPrefix: '');
```

And when a service accepts a parameter nobody else has, `custom` passes it
through untouched rather than making you fork the library:

```php
$chat->setOptions(custom: ['safe_prompt' => true]);
```

That escape hatch is deliberate here and nowhere else. Compatible endpoints are
a moving target with dozens of dialects; the five first-class providers get
typed named arguments instead, because there we know exactly what exists.
