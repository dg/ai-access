# AI Access examples

Runnable programs, one concept each. Every one of them takes the provider as its
first argument, so the same file talks to five different companies:

```shell
php examples/chat/basic.php openai
php examples/chat/basic.php claude
php examples/chat/basic.php gemini
php examples/chat/basic.php deepseek
php examples/chat/basic.php grok
```

## Setup

```shell
composer install
cp examples/.env.example examples/.env
```

Then fill in the keys of the providers you want to try. You only need the ones
you actually use; an example that needs a key you have not set says so and
stops. The same names work as environment variables, which is how the CI runs
these against the live APIs once a month.

Where a feature is not available everywhere, the example tells you who supports
it instead of failing with an API error:

```
Provider 'grok' does not support this feature. Supported: openai, gemini
```

## Where to start

Read them in this order; each directory has its own readme with the details.

| | |
|---|---|
| [chat/](chat) | send a message, keep a conversation, set the rules, control cost and thinking effort |
| [embeddings/](embeddings) | compare texts by meaning, store vectors compactly |
| [batch/](batch) | queue many requests at half the price and collect them later |
| [errors/](errors) | the exception hierarchy and what to do about each case |

## A note on output

Model output is not deterministic. Where a readme shows a sample run, treat it
as an illustration of the shape, not as something to compare against
character by character.
