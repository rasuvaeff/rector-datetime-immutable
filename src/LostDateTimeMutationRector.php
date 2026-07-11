<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DateTimeMutatorCatalog;
use Rasuvaeff\RectorDateTimeImmutable\Internal\FactoryCallMap;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\FileNode;
use Rector\PHPStan\ScopeFetcher;
use Rector\Rector\AbstractRector;
use Webmozart\Assert\Assert;

/**
 * Finds lost `DateTimeImmutable` mutations — statement-level
 * `$date->modify('+1 day');` where the returned instance is thrown away and
 * the code silently keeps the old value (the classic bug after a
 * `DateTime` → `DateTimeImmutable` migration).
 *
 * In `fix` mode (default) the statement becomes
 * `$date = $date->modify('+1 day');`. Only simple variables are rewritten —
 * for property fetches, method-call results and other targets an assignment
 * would be wrong or impossible, so they are only surfaced by `report` mode.
 *
 * In `report` mode a `@todo` marker comment is attached instead, so
 * `rector process --dry-run` fails CI and points at every finding while the
 * code itself stays untouched. (PHPStan level 4 reports the same statements —
 * this mode is for pipelines that run Rector without it.)
 *
 * Skipped: calls whose result is used (assignments, chains, conditions,
 * returns), mutable `DateTime` receivers (the in-place mutation works),
 * receivers that cannot be proven `DateTimeImmutable`, and subclasses that
 * override the mutator (the override may legally mutate in place).
 *
 * Exactness sources for fix mode: direct built-in construction, the shared
 * static factories, the procedural `date_create_immutable*()` factories, a
 * `clone` of an exact value, and mutator results on exact locals. A plain
 * alias (`$b = $a;`) deliberately does not count: in the pre-migration
 * mutable program both names shared one mutated object, so a receiver-only
 * assignment could silently diverge from legacy behaviour.
 *
 * ```php
 * // rector.php
 * ->withConfiguredRule(LostDateTimeMutationRector::class, [
 *     LostDateTimeMutationRector::MODE => LostDateTimeMutationRector::MODE_FIX,
 * ])
 * ```
 *
 * @api
 */
final class LostDateTimeMutationRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string MODE = 'mode';
    public const string MODE_FIX = 'fix';
    public const string MODE_REPORT = 'report';

    public const string REPORT_MARKER = '@todo lost DateTimeImmutable mutation: the return value is ignored';

    private const string UNCONDITIONAL_ASSIGNMENT = 'rector_datetime_immutable_unconditional_assignment';

    private string $mode = self::MODE_FIX;

    private readonly DateTimeMutatorCatalog $mutatorCatalog;
    private readonly FactoryCallMap $factoryCallMap;

    /** @var array<string, array<string, true>> */
    private array $exactBuiltInVariables = [];

    /** @var array<string, list<array{start: int, end: int}>> */
    private array $closureRanges = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->mutatorCatalog = new DateTimeMutatorCatalog();
        $this->factoryCallMap = new FactoryCallMap();
    }

    #[\Override]
    public function configure(array $configuration): void
    {
        Assert::allOneOf(array_keys($configuration), [self::MODE]);

        $mode = $configuration[self::MODE] ?? self::MODE_FIX;

        Assert::string($mode);
        Assert::oneOf($mode, [self::MODE_FIX, self::MODE_REPORT]);

        $this->mode = $mode;
    }

    /**
     * @return array<class-string<Node>>
     */
    #[\Override]
    public function getNodeTypes(): array
    {
        return [
            FileNode::class,
            Namespace_::class,
            Function_::class,
            ClassMethod::class,
            Closure::class,
            Assign::class,
            AssignRef::class,
            AssignOp::class,
            Expression::class,
            Foreach_::class,
        ];
    }

    #[\Override]
    public function refactor(Node $node): ?Node
    {
        if (
            $node instanceof FileNode
            || $node instanceof Namespace_
            || $node instanceof Function_
            || $node instanceof ClassMethod
            || $node instanceof Closure
        ) {
            $this->markUnconditionalAssignments($node);

            if ($node instanceof Closure) {
                $this->rememberClosureRange($node);
            }

            return null;
        }

        if ($node instanceof Foreach_) {
            $this->forgetAssignedVariables($node->keyVar, $node);
            $this->forgetAssignedVariables($node->valueVar, $node);

            return null;
        }

        if ($node instanceof Assign || $node instanceof AssignRef || $node instanceof AssignOp) {
            $this->trackAssignment($node);

            return null;
        }

        if (!$node instanceof Expression) {
            return null;
        }

        $call = $node->expr;

        if (!$call instanceof MethodCall || $call->isFirstClassCallable()) {
            return null;
        }

        if (!$call->name instanceof Identifier || !$this->mutatorCatalog->isMutator($call->name->toString())) {
            return null;
        }

        if (!$this->isLostImmutableMutation($call)) {
            return null;
        }

        if ($this->mode === self::MODE_REPORT) {
            return $this->markStatement($node);
        }

        if (
            !$call->var instanceof Variable
            || !\is_string($call->var->name)
            || $call->var->name === 'this'
        ) {
            return null;
        }

        $assignment = new Assign(new Variable($call->var->name), $call);
        $assignment->setAttribute(self::UNCONDITIONAL_ASSIGNMENT, true);
        $node->expr = $assignment;

        return $node;
    }

    /**
     * Whether the receiver is provably `DateTimeImmutable` AND the called
     * mutator is the native one: a subclass overriding it may legally mutate
     * in place, and on plain `DateTime` the in-place mutation works as
     * written.
     */
    private function isLostImmutableMutation(MethodCall $methodCall): bool
    {
        if (!$methodCall->name instanceof Identifier) {
            return false;
        }

        $methodName = $methodCall->name->toString();
        $scope = ScopeFetcher::fetch($methodCall);
        $receiverType = $scope->getType($methodCall->var);
        $classNames = array_values(array_unique($receiverType->getObjectClassNames()));

        if ($classNames === []) {
            return false;
        }

        foreach ($classNames as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                return false;
            }

            $classReflection = $this->reflectionProvider->getClass($className);

            if (
                !$classReflection->is(\DateTimeImmutable::class)
                || !$classReflection->hasNativeMethod($methodName)
            ) {
                return false;
            }

            $reflection = $classReflection->getNativeMethod($methodName);

            if ($reflection->getDeclaringClass()->getName() !== \DateTimeImmutable::class) {
                return false;
            }

            if (
                $this->mode === self::MODE_FIX
                && !$classReflection->isFinal()
                && !$this->isExactBuiltInLocal($methodCall, $classReflection)
            ) {
                return false;
            }
        }

        return true;
    }

    private function isExactBuiltInLocal(
        MethodCall $methodCall,
        ClassReflection $classReflection,
    ): bool {
        if ($classReflection->getName() !== \DateTimeImmutable::class) {
            return false;
        }

        if (
            !$methodCall->var instanceof Variable
            || !\is_string($methodCall->var->name)
            || $methodCall->var->name === 'this'
        ) {
            return false;
        }

        return isset(
            $this->exactBuiltInVariables[$this->scopeKey($methodCall)][$methodCall->var->name],
        );
    }

    private function markUnconditionalAssignments(
        FileNode|Namespace_|Function_|ClassMethod|Closure $scope,
    ): void {
        foreach ($scope->stmts ?? [] as $statement) {
            if ($statement instanceof Expression && $statement->expr instanceof Assign) {
                $statement->expr->setAttribute(self::UNCONDITIONAL_ASSIGNMENT, true);
            }
        }
    }

    private function trackAssignment(Assign|AssignRef|AssignOp $assignment): void
    {
        if ($assignment instanceof Assign) {
            if (!$assignment->var instanceof Variable || !\is_string($assignment->var->name)) {
                $this->forgetAssignedVariables($assignment->var, $assignment);

                return;
            }

            $scopeKey = $this->scopeKey($assignment);
            $variableName = $assignment->var->name;
            $wasExact = isset($this->exactBuiltInVariables[$scopeKey][$variableName]);
            $assignedExpressionIsExact = $this->isExactBuiltInExpression($assignment->expr, $scopeKey);
            unset($this->exactBuiltInVariables[$scopeKey][$variableName]);

            if (
                $assignedExpressionIsExact
                && ($wasExact || $assignment->getAttribute(self::UNCONDITIONAL_ASSIGNMENT) === true)
            ) {
                $this->exactBuiltInVariables[$scopeKey][$variableName] = true;
            }

            return;
        }

        if ($assignment instanceof AssignRef) {
            $this->forgetAssignedVariables($assignment->var, $assignment);
            $this->forgetAssignedVariables($assignment->expr, $assignment);

            return;
        }

        $this->forgetAssignedVariables($assignment->var, $assignment);
    }

    private function isExactBuiltInExpression(Expr $expr, string $scopeKey): bool
    {
        if (
            $expr instanceof New_
            && $expr->class instanceof \PhpParser\Node\Name
            && $this->isName($expr->class, \DateTimeImmutable::class)
        ) {
            return true;
        }

        if (
            $expr instanceof StaticCall
            && $expr->class instanceof \PhpParser\Node\Name
            && $this->isName($expr->class, \DateTimeImmutable::class)
            && $expr->name instanceof Identifier
        ) {
            return \in_array(
                strtolower($expr->name->toString()),
                ['createfromformat', 'createfrominterface', 'createfrommutable', 'createfromtimestamp'],
                true,
            );
        }

        if ($expr instanceof FuncCall && $expr->name instanceof \PhpParser\Node\Name) {
            $resolvedName = $this->getName($expr);

            return $resolvedName !== null && $this->factoryCallMap->isProceduralImmutableFactory($resolvedName);
        }

        // `clone $exact` yields a fresh exact instance with the same
        // already-separated semantics the mutable program had. A plain alias
        // (`$b = $a;`) deliberately does NOT establish exactness: pre-migration
        // both names shared one mutated object, so a receiver-only auto-fix
        // could silently diverge — the clone source is the only place a bare
        // exact variable counts.
        if ($expr instanceof Clone_) {
            $source = $expr->expr;

            if (
                $source instanceof Variable
                && \is_string($source->name)
                && isset($this->exactBuiltInVariables[$scopeKey][$source->name])
            ) {
                return true;
            }

            return $this->isExactBuiltInExpression($source, $scopeKey);
        }

        return $expr instanceof MethodCall
            && $expr->var instanceof Variable
            && \is_string($expr->var->name)
            && isset($this->exactBuiltInVariables[$scopeKey][$expr->var->name])
            && $expr->name instanceof Identifier
            && $this->mutatorCatalog->isMutator($expr->name->toString());
    }

    private function forgetAssignedVariables(?Expr $expr, Node $context): void
    {
        if (!$expr instanceof Expr) {
            return;
        }

        $scopeKey = $this->scopeKey($context);

        if ($expr instanceof Variable && \is_string($expr->name)) {
            unset($this->exactBuiltInVariables[$scopeKey][$expr->name]);

            return;
        }

        if (!$expr instanceof Array_) {
            return;
        }

        foreach ($expr->items as $item) {
            if ($item !== null) {
                $this->forgetAssignedVariables($item->value, $context);
            }
        }
    }

    private function scopeKey(Node $node): string
    {
        $scope = ScopeFetcher::fetch($node);
        $className = $scope->getClassReflection()?->getName() ?? '';
        $function = $scope->getFunction();
        $functionKey = $function instanceof \PHPStan\Reflection\Php\PhpFunctionFromParserNodeReflection
            ? $function::class . ':' . $function->getName() . ':' . spl_object_id($function)
            : 'global';

        $closureKey = '';
        $position = $node->getStartFilePos();

        foreach ($this->closureRanges[$scope->getFile()] ?? [] as $range) {
            if ($position >= $range['start'] && $position <= $range['end']) {
                $closureKey = '|closure:' . $range['start'];
            }
        }

        return $scope->getFile() . '|' . $className . '|' . $functionKey . $closureKey;
    }

    private function rememberClosureRange(Closure $closure): void
    {
        $scope = ScopeFetcher::fetch($closure);
        $this->closureRanges[$scope->getFile()][] = [
            'start' => $closure->getStartFilePos(),
            'end' => $closure->getEndFilePos(),
        ];
    }

    private function markStatement(Expression $expression): ?Expression
    {
        $comments = $expression->getComments();

        foreach ($comments as $comment) {
            if (str_contains($comment->getText(), self::REPORT_MARKER)) {
                return null;
            }
        }

        $comments[] = new Comment('// ' . self::REPORT_MARKER);
        $expression->setAttribute(AttributeKey::COMMENTS, $comments);

        return $expression;
    }
}
