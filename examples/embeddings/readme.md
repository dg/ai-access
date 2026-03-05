# Embeddings

An embedding turns a piece of text into a list of numbers that captures what it
means. Two texts about the same thing end up close together even when they share
no words, which is what makes semantic search, clustering and RAG possible.

Supported by OpenAI and Gemini. Ask for it from any other provider and the
example stops with a list of the ones that can.

```shell
php examples/embeddings/similarity.php openai
php examples/embeddings/storage.php gemini
```

## similarity.php

Three sentences, two comparisons. The first pair says the same thing in
different words, the second pair is about something else entirely, and the
numbers show it.

```
Output (will vary):
0.8721  The cat is sleeping on the sofa. <-> A kitten naps on the couch.
0.1043  The cat is sleeping on the sofa. <-> PHP 8.3 introduced typed class constants.
```

Cosine similarity runs from -1 to 1. In practice you rarely see negative values;
what matters is the ordering, not the absolute number.

## storage.php

Vectors are large, and keeping them as JSON wastes both space and precision.
`serialize()` packs them into little-endian 32bit floats, four bytes per
dimension, ready for a BLOB column. `deserialize()` reads them back.

The format is deliberately architecture-independent, so a vector written on one
machine can be read on another.
