# Tools

A tool is a function you describe to the model. When the model decides it needs
it, it asks for the call with arguments, you answer, and the conversation carries
on with the answer in it. All five providers can do this, each in its own wire
format; AI Access gives you one.

```shell
php examples/tools/weather.php claude
php examples/tools/manual-loop.php openai
```

## weather.php

Give the tool a handler and the library runs the whole loop: it calls your code,
sends the result back and returns only when the model is finished. One
`sendMessage()`, however many rounds it takes.

```
Output (will vary):
Brno is at 18 °C with clear skies, while Reykjavik sits at 4 °C in the rain.

Rounds in the history: 4
Tokens for the whole exchange: 1284
```

`getTotalUsage()` covers every round, while `Response::getUsage()` is only the
last one; with a loop the last round says little about the cost.

The loop stops after `setToolLoop(maxRounds:)` rounds, eight by default, and the
limit counts `validate()` iterations too. Hitting it is an exception rather than
a quiet half-answer.

## manual-loop.php

Leave the handler out and nothing runs behind your back: `getToolCalls()` gives
you the calls, `addToolResult()` answers them, and the next `sendMessage()`
continues. Useful when the call needs a confirmation, a queue, or anything else
that does not fit in a closure.

## Mistakes are the model's to fix

An invented tool name, arguments that will not decode, arguments that do not
match the schema: none of these throw. They go back to the model as an error
result, and it usually corrects itself on the next round. Your handler throwing
is different: that propagates, unless you ask for
`setToolLoop(catchErrors: true)` — and even then a PHP `Error`, meaning a bug in
the handler rather than a failure of the tool, still comes out rather than being
explained to the model.

Return something the model can act on. An empty result is accepted by the APIs,
but the model then has nothing to go on and will answer vaguely; `['error' =>
'no station in Brno']` gets you a better conversation than `null`.
