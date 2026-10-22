<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Docs;

final readonly class MethodDoc
{
    /**
     * @param list<ParamDoc> $params
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        /** Rendered signature, e.g. "find(string $id): ?object" */
        public string $signature,
        public ?string $summary,
        public array $params,
        public ?string $returnType,
        public ?string $returnDescription,
        /** FQCN the docblock was inherited from via {@inheritdoc}, if any. */
        public ?string $inheritedFrom,
        public bool $documented,
        public int $line,
        public bool $isGenerator = false,
    ) {
    }
}
