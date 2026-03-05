# Chat

Everything here works the same on all five providers. Pass the provider name as
the first argument and watch the same code talk to a different company.

```shell
php examples/chat/basic.php claude
php examples/chat/conversation.php gemini
php examples/chat/system-instruction.php openai
php examples/chat/options.php deepseek
```

## basic.php

The whole library in five lines: create a chat, send a message, read the answer.
The response object also carries token usage, which is worth printing while you
are still calibrating costs.

```
[claude]
Curly braces bloom,
requests flow through server veins,
echo fills the void.

17 tokens in / 28 out
```

## conversation.php

`sendMessage()` appends both your message and the model's reply to the history,
so a multi-turn conversation needs no bookkeeping on your side. The example asks
a follow-up question that only makes sense if the model still remembers the
previous answer.

You can also build the history by hand with `addMessage()` and then call
`sendMessage()` with no argument. That is how you restore a conversation from a
database, or feed the model a few examples before the real question.

## system-instruction.php

A system instruction sets the rules for the whole conversation: persona, tone,
output format. It is not part of the message history, so it applies to every
turn without being repeated.

## options.php

Providers are retiring `temperature` on their newest reasoning models: Claude
answers HTTP 400 on Opus 4.7 and on the whole Claude 5 line, GPT-5.1+ answers 400
unless reasoning effort is none, Gemini ignores it silently, DeepSeek ignores it
whenever thinking is on, which is by default. What replaced it is a single dial:
how hard should the model think before answering.

`setEffort()` maps that dial to whatever each provider calls it, so
`Effort::Low` is a cheap fast answer everywhere and `Effort::High` a slow
careful one. Watch the reasoning tokens in the output to see the difference in
what you are paying for.

Not every model has the dial. Older ones, Claude Haiku 4.5 among them, reject
it with an `ApiException`, and that is deliberate: the library sends what you
asked for rather than keeping a table of model capabilities that would be stale
within weeks.

Everything else is genuinely provider-specific, so it lives as typed named
arguments on the concrete `Chat` class, where your IDE can offer it:

```php
$client = new AIAccess\Provider\Claude\Client($apiKey);
$chat = $client->createChat('claude-sonnet-5');
$chat->setOptions(maxOutputTokens: 200, stopSequences: ['END']);
```

OpenAI takes the same limit under the same name, but knows nothing about stop
sequences and offers `store:` instead. There is no lowest common denominator
here on purpose: an option array shared by everyone would silently swallow the
names that do not apply.
