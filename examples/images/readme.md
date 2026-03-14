# Images

Text goes in, a picture comes out, and the binary data lands in your hands
already decoded. No base64 juggling.

```shell
php examples/images/generate.php openai
php examples/images/generate.php grok
php examples/images/edit.php openai cover.png
```

## generate.php

One prompt, one image, saved to disk. `Media` carries the raw bytes, the mime
type and, as everywhere in this library, the untouched provider response if you
need something the abstraction skipped. The same object goes back in as a
reference below, so a generated image never has to touch the disk.

Both providers here return a single image because that is what the interface
promises. If you want four variations, ask four times; batching images is a
provider-specific optimisation, not something worth pretending is portable.

## edit.php

The interesting one, and OpenAI only. Pass reference images along with the
prompt and the model works from them instead of from words alone: same
composition in a different season, same style applied to new content, a series
that has to look like it belongs together.

```php
$image = $client->generateImage(
	'gpt-image-2',
	'Keep the composition and palette, but make it a winter scene.',
	references: [AIAccess\Media::fromFile('cover.png')],
	size: '1024x1024',
	quality: 'high',
);
```

Up to ten references, PNG, JPEG or WebP. Behind the scenes this switches from
the generations endpoint to the edits endpoint and uploads the files as
multipart, which is exactly the sort of difference you should not have to care
about.

Expect this call to take a while. Medium quality lands in under a minute, high
quality with a large reference can run past the default three-minute timeout
and come back as a `CommunicationException`. Give it more room when you need
the quality:

```php
$http = (new AIAccess\Http\CurlClient)->setOptions(requestTimeout: 600);
$client = new AIAccess\Provider\OpenAI\Client($apiKey, $http);
```
