<?php

namespace Gen;

class Numbers implements \IteratorAggregate
{
    public function getIterator(): \Generator
    {
        yield 1;
        yield from [2, 3];
    }

    public function plain(): array
    {
        // The yield belongs to the closure, not to plain().
        $f = function () {
            yield 1;
        };

        return [$f];
    }
}

function countUp(int $n): \Generator
{
    for ($i = 0; $i < $n; $i++) {
        yield $i;
    }
}

function notAGenerator(): int
{
    return 1;
}
