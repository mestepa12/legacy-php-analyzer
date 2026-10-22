<?php

declare(strict_types=1);

namespace LegacyAnalyzer\Parser;

use LegacyAnalyzer\Model\ClassLikeInfo;
use LegacyAnalyzer\Model\ClassLikeKind;
use LegacyAnalyzer\Model\FunctionInfo;
use LegacyAnalyzer\Model\MethodInfo;
use LegacyAnalyzer\Model\ParamInfo;
use LegacyAnalyzer\Model\TraitAliasInfo;
use LegacyAnalyzer\Model\TraitPrecedenceInfo;
use LegacyAnalyzer\Model\Visibility;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * First AST pass: records WHAT EXISTS in a file (declared symbols),
 * without following any references. Must run after NameResolver in the
 * same traverser so that `namespacedName` attributes are available.
 *
 * Symbols are built in leaveNode, not enterNode: NameResolver resolves
 * names as it ENTERS each node, so when we enter a class its children
 * (trait uses, parameter/return type hints) are still unresolved. By
 * the time we LEAVE the class, every nested name is a FQCN.
 *
 * Anonymous classes (`new class { ... }`) are skipped: they have no
 * referenceable name, so they can never appear in the dependency graph
 * as a target nor be dead code on their own.
 */
final class SymbolCollector extends NodeVisitorAbstract
{
    /** @var list<ClassLikeInfo> */
    private array $classLikes = [];

    /** @var list<FunctionInfo> */
    private array $functions = [];

    public function __construct(private readonly string $file)
    {
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike && $node->name !== null) {
            $this->classLikes[] = $this->buildClassLike($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->functions[] = $this->buildFunction($node);
        }

        return null;
    }

    /** @return list<ClassLikeInfo> */
    public function classLikes(): array
    {
        return $this->classLikes;
    }

    /** @return list<FunctionInfo> */
    public function functions(): array
    {
        return $this->functions;
    }

    // Flattens the four class-like node types into one ClassLikeInfo
    // shape so later phases never branch on AST node classes.
    private function buildClassLike(Stmt\ClassLike $node): ClassLikeInfo
    {
        [$kind, $isAbstract, $isFinal, $extends, $implements] = match (true) {
            $node instanceof Stmt\Class_ => [
                ClassLikeKind::ClassType,
                $node->isAbstract(),
                $node->isFinal(),
                $node->extends !== null ? [$node->extends->toString()] : [],
                array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements),
            ],
            $node instanceof Stmt\Interface_ => [
                ClassLikeKind::InterfaceType,
                false,
                false,
                array_map(static fn (Node\Name $n): string => $n->toString(), $node->extends),
                [],
            ],
            $node instanceof Stmt\Trait_ => [ClassLikeKind::TraitType, false, false, [], []],
            $node instanceof Stmt\Enum_ => [
                ClassLikeKind::EnumType,
                false,
                true,
                [],
                array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements),
            ],
        };

        $traits = [];
        $aliases = [];
        $precedences = [];

        foreach ($node->getTraitUses() as $traitUse) {
            foreach ($traitUse->traits as $traitName) {
                $traits[] = $traitName->toString();
            }

            foreach ($traitUse->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    $aliases[] = new TraitAliasInfo(
                        trait: $adaptation->trait?->toString(),
                        method: $adaptation->method->toString(),
                        alias: $adaptation->newName?->toString(),
                        newVisibility: self::visibilityFromModifier($adaptation->newModifier),
                    );
                } elseif ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $precedences[] = new TraitPrecedenceInfo(
                        trait: $adaptation->trait->toString(),
                        method: $adaptation->method->toString(),
                        insteadOf: array_map(
                            static fn (Node\Name $n): string => $n->toString(),
                            $adaptation->insteadof,
                        ),
                    );
                }
            }
        }

        $isInterface = $kind === ClassLikeKind::InterfaceType;
        $methods = array_map(
            fn (Stmt\ClassMethod $m): MethodInfo => $this->buildMethod($m, $isInterface),
            $node->getMethods(),
        );

        /** @var Node\Name $namespacedName set by NameResolver on named class-likes */
        $namespacedName = $node->namespacedName;

        return new ClassLikeInfo(
            fqcn: $namespacedName->toString(),
            shortName: $node->name->toString(),
            kind: $kind,
            isAbstract: $isAbstract,
            isFinal: $isFinal,
            extends: $extends,
            implements: $implements,
            traits: $traits,
            traitAliases: $aliases,
            traitPrecedences: $precedences,
            methods: $methods,
            docComment: $node->getDocComment()?->getText(),
            file: $this->file,
            startLine: $node->getStartLine(),
        );
    }

    private function buildMethod(Stmt\ClassMethod $node, bool $isInterface): MethodInfo
    {
        return new MethodInfo(
            name: $node->name->toString(),
            visibility: match (true) {
                $node->isPrivate() => Visibility::PrivateVis,
                $node->isProtected() => Visibility::ProtectedVis,
                default => Visibility::PublicVis,
            },
            isStatic: $node->isStatic(),
            // Interface methods carry no `abstract` flag but are contracts too.
            isAbstract: $node->isAbstract() || $isInterface,
            isFinal: $node->isFinal(),
            hasBody: $node->stmts !== null,
            params: $this->buildParams($node->params),
            returnType: TypeStringifier::stringify($node->returnType),
            docComment: $node->getDocComment()?->getText(),
            startLine: $node->getStartLine(),
            isGenerator: self::isGenerator($node->stmts),
        );
    }

    private function buildFunction(Stmt\Function_ $node): FunctionInfo
    {
        return new FunctionInfo(
            fqn: $node->namespacedName->toString(),
            shortName: $node->name->toString(),
            params: $this->buildParams($node->params),
            returnType: TypeStringifier::stringify($node->returnType),
            docComment: $node->getDocComment()?->getText(),
            file: $this->file,
            startLine: $node->getStartLine(),
            isGenerator: self::isGenerator($node->stmts),
        );
    }

    /**
     * A function body containing `yield` / `yield from` is a generator. Yields
     * inside nested closures, arrow functions or anonymous classes belong to
     * those, so the search does not descend into them.
     *
     * @param array<Node>|null $stmts
     */
    private static function isGenerator(?array $stmts): bool
    {
        $stack = $stmts ?? [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) {
                return true;
            }
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction
                || $node instanceof Stmt\Function_ || $node instanceof Stmt\Class_) {
                continue;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $sub = $node->$name;
                foreach (is_array($sub) ? $sub : [$sub] as $child) {
                    if ($child instanceof Node) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  array<Node\Param> $params
     * @return list<ParamInfo>
     */
    private function buildParams(array $params): array
    {
        $result = [];

        foreach ($params as $param) {
            /** @var Node\Expr\Variable $var */
            $var = $param->var;
            $result[] = new ParamInfo(
                name: is_string($var->name) ? $var->name : '{dynamic}',
                type: TypeStringifier::stringify($param->type),
                hasDefault: $param->default !== null,
                byRef: $param->byRef,
                variadic: $param->variadic,
            );
        }

        return $result;
    }

    // null = the `as` alias keeps the method's original visibility.
    private static function visibilityFromModifier(?int $modifier): ?Visibility
    {
        return match ($modifier) {
            Modifiers::PUBLIC => Visibility::PublicVis,
            Modifiers::PROTECTED => Visibility::ProtectedVis,
            Modifiers::PRIVATE => Visibility::PrivateVis,
            default => null,
        };
    }
}
