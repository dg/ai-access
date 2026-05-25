# Multimodal input

Text is not the only thing you can put in a message. Pass a `Media` object
alongside the words and the model sees the picture, or reads the document.

```shell
php examples/multimodal/image-input.php claude photo.jpg
php examples/multimodal/image-input.php openai chart.png
```

## image-input.php

```php
$response = $chat->sendMessage([
	'What is in this picture?',
	AIAccess\Media::fromFile('photo.jpg'),
]);
```

```
Output (will vary):
A tabby cat asleep on a grey sofa, lit by afternoon sun from the left.
Warm greys dominate, with the orange of the cat's fur as the accent.
```

The image stays in the history, so the second question still sees it — but the
history is what every request is built from, which means **the picture is sent
again, and paid for again, on every turn**. A short exchange about a large image
costs more than it looks. If that matters, drop the picture from the history once
you have what you need, or lean on the provider's prompt caching.

## Who can take what

| | images | documents |
|---|---|---|
| Claude | ✓ | ✓ PDF |
| OpenAI | ✓ | ✓ PDF |
| Gemini | ✓ | ✓ PDF |
| Grok | ✓ | – |
| DeepSeek | – | – |

Where a provider cannot take the content, the library says so **before** the
request goes out, naming the mime type, instead of letting the API answer with a
puzzling 400.

`Media::fromFile()` reads the mime type from the file itself and remembers the
name, which is what OpenAI shows the model for documents. `Media::fromBinary()`
takes data you already have in memory — a generated image, a database blob, an
upload — with the mime type and, optionally, a name.

Everything travels inline as base64, so it counts towards the request size.
Providers cap that in the low tens of megabytes; for anything larger their Files
APIs exist, and AI Access does not wrap them yet.
