<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Docs;

use LegacyAnalyzer\Model\ClassLikeInfo;
use LegacyAnalyzer\Model\Codebase;
use LegacyAnalyzer\Model\FunctionInfo;
use LegacyAnalyzer\Model\MethodInfo;
use LegacyAnalyzer\Model\ParamInfo;

/**
 * Generates basic documentation from existing PHPDoc + real signatures,
 * and flags doc-vs-code discrepancies. Signatures are authoritative:
 * docblocks only contribute prose and fill type gaps.
 *
 * {@inheritdoc} is resolved through extends → implements → traits
 * (depth-first, skipping ancestors that have no doc or inherit
 * themselves). The inherited doc is then compared against the REAL
 * signature of the inheriting method: overrides that changed their
 * parameters without touching documentation are exactly the bug this
 * phase exists to catch.
 */
final class DocGenerator
{
    private Codebase $codebase;

    /** @var list<Discrepancy> */
    private array $discrepancies = [];

    public function generate(Codebase $codebase): DocReport
    {
        $this->codebase = $codebase;
        $this->discrepancies = [];

        $classes = [];
        $classLikesDocumented = 0;
        $methodsTotal = 0;
        $methodsDocumented = 0;

        foreach ($codebase->classLikes() as $classLike) {
            $classDoc = $this->buildClassDoc($classLike);
            $classes[] = $classDoc;
            $classLikesDocumented += (int) $classDoc->documented;
            $methodsTotal += count($classDoc->methods);

            foreach ($classDoc->methods as $method) {
                $methodsDocumented += (int) $method->documented;
            }
        }

        $functions = [];
        $functionsDocumented = 0;

        foreach ($codebase->functions() as $function) {
            $functionDoc = $this->buildFunctionDoc($function);
            $functions[] = $functionDoc;
            $functionsDocumented += (int) $functionDoc->documented;
        }

        usort($classes, static fn (ClassDoc $a, ClassDoc $b): int => strcasecmp($a->fqcn, $b->fqcn));
        usort($functions, static fn (FunctionDoc $a, FunctionDoc $b): int => strcasecmp($a->fqn, $b->fqn));
        usort(
            $this->discrepancies,
            static fn (Discrepancy $a, Discrepancy $b): int => [$a->file, $a->line, $a->detail] <=> [$b->file, $b->line, $b->detail],
        );

        return new DocReport(
            classes: $classes,
            functions: $functions,
            discrepancies: $this->discrepancies,
            coverage: new DocCoverage(
                classLikesTotal: count($classes),
                classLikesDocumented: $classLikesDocumented,
                methodsTotal: $methodsTotal,
                methodsDocumented: $methodsDocumented,
                functionsTotal: count($functions),
                functionsDocumented: $functionsDocumented,
            ),
        );
    }

    // --- builders ------------------------------------------------------------

    private function buildClassDoc(ClassLikeInfo $classLike): ClassDoc
    {
        $doc = PhpDocParser::parse($classLike->docComment);
        $methods = [];

        foreach ($classLike->methods as $method) {
            $methods[] = $this->buildMethodDoc($classLike, $method);
        }

        return new ClassDoc(
            fqcn: $classLike->fqcn,
            kind: $classLike->kind->value,
            summary: $doc?->summary,
            extends: $classLike->extends,
            implements: $classLike->implements,
            traits: $classLike->traits,
            methods: $methods,
            documented: $doc !== null,
            file: $classLike->file,
            line: $classLike->startLine,
        );
    }

    private function buildMethodDoc(ClassLikeInfo $classLike, MethodInfo $method): MethodDoc
    {
        $ownDoc = PhpDocParser::parse($method->docComment);
        $location = $classLike->fqcn . '::' . $method->name;
        $effectiveDoc = $ownDoc;
        $inheritedFrom = null;

        if ($ownDoc !== null && $ownDoc->isInheritDoc) {
            $resolved = $this->resolveInheritedDoc($classLike, $method->name);

            if ($resolved === null) {
                $this->discrepancies[] = new Discrepancy(
                    location: $location,
                    kind: DiscrepancyKind::InheritdocUnresolvable,
                    detail: '{@inheritdoc} found, but no ancestor inside the project documents ' . $method->name . '()',
                    file: $classLike->file,
                    line: $method->startLine,
                );
            } else {
                [$inheritedFrom, $inheritedDoc] = $resolved;
                $effectiveDoc = $inheritedDoc;

                // The valuable check: inherited documentation vs the
                // override's REAL signature.
                foreach ($this->compare($inheritedDoc, $method->params, $method->returnType, $classLike->fqcn) as [$_, $detail]) {
                    $this->discrepancies[] = new Discrepancy(
                        location: $location,
                        kind: DiscrepancyKind::InheritdocSignatureMismatch,
                        detail: $detail . ' (doc inherited from ' . $inheritedFrom . ')',
                        file: $classLike->file,
                        line: $method->startLine,
                    );
                }
            }
        } elseif ($ownDoc !== null) {
            foreach ($this->compare($ownDoc, $method->params, $method->returnType, $classLike->fqcn) as [$kind, $detail]) {
                $this->discrepancies[] = new Discrepancy(
                    location: $location,
                    kind: $kind,
                    detail: $detail,
                    file: $classLike->file,
                    line: $method->startLine,
                );
            }
        }

        return new MethodDoc(
            name: $method->name,
            visibility: $method->visibility->value,
            isStatic: $method->isStatic,
            isAbstract: $method->isAbstract,
            signature: $this->renderSignature($method->name, $method->params, $method->returnType),
            summary: $effectiveDoc?->summary,
            params: $this->mergeParams($method->params, $effectiveDoc),
            returnType: $method->returnType ?? $effectiveDoc?->returnType,
            returnDescription: $effectiveDoc?->returnDescription,
            inheritedFrom: $inheritedFrom,
            documented: $ownDoc !== null,
            line: $method->startLine,
            isGenerator: $method->isGenerator,
        );
    }

    private function buildFunctionDoc(FunctionInfo $function): FunctionDoc
    {
        $doc = PhpDocParser::parse($function->docComment);

        if ($doc !== null) {
            foreach ($this->compare($doc, $function->params, $function->returnType, null) as [$kind, $detail]) {
                $this->discrepancies[] = new Discrepancy(
                    location: $function->fqn,
                    kind: $kind,
                    detail: $detail,
                    file: $function->file,
                    line: $function->startLine,
                );
            }
        }

        return new FunctionDoc(
            fqn: $function->fqn,
            signature: $this->renderSignature($function->shortName, $function->params, $function->returnType),
            summary: $doc?->summary,
            params: $this->mergeParams($function->params, $doc),
            returnType: $function->returnType ?? $doc?->returnType,
            returnDescription: $doc?->returnDescription,
            documented: $doc !== null,
            file: $function->file,
            line: $function->startLine,
            isGenerator: $function->isGenerator,
        );
    }

    // --- inheritdoc resolution --------------------------------------------

    /**
     * Depth-first over extends → implements → traits. Returns the first
     * ancestor that declares the method WITH a real (non-inheritdoc)
     * docblock, or null when the chain leaves the project.
     *
     * @param  array<string, true> $visited
     * @return array{0: string, 1: ParsedDoc}|null [ancestor FQCN, its doc]
     */
    private function resolveInheritedDoc(ClassLikeInfo $classLike, string $methodName, array $visited = []): ?array
    {
        $chain = [...$classLike->extends, ...$classLike->implements, ...$classLike->traits];

        foreach ($chain as $ancestorName) {
            $key = strtolower(ltrim($ancestorName, '\\'));

            if (isset($visited[$key])) {
                continue;
            }

            $visited[$key] = true;
            $ancestor = $this->codebase->classLike($ancestorName);

            if ($ancestor === null) {
                continue; // outside the project (vendor / built-in)
            }

            $method = $ancestor->method($methodName);

            if ($method !== null) {
                $doc = PhpDocParser::parse($method->docComment);

                if ($doc !== null && !$doc->isInheritDoc) {
                    return [$ancestor->fqcn, $doc];
                }
            }

            $fromAbove = $this->resolveInheritedDoc($ancestor, $methodName, $visited);

            if ($fromAbove !== null) {
                return $fromAbove;
            }
        }

        return null;
    }

    // --- doc vs signature comparison ------------------------------------------

    /**
     * @param  list<ParamInfo> $params
     * @return list<array{0: DiscrepancyKind, 1: string}>
     */
    private function compare(ParsedDoc $doc, array $params, ?string $returnType, ?string $selfFqcn): array
    {
        $issues = [];
        $byName = [];

        foreach ($params as $param) {
            $byName[strtolower($param->name)] = $param;
        }

        foreach ($doc->params as $tag) {
            if ($tag->name === null) {
                continue; // malformed tag, nothing to match against
            }

            $sigParam = $byName[strtolower($tag->name)] ?? null;

            if ($sigParam === null) {
                $issues[] = [
                    DiscrepancyKind::ParamUnknown,
                    'documented parameter $' . $tag->name . ' does not exist in the signature',
                ];

                continue;
            }

            // Asymmetric variadic rule: a docblock omitting `...` is
            // tolerated (PHPDoc rarely bothers), but claiming `...` on
            // a non-variadic parameter documents something unreal.
            if ($tag->variadicClaimed && !$sigParam->variadic) {
                $issues[] = [
                    DiscrepancyKind::ParamVariadicMismatch,
                    'documented as variadic (...$' . $tag->name . ') but the parameter is not',
                ];
            }

            $docType = TypeNormalizer::canonicalize($tag->type, $selfFqcn);
            $sigType = TypeNormalizer::canonicalize($sigParam->type, $selfFqcn);

            if ($docType !== null && $sigType !== null && $docType !== $sigType) {
                $issues[] = [
                    DiscrepancyKind::ParamTypeMismatch,
                    sprintf('$%s documented as "%s", signature says "%s"', $tag->name, $tag->type, $sigParam->type),
                ];
            }
        }

        $docReturn = TypeNormalizer::canonicalize($doc->returnType, $selfFqcn);
        $sigReturn = TypeNormalizer::canonicalize($returnType, $selfFqcn);

        if ($docReturn !== null && $sigReturn !== null && $docReturn !== $sigReturn) {
            $issues[] = [
                DiscrepancyKind::ReturnTypeMismatch,
                sprintf('return documented as "%s", signature says "%s"', $doc->returnType, $returnType),
            ];
        }

        return $issues;
    }

    // --- rendering ---------------------------------------------------------------

    /**
     * Defaults are rendered as `…`: the docs describe the contract, and
     * printing arbitrary default expressions would need a pretty-printer.
     *
     * @param list<ParamInfo> $params
     */
    private function renderSignature(string $name, array $params, ?string $returnType): string
    {
        $rendered = [];

        foreach ($params as $param) {
            $rendered[] = ($param->type !== null ? $param->type . ' ' : '')
                . ($param->byRef ? '&' : '')
                . ($param->variadic ? '...' : '')
                . '$' . $param->name
                . ($param->hasDefault ? ' = …' : '');
        }

        return $name . '(' . implode(', ', $rendered) . ')'
            . ($returnType !== null ? ': ' . $returnType : '');
    }

    /**
     * The real signature type wins; the docblock only fills the gaps
     * left by untyped legacy parameters.
     *
     * @param  list<ParamInfo> $params
     * @return list<ParamDoc>
     */
    private function mergeParams(array $params, ?ParsedDoc $doc): array
    {
        $result = [];

        foreach ($params as $param) {
            $tag = $doc?->paramByName($param->name);

            $result[] = new ParamDoc(
                name: $param->name,
                type: $param->type ?? $tag?->type,
                variadic: $param->variadic,
                hasDefault: $param->hasDefault,
                description: $tag?->description,
            );
        }

        return $result;
    }
}
