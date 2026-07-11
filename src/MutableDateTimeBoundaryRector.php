<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeFinder;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DateTimeTypeRewriter;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DirectValueBranches;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DoctrineColumnDetector;
use Rasuvaeff\RectorDateTimeImmutable\Internal\MutableDateTimeBoundaryAnalyzer;
use Rasuvaeff\RectorDateTimeImmutable\Internal\MutableDateTimeMarker;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PHPStan\ScopeFetcher;
use Rector\Rector\AbstractRector;
use Webmozart\Assert\Assert;

/**
 * Reports calls whose parameter requires mutable `DateTime` and would reject a
 * migrated `DateTimeImmutable` value, plus method parameters that feed a
 * property the migration preserves as mutable (ORM columns,
 * `@mutable-datetime` declarations, inherited properties): migrating such a
 * parameter guarantees a `TypeError` on the property assignment, so the case
 * needs a human decision first — mark the method `@mutable-datetime` or
 * migrate the storage contract. Run as a dry-run preflight before the
 * migration rules.
 *
 * A statement carrying the `@mutable-datetime-boundary` comment is an
 * acknowledged boundary call: the human reviewed the mutable flow, so the
 * call is no longer reported. `MODE_ACKNOWLEDGE` writes that comment for
 * every boundary call finding — parameters feeding a preserved mutable
 * property are never acknowledged this way because silencing them would let
 * the migration break the property assignment; they keep their own
 * resolution (mark the method `@mutable-datetime` or migrate the storage).
 *
 * @api
 */
final class MutableDateTimeBoundaryRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string REPORT_MARKER = '@todo mutable DateTime boundary';
    public const string ACKNOWLEDGE_MARKER = '@mutable-datetime-boundary';
    public const string MODE = 'mode';
    public const string MODE_REPORT = 'report';
    public const string MODE_ACKNOWLEDGE = 'acknowledge';
    public const string DOCTRINE_COLUMNS = 'doctrine_columns';

    private string $mode = self::MODE_REPORT;
    private bool $doctrineColumns = false;

    public function __construct(
        private readonly MutableDateTimeBoundaryAnalyzer $boundaryAnalyzer,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
        $this->nodeFinder = new NodeFinder();
        $this->typeRewriter = new DateTimeTypeRewriter();
        $this->doctrineColumnDetector = new DoctrineColumnDetector();
        $this->mutableDateTimeMarker = new MutableDateTimeMarker();
        $this->directValueBranches = new DirectValueBranches();
    }

    private readonly NodeFinder $nodeFinder;
    private readonly DateTimeTypeRewriter $typeRewriter;
    private readonly DoctrineColumnDetector $doctrineColumnDetector;
    private readonly MutableDateTimeMarker $mutableDateTimeMarker;
    private readonly DirectValueBranches $directValueBranches;

    /** @var array<string, list<string>> */
    private array $preservedFeedFindings = [];

    #[\Override]
    public function configure(array $configuration): void
    {
        Assert::allOneOf(array_keys($configuration), [self::MODE, self::DOCTRINE_COLUMNS]);

        /** @var mixed $mode */
        $mode = $configuration[self::MODE] ?? self::MODE_REPORT;
        Assert::string($mode);
        Assert::oneOf($mode, [self::MODE_REPORT, self::MODE_ACKNOWLEDGE]);
        $this->mode = $mode;

        /** @var mixed $doctrineColumns */
        $doctrineColumns = $configuration[self::DOCTRINE_COLUMNS] ?? false;
        Assert::boolean($doctrineColumns);
        $this->doctrineColumns = $doctrineColumns;
    }

    /**
     * @return array<class-string<Node>>
     */
    #[\Override]
    public function getNodeTypes(): array
    {
        return [
            Class_::class,
            Expression::class,
            Return_::class,
            Property::class,
            Param::class,
        ];
    }

    #[\Override]
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Class_) {
            $this->collectPreservedPropertyFeeds($node);

            return null;
        }

        $messages = [];

        if (!$this->carriesAcknowledgeMarker($node)) {
            $calls = $this->nodeFinder->find(
                [$node],
                static fn(Node $candidate): bool => $candidate instanceof CallLike,
            );

            foreach ($calls as $call) {
                if (!$call instanceof CallLike) {
                    continue;
                }

                foreach ($this->boundaryAnalyzer->unsafeArguments($call) as $finding) {
                    $messages[] = sprintf('parameter %s requires DateTime', $finding['parameter']);
                }
            }
        }

        // Feed findings are never written by acknowledge mode: silencing them
        // would let the migration break the preserved property assignment.
        if ($this->mode === self::MODE_REPORT) {
            foreach ($this->preservedFeedFindings[$this->findingKey($node)] ?? [] as $message) {
                $messages[] = $message;
            }
        }

        if ($messages === []) {
            return null;
        }

        $markerPrefix = $this->mode === self::MODE_ACKNOWLEDGE
            ? self::ACKNOWLEDGE_MARKER
            : self::REPORT_MARKER;
        $comments = $node->getComments();

        foreach ($messages as $message) {
            $marker = $markerPrefix . ': ' . $message;

            foreach ($comments as $comment) {
                if (str_contains($comment->getText(), $marker)) {
                    continue 2;
                }
            }

            $comments[] = new Comment('// ' . $marker);
        }

        $node->setAttribute(AttributeKey::COMMENTS, $comments);

        return $node;
    }

    private function carriesAcknowledgeMarker(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), self::ACKNOWLEDGE_MARKER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classes whose types the migration never touches (anonymous, abstract,
     * marked, `DateTime` subclasses) keep every parameter mutable, so their
     * property assignments cannot break.
     */
    private function collectPreservedPropertyFeeds(Class_ $class): void
    {
        if (
            $class->isAnonymous()
            || $class->isAbstract()
            || $this->mutableDateTimeMarker->isMarked($class)
            || ($class->extends instanceof Name && $this->isName($class->extends, 'DateTime'))
        ) {
            return;
        }

        $classReflection = $this->resolveClassReflection($class);

        if (!$classReflection instanceof ClassReflection) {
            return;
        }

        $preserved = $this->preservedMutablePropertyNames($class, $classReflection);

        if ($preserved === []) {
            return;
        }

        foreach ($class->getMethods() as $method) {
            if (
                $this->mutableDateTimeMarker->isMarked($method)
                || $this->ancestorDeclaresMethod($classReflection, $method->name->toString())
            ) {
                continue;
            }

            foreach ($method->params as $param) {
                if (
                    $param->type === null
                    || $this->paramStaysMutable($param, $classReflection)
                    || !$this->wouldMigrateType($param->type)
                    || !$param->var instanceof Variable
                    || !\is_string($param->var->name)
                ) {
                    continue;
                }

                $this->collectParamFeeds($method, $param->var->name, $preserved);
            }
        }
    }

    /**
     * @param array<string, true> $preserved
     */
    private function collectParamFeeds(ClassMethod $method, string $paramName, array $preserved): void
    {
        foreach ($this->nodeFinder->findInstanceOf($method->stmts ?? [], Expression::class) as $statement) {
            if (!$statement->expr instanceof Assign) {
                continue;
            }

            $propertyName = $this->ownPropertyName($statement->expr->var);

            if ($propertyName === null || !isset($preserved[$propertyName])) {
                continue;
            }

            if (!$this->branchesReferenceVariable($statement->expr->expr, $paramName)) {
                continue;
            }

            $this->preservedFeedFindings[$this->findingKey($statement)][] = sprintf(
                'parameter $%s feeds mutable property $%s',
                $paramName,
                $propertyName,
            );
        }
    }

    /**
     * @return array<string, true>
     */
    private function preservedMutablePropertyNames(Class_ $class, ClassReflection $classReflection): array
    {
        $preserved = [];

        foreach ($class->getProperties() as $property) {
            if ($property->type === null || !$this->wouldMigrateType($property->type)) {
                continue;
            }

            $preservedColumn = $this->doctrineColumnDetector->isMappedColumn($property)
                && (!$this->doctrineColumns || !$this->doctrineColumnDetector->isCoMigratableColumn($property));

            if (
                $this->mutableDateTimeMarker->isMarked($property)
                || $preservedColumn
                || $this->ancestorDeclaresPropertyFromNode($classReflection, $property)
            ) {
                foreach ($property->props as $propertyItem) {
                    $preserved[$propertyItem->name->toString()] = true;
                }
            }
        }

        $constructor = $class->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return $preserved;
        }

        foreach ($constructor->params as $param) {
            if (
                $param->flags === 0
                || $param->type === null
                || !$this->wouldMigrateType($param->type)
                || !$param->var instanceof Variable
                || !\is_string($param->var->name)
            ) {
                continue;
            }

            if ($this->paramStaysMutable($param, $classReflection)) {
                $preserved[$param->var->name] = true;
            }
        }

        return $preserved;
    }

    private function paramStaysMutable(Param $param, ClassReflection $classReflection): bool
    {
        if ($this->mutableDateTimeMarker->isMarked($param)) {
            return true;
        }

        if ($param->flags === 0) {
            return false;
        }

        $preservedColumn = $this->doctrineColumnDetector->isMappedColumn($param)
            && (!$this->doctrineColumns || !$this->doctrineColumnDetector->isCoMigratableColumn($param));

        return $preservedColumn
            || (
                $param->var instanceof Variable
                && \is_string($param->var->name)
                && $this->ancestorDeclaresProperty($classReflection, $param->var->name)
            );
    }

    private function wouldMigrateType(Identifier|Name|ComplexType $type): bool
    {
        return $this->typeRewriter->wouldRewrite(
            $type,
            fn(Name $name): bool => $this->isName($name, 'DateTime'),
            fn(Name $name): bool => $this->isName($name, 'DateTimeImmutable'),
        );
    }

    private function ownPropertyName(Expr $expr): ?string
    {
        if (
            $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $expr->name->toString();
        }

        if (
            $expr instanceof StaticPropertyFetch
            && $expr->class instanceof Name
            && $expr->name instanceof VarLikeIdentifier
            && \in_array(strtolower($expr->class->toString()), ['self', 'static'], true)
        ) {
            return $expr->name->toString();
        }

        return null;
    }

    private function branchesReferenceVariable(Expr $expr, string $variableName): bool
    {
        foreach ($this->directValueBranches->branches($expr) as $branch) {
            if ($branch instanceof Variable && $branch->name === $variableName) {
                return true;
            }
        }

        return false;
    }

    private function findingKey(Node $node): string
    {
        return ScopeFetcher::fetch($node)->getFile() . ':' . $node->getStartFilePos();
    }

    private function resolveClassReflection(Class_ $class): ?ClassReflection
    {
        $namespacedName = $class->namespacedName;

        if (!$namespacedName instanceof Name) {
            return null;
        }

        $className = $namespacedName->toString();

        if (!$this->reflectionProvider->hasClass($className)) {
            return null;
        }

        return $this->reflectionProvider->getClass($className);
    }

    private function ancestorDeclaresMethod(ClassReflection $classReflection, string $methodName): bool
    {
        foreach ([...$classReflection->getParents(), ...$classReflection->getInterfaces()] as $ancestor) {
            if ($ancestor->hasNativeMethod($methodName)) {
                return true;
            }
        }

        foreach ($classReflection->getTraits(true) as $trait) {
            if ($trait->hasNativeMethod($methodName) && $trait->getNativeMethod($methodName)->isAbstract()) {
                return true;
            }
        }

        return false;
    }

    private function ancestorDeclaresPropertyFromNode(
        ClassReflection $classReflection,
        Property $property,
    ): bool {
        foreach ($property->props as $propertyItem) {
            if ($this->ancestorDeclaresProperty($classReflection, $propertyItem->name->toString())) {
                return true;
            }
        }

        return false;
    }

    private function ancestorDeclaresProperty(ClassReflection $classReflection, string $propertyName): bool
    {
        $ancestors = [
            ...$classReflection->getParents(),
            ...$classReflection->getInterfaces(),
            ...array_values($classReflection->getTraits(true)),
        ];

        foreach ($ancestors as $ancestor) {
            if ($ancestor->hasNativeProperty($propertyName)) {
                return true;
            }
        }

        return false;
    }
}
