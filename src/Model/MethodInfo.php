<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Model;

/**
 * Immutable representation of a method declared in a class, interface,
 * trait or enum.
 *
 * `hasBody` is false for interface methods and abstract methods: those
 * are contracts, never dead-code candidates (design decision #8).
 */
final readonly class MethodInfo
{
    /**
     * @param list<ParamInfo> $params
     */
    public function __construct(
        public string $name,
        public Visibility $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public bool $isFinal,
        public bool $hasBody,
        public array $params,
        public ?string $returnType,
        public ?string $docComment,
        public int $startLine,
        public bool $isGenerator = false,
    ) {
    }
}
