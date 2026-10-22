<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Output;

use LegacyAnalyzer\Cli\AnalyzeCommand;

/**
 * Serializes an AnalysisResult into the tool's stable JSON schema.
 *
 * `meta.schemaVersion` is bumped on any breaking change to this shape:
 * the HTML reporter and any external consumer key off it instead of
 * guessing from the structure.
 */
final class JsonReporter
{
    public const SCHEMA_VERSION = 1;

    public function toJson(AnalysisResult $result, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($this->toArray($result), $flags);
    }

    /** @return array<string, mixed> */
    public function toArray(AnalysisResult $result): array
    {
        $codebase = $result->parseResult->codebase;

        return [
            'meta' => [
                'schemaVersion' => self::SCHEMA_VERSION,
                'tool' => [
                    'name' => 'legacy-php-analyzer',
                    'version' => AnalyzeCommand::VERSION,
                ],
                'analyzedPath' => $result->analyzedPath,
                'generatedAt' => $result->generatedAt,
                'filesScanned' => $codebase->fileCount(),
                'filesWithParseErrors' => count($codebase->errors()),
                'excludedDirs' => $result->excludedDirs,
            ],
            'errors' => array_map(
                static fn ($e) => ['file' => $e->file, 'message' => $e->message],
                $codebase->errors(),
            ),
            'graph' => [
                'nodes' => array_map(
                    static fn ($n) => ['name' => $n->name, 'kind' => $n->kind->value],
                    $result->graph->nodes(),
                ),
                'edges' => array_map(
                    static fn ($e) => [
                        'from' => $e->from,
                        'to' => $e->to,
                        'type' => $e->type->value,
                        'member' => $e->member,
                        'file' => $e->file,
                        'line' => $e->line,
                    ],
                    $result->graph->edges(),
                ),
            ],
            'deadCode' => [
                'classes' => array_map($this->deadSymbol(...), $result->deadCode->classes),
                'methods' => array_map($this->deadSymbol(...), $result->deadCode->methods),
                'functions' => array_map($this->deadSymbol(...), $result->deadCode->functions),
                'dynamicReferenceCounts' => (object) $result->deadCode->dynamicReferenceCounts,
            ],
            'docs' => [
                'coverage' => [
                    'classLikesTotal' => $result->docs->coverage->classLikesTotal,
                    'classLikesDocumented' => $result->docs->coverage->classLikesDocumented,
                    'methodsTotal' => $result->docs->coverage->methodsTotal,
                    'methodsDocumented' => $result->docs->coverage->methodsDocumented,
                    'functionsTotal' => $result->docs->coverage->functionsTotal,
                    'functionsDocumented' => $result->docs->coverage->functionsDocumented,
                ],
                'classes' => array_map($this->classDoc(...), $result->docs->classes),
                'functions' => array_map($this->functionDoc(...), $result->docs->functions),
                'discrepancies' => array_map(
                    static fn ($d) => [
                        'location' => $d->location,
                        'kind' => $d->kind->value,
                        'detail' => $d->detail,
                        'file' => $d->file,
                        'line' => $d->line,
                    ],
                    $result->docs->discrepancies,
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function deadSymbol(object $symbol): array
    {
        return [
            'identifier' => $symbol->identifier,
            'type' => $symbol->type,
            'file' => $symbol->file,
            'line' => $symbol->line,
            'confidence' => $symbol->confidence->value,
            'reason' => $symbol->reason,
        ];
    }

    /** @return array<string, mixed> */
    private function classDoc(object $class): array
    {
        return [
            'fqcn' => $class->fqcn,
            'kind' => $class->kind,
            'summary' => $class->summary,
            'extends' => $class->extends,
            'implements' => $class->implements,
            'traits' => $class->traits,
            'documented' => $class->documented,
            'file' => $class->file,
            'line' => $class->line,
            'methods' => array_map(
                fn ($m) => [
                    'name' => $m->name,
                    'visibility' => $m->visibility,
                    'static' => $m->isStatic,
                    'abstract' => $m->isAbstract,
                    'signature' => $m->signature,
                    'summary' => $m->summary,
                    'params' => array_map($this->paramDoc(...), $m->params),
                    'returnType' => $m->returnType,
                    'returnDescription' => $m->returnDescription,
                    'inheritedFrom' => $m->inheritedFrom,
                    'documented' => $m->documented,
                    'line' => $m->line,
                    'generator' => $m->isGenerator,
                ],
                $class->methods,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function functionDoc(object $function): array
    {
        return [
            'fqn' => $function->fqn,
            'signature' => $function->signature,
            'summary' => $function->summary,
            'params' => array_map($this->paramDoc(...), $function->params),
            'returnType' => $function->returnType,
            'returnDescription' => $function->returnDescription,
            'documented' => $function->documented,
            'file' => $function->file,
            'line' => $function->line,
            'generator' => $function->isGenerator,
        ];
    }

    /** @return array<string, mixed> */
    private function paramDoc(object $param): array
    {
        return [
            'name' => $param->name,
            'type' => $param->type,
            'variadic' => $param->variadic,
            'hasDefault' => $param->hasDefault,
            'description' => $param->description,
        ];
    }
}
