# Images

Text goes in, a picture comes out, and the binary data lands in your hands
already decoded. No base64 juggling.

```shell
php examples/images/generate.php openai
php examples/images/generate.php grok
php examples/images/edit.php openai cover.png
php examples/images/batch.php openai
```

## generate.php

One prompt, one image, saved to disk. `Media` carries the raw bytes, the mime
type and, as everywhere in this library, the untouched provider response if you
need something the abstraction skipped. The same object goes back in as a
reference below, so a generated image never has to touch the disk.

Every provider here returns a single image, and whatever else it takes is a named
argument of its own `generateImage()`. When one prompt and one picture is not
enough, the answer is a batch, not a second way of asking.

The three of them work in entirely different ways underneath: OpenAI and xAI
have dedicated image endpoints, while Gemini has none — you ask an image model
through the ordinary chat endpoint and the picture comes back as a content part.

## edit.php

Pass reference images along with the prompt and the model works from them
instead of from words alone: same composition in a different season, same style
applied to new content, a series that has to look like it belongs together.
OpenAI and Gemini can do this; xAI takes a prompt only and says so.

```php
$image = $client->generateImage(
	'Keep the composition and palette, but make it a winter scene.',
	'gpt-image-2',
	references: [AIAccess\Media::fromFile('cover.png')],
	size: '1024x1024',
	quality: 'high',
);
```

Up to sixteen references, PNG, JPEG or WebP. Behind the scenes this switches
from the generations endpoint to the edits endpoint and inlines the bytes as
data URLs, which is exactly the sort of difference you should not have to care
about. In a batch every request carries its own copy of the reference, so a
large one repeated across many requests counts against the 200 MB the provider
accepts for a whole job.

Expect this call to take a while. Medium quality lands in under a minute, high
quality with a large reference can run past the default three-minute timeout
and come back as a `CommunicationException`. Give it more room when you need
the quality:

```php
$http = (new AIAccess\Http\CurlClient)->setOptions(requestTimeout: 600);
$client = new AIAccess\Provider\OpenAI\Client($apiKey, $http);
```

## batch.php

Three prompts queued as one job, at roughly half the price, with the pictures
collected later by [examples/batch/results.php](../batch/results.php). OpenAI
and Gemini offer this; the results are ordinary messages whose media parts hold
the images, so the same script reads a batch of answers and a batch of pictures.

At OpenAI a job runs one endpoint and one model, so prompts and edits cannot
share it: generating and editing are two different endpoints, and adding both to
one batch raises a `LogicException` before anything is uploaded. Gemini has no
endpoint split; there only the usual one-model rule applies.
