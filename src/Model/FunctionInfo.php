<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Model;

/**
 * Immutable representation of a global (or namespaced) function.
 */
final readonly class FunctionInfo
{
    /**
     * @param string          $fqn    Fully qualified name, e.g. "App\Util\slugify"
     * @param list<ParamInfo> $params
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public array $params,
        public ?string $returnType,
        public ?string $docComment,
        public string $file,
        public int $startLine,
        public bool $isGenerator = false,
    ) {
    }
}
