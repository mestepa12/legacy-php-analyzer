<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Docs;

final readonly class FunctionDoc
{
    /**
     * @param list<ParamDoc> $params
     */
    public function __construct(
        public string $fqn,
        public string $signature,
        public ?string $summary,
        public array $params,
        public ?string $returnType,
        public ?string $returnDescription,
        public bool $documented,
        public string $file,
        public int $line,
        public bool $isGenerator = false,
    ) {
    }
}
