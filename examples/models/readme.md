# Models

Model names are plain strings in this library, which is why a new model works
the day it ships without a library update. The flip side is that a retired one
keeps working right up until it does not.

```shell
php examples/models/list.php claude
php examples/models/list.php openai gpt-5.6
```

## list.php

Asks the provider what it currently offers and checks that the model the other
examples default to is still on the list.

That check is not decoration. In the year before this was written every
provider retired models: DeepSeek dropped both of its old names in one day, xAI
retired its entire grok-3 and grok-4 line, Anthropic and Google switched off
several more. A test suite notices none of that, because it never calls anyone.

xAI makes it worse by being helpful: ask for `grok-3` today and you get HTTP 200
with `grok-4.3` quietly substituted and billed. A smoke test passes, the bill
changes. Comparing against `listModels()` is the only thing that catches it.

`Model` deliberately carries just an `id` plus the provider's untouched `raw`
record. Context windows, pricing and capability flags live in different places
under different names in all five APIs, and any attempt to unify them would be
a table going stale in the background.
