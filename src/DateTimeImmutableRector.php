<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\Node\UseItem;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DateTimeTypeRewriter;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DirectValueBranches;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DocblockTypeRewriter;
use Rasuvaeff\RectorDateTimeImmutable\Internal\DoctrineColumnDetector;
use Rasuvaeff\RectorDateTimeImmutable\Internal\FactoryCallMap;
use Rasuvaeff\RectorDateTimeImmutable\Internal\MutableDateTimeBoundaryAnalyzer;
use Rasuvaeff\RectorDateTimeImmutable\Internal\MutableDateTimeMarker;
use Rasuvaeff\RectorDateTimeImmutable\Internal\UseImportUsageScanner;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\FileNode;
use Rector\PHPStan\ScopeFetcher;
use Rector\Rector\AbstractRector;
use Webmozart\Assert\Assert;

/**
 * Migrates mutable `DateTime` construction to `DateTimeImmutable`:
 * `new \DateTime(...)`, `\DateTime::createFromFormat(...)`,
 * `\DateTime::createFromInterface(...)`, `\DateTime::createFromTimestamp(...)`,
 * `date_create()` and `date_create_from_format()`. Concrete signatures and
 * properties migrate with construction by default; each category can be
 * disabled for a staged migration.
 *
 * Left untouched: subclasses of `DateTime` (rewriting `extends` breaks
 * downstream mutation — opt-in via ALLOW_SUBCLASS), signatures declared by an
 * ancestor class or interface (LSP: implementations must keep the inherited
 * type), interface/trait/abstract declarations, ORM-mapped columns
 * (`#[Column]` / `@ORM\Column` — the ORM decides the concrete class),
 * anything marked `@mutable-datetime`, and return types whose `return`
 * directly yields such a preserved mutable property (the runtime value stays
 * `DateTime`; a migrated declaration would be a guaranteed `TypeError`).
 *
 * Migrated construction turns downstream `$d->modify(...)` statements into
 * lost mutations — run the companion `LostDateTimeMutationRector` in the same
 * configuration to repair them.
 *
 * ```php
 * // rector.php
 * ->withConfiguredRule(DateTimeImmutableRector::class, [
 *     DateTimeImmutableRector::CONSTRUCTORS => true,    // default
 *     DateTimeImmutableRector::TYPEHINTS => true,       // default: signatures
 *     DateTimeImmutableRector::PROPERTIES => true,        // default: properties + promoted params
 *     DateTimeImmutableRector::ALLOW_SUBCLASS => false, // opt-in: rewrite `extends \DateTime`
 * ])
 * ```
 *
 * @api
 */
final class DateTimeImmutableRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string CONSTRUCTORS = 'constructors';
    public const string TYPEHINTS = 'typehints';
    public const string PROPERTIES = 'properties';
    public const string ALLOW_SUBCLASS = 'allow_subclass';
    public const string DOCTRINE_COLUMNS = 'doctrine_columns';

    private const array KNOWN_OPTIONS = [
        self::CONSTRUCTORS,
        self::TYPEHINTS,
        self::PROPERTIES,
        self::ALLOW_SUBCLASS,
        self::DOCTRINE_COLUMNS,
    ];

    private bool $constructors = true;
    private bool $typehints = true;
    private bool $properties = true;
    private bool $allowSubclass = false;
    private bool $doctrineColumns = false;

    private readonly FactoryCallMap $factoryCallMap;
    private readonly DateTimeTypeRewriter $typeRewriter;
    private readonly DoctrineColumnDetector $doctrineColumnDetector;
    private readonly MutableDateTimeMarker $mutableDateTimeMarker;
    private readonly DirectValueBranches $directValueBranches;
    private readonly NodeFinder $nodeFinder;
    private readonly UseImportUsageScanner $useImportUsageScanner;
    private readonly DocblockTypeRewriter $docblockTypeRewriter;

    /** @var array<Stmt> */
    private array $currentFileStmts = [];

    /** @var array<string, list<array{start: int, end: int}>> */
    private array $constructionSkipRanges = [];

    /** @var array<string, true> */
    private array $mutableBoundaryStorages = [];

    /** @var array<string, true> */
    private array $mutableBoundaryReturnScopes = [];

    /** @var array<string, true> */
    private array $preservedMutablePropertyKeys = [];

    /** @var array<string, list<array{start: int, end: int}>> */
    private array $lexicalRanges = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly MutableDateTimeBoundaryAnalyzer $boundaryAnalyzer,
    ) {
        $this->factoryCallMap = new FactoryCallMap();
        $this->typeRewriter = new DateTimeTypeRewriter();
        $this->doctrineColumnDetector = new DoctrineColumnDetector();
        $this->mutableDateTimeMarker = new MutableDateTimeMarker();
        $this->directValueBranches = new DirectValueBranches();
        $this->nodeFinder = new NodeFinder();
        $this->useImportUsageScanner = new UseImportUsageScanner();
        $this->docblockTypeRewriter = new DocblockTypeRewriter();
    }

    #[\Override]
    public function configure(array $configuration): void
    {
        Assert::allOneOf(array_keys($configuration), self::KNOWN_OPTIONS);
        Assert::allBoolean($configuration);

        $this->constructors = $configuration[self::CONSTRUCTORS] ?? true;
        $this->typehints = $configuration[self::TYPEHINTS] ?? true;
        $this->properties = $configuration[self::PROPERTIES] ?? true;
        $this->allowSubclass = $configuration[self::ALLOW_SUBCLASS] ?? false;
        $this->doctrineColumns = $configuration[self::DOCTRINE_COLUMNS] ?? false;
    }

    /**
     * @return array<class-string<Node>>
     */
    #[\Override]
    public function getNodeTypes(): array
    {
        return [
            FileNode::class,
            New_::class,
            StaticCall::class,
            FuncCall::class,
            Class_::class,
            Trait_::class,
            Enum_::class,
            Function_::class,
            Closure::class,
            ArrowFunction::class,
            Use_::class,
        ];
    }

    /**
     * @return Node|NodeVisitor::REMOVE_NODE|null
     */
    #[\Override]
    public function refactor(Node $node): Node|int|null
    {
        if ($node instanceof FileNode) {
            $this->currentFileStmts = $node->stmts;
            $this->protectMutableBoundariesWithin($node);

            return null;
        }

        if ($node instanceof Use_) {
            return $this->refactorUse($node);
        }

        if ($node instanceof New_) {
            return $this->constructors ? $this->refactorNew($node) : null;
        }

        if ($node instanceof StaticCall) {
            return $this->constructors ? $this->refactorStaticCall($node) : null;
        }

        if ($node instanceof FuncCall) {
            return $this->constructors ? $this->refactorFuncCall($node) : null;
        }

        if ($node instanceof Class_) {
            return $this->refactorClass($node);
        }

        if ($node instanceof Trait_) {
            $this->skipConstructionWithin($node);

            return null;
        }

        if ($node instanceof Enum_) {
            return $this->refactorEnum($node);
        }

        if ($node instanceof Function_ || $node instanceof Closure || $node instanceof ArrowFunction) {
            return $this->refactorFunctionLike($node);
        }

        return null;
    }

    private function refactorNew(New_ $new): ?New_
    {
        if (
            $this->isConstructionSkipped($new)
            || !$new->class instanceof Name
            || !$this->isName($new->class, 'DateTime')
        ) {
            return null;
        }

        $new->class = new FullyQualified('DateTimeImmutable');

        return $new;
    }

    private function refactorStaticCall(StaticCall $staticCall): ?StaticCall
    {
        if (
            $this->isConstructionSkipped($staticCall)
            || !$staticCall->class instanceof Name
            || !$this->isName($staticCall->class, 'DateTime')
        ) {
            return null;
        }

        if (
            !$staticCall->name instanceof Identifier
            || !$this->factoryCallMap->isSharedStaticFactory($staticCall->name->toString())
        ) {
            return null;
        }

        $staticCall->class = new FullyQualified('DateTimeImmutable');

        return $staticCall;
    }

    private function refactorFuncCall(FuncCall $funcCall): ?FuncCall
    {
        if ($this->isConstructionSkipped($funcCall) || !$funcCall->name instanceof Name) {
            return null;
        }

        $resolvedName = $this->getName($funcCall);

        if ($resolvedName === null) {
            return null;
        }

        $immutableEquivalent = $this->factoryCallMap->immutableEquivalent($resolvedName);

        if ($immutableEquivalent === null) {
            return null;
        }

        $funcCall->name = new FullyQualified($immutableEquivalent);

        return $funcCall;
    }

    /**
     * A `use DateTime;` import whose alias no longer appears anywhere in the
     * file — migrated references become `\DateTimeImmutable` — is removed so
     * the migration does not leave dead imports behind. Uses visited before
     * the migrating nodes of the same pass keep their import; the next pass
     * removes it, matching the documented "run until no changes" flow.
     *
     * @return Use_|NodeVisitor::REMOVE_NODE|null
     */
    private function refactorUse(Use_ $use): Use_|int|null
    {
        if (
            (!$this->constructors && !$this->typehints && !$this->properties)
            || $use->type !== Use_::TYPE_NORMAL
        ) {
            return null;
        }

        $kept = [];
        $changed = false;

        foreach ($use->uses as $item) {
            if ($this->isRemovableDateTimeImport($item)) {
                $changed = true;

                continue;
            }

            $kept[] = $item;
        }

        if (!$changed) {
            return null;
        }

        if ($kept === []) {
            return NodeVisitor::REMOVE_NODE;
        }

        $use->uses = $kept;

        return $use;
    }

    private function isRemovableDateTimeImport(UseItem $item): bool
    {
        if (\count($item->name->getParts()) !== 1 || strcasecmp($item->name->toString(), 'DateTime') !== 0) {
            return false;
        }

        return !$this->useImportUsageScanner->isUsed($this->currentFileStmts, $item->getAlias()->toString());
    }

    private function refactorClass(Class_ $class): ?Class_
    {
        $changed = false;

        if ($class->isAnonymous() || $this->mutableDateTimeMarker->isMarked($class)) {
            $this->skipConstructionWithin($class);

            return null;
        }

        if ($class->extends instanceof Name && $this->isName($class->extends, 'DateTime')) {
            if (!$this->allowSubclass) {
                $this->skipConstructionWithin($class);

                return null;
            }

            $class->extends = new FullyQualified('DateTimeImmutable');
            $changed = true;
        }

        if ($class->isAbstract()) {
            $this->skipConstructionWithin($class);
        } elseif ($this->typehints || $this->properties) {
            $changed = $this->migrateClassTypes($class) || $changed;
        }

        return $changed ? $class : null;
    }

    private function refactorEnum(Enum_ $enum): ?Enum_
    {
        if ((!$this->typehints && !$this->properties) || $this->mutableDateTimeMarker->isMarked($enum)) {
            if ($this->mutableDateTimeMarker->isMarked($enum)) {
                $this->skipConstructionWithin($enum);
            }

            return null;
        }

        return $this->migrateClassTypes($enum) ? $enum : null;
    }

    private function refactorFunctionLike(
        Function_|Closure|ArrowFunction $function,
    ): Function_|Closure|ArrowFunction|null {
        if (!$this->typehints || $this->mutableDateTimeMarker->isMarked($function)) {
            if ($this->mutableDateTimeMarker->isMarked($function)) {
                $this->protectMutableSignature($function);
            }

            return null;
        }

        return $this->migrateSignature($function) ? $function : null;
    }

    private function migrateClassTypes(Class_|Enum_ $class): bool
    {
        $classReflection = $this->resolveClassReflection($class);

        if (!$classReflection instanceof ClassReflection) {
            $this->skipConstructionWithin($class);

            return false;
        }

        $changed = false;

        // Properties before methods: preserved-mutable properties must be
        // registered before any accessor signature over them is migrated.
        if ($this->properties) {
            foreach ($class->getProperties() as $property) {
                if ($property->type === null) {
                    continue;
                }

                $coMigratedColumn = $this->doctrineColumns
                    && $this->doctrineColumnDetector->isCoMigratableColumn($property);

                if (
                    $this->isMutableBoundaryProperty($property)
                    || $this->ancestorDeclaresPropertyFromNode($classReflection, $property)
                    || $this->mutableDateTimeMarker->isMarked($property)
                    || ($this->doctrineColumnDetector->isMappedColumn($property) && !$coMigratedColumn)
                ) {
                    $this->protectMutableProperty($class, $property);

                    continue;
                }

                $rewritten = $this->rewrittenType($property->type);

                if ($rewritten !== null) {
                    $property->type = $rewritten;
                    $this->syncPropertyDocblock($property);

                    if ($coMigratedColumn) {
                        $this->doctrineColumnDetector->migrateColumnType($property);
                    }

                    $changed = true;
                }
            }
        }

        foreach ($this->methodsConstructorFirst($class) as $method) {
            if ($this->mutableDateTimeMarker->isMarked($method)) {
                $this->protectMutableSignature($method);

                continue;
            }

            if ($this->ancestorDeclaresMethod($classReflection, $method->name->toString())) {
                $this->protectMutableSignature($method);

                continue;
            }

            $changed = $this->migrateSignature($method, $classReflection) || $changed;
        }

        return $changed;
    }

    /**
     * Preserved promoted properties are registered while the constructor
     * signature migrates, so it must be processed before the accessors.
     *
     * @return list<ClassMethod>
     */
    private function methodsConstructorFirst(Class_|Enum_ $class): array
    {
        $constructor = null;
        $rest = [];

        foreach ($class->getMethods() as $method) {
            if ($constructor === null && strtolower($method->name->toString()) === '__construct') {
                $constructor = $method;

                continue;
            }

            $rest[] = $method;
        }

        return $constructor instanceof ClassMethod ? [$constructor, ...$rest] : $rest;
    }

    private function migrateSignature(
        ClassMethod|Function_|Closure|ArrowFunction $functionLike,
        ?ClassReflection $classReflection = null,
    ): bool {
        $changed = false;
        $syncedParamNames = [];
        $returnRewritten = false;
        $hasExplicitMutableFactory = $this->hasExplicitMutableFactory($functionLike);
        $hasMutableBoundary = isset($this->mutableBoundaryReturnScopes[$this->lexicalScopeKey($functionLike)]);
        $returnsPreservedMutable = $this->returnsPreservedMutableStorage($functionLike);

        if ($hasExplicitMutableFactory || $hasMutableBoundary || $returnsPreservedMutable) {
            $this->protectMutableReturn($functionLike);
        }

        foreach ($functionLike->params as $param) {
            $promoted = $param->flags !== 0;

            if ($promoted ? !$this->properties : !$this->typehints) {
                continue;
            }

            if ($param->type === null) {
                continue;
            }

            if ($this->isMutableBoundaryParameter($param)) {
                $this->protectMutableParameter($param);

                if ($promoted) {
                    $this->recordPreservedPromotedProperty($param);
                }

                continue;
            }

            if ($this->mutableDateTimeMarker->isMarked($param)) {
                $this->protectMutableParameter($param);

                if ($promoted) {
                    $this->recordPreservedPromotedProperty($param);
                }

                continue;
            }

            $coMigratedColumn = false;

            if ($promoted && $this->doctrineColumnDetector->isMappedColumn($param)) {
                $coMigratedColumn = $this->doctrineColumns
                    && $this->doctrineColumnDetector->isCoMigratableColumn($param);

                if (!$coMigratedColumn) {
                    $this->protectMutableParameter($param);
                    $this->recordPreservedPromotedProperty($param);

                    continue;
                }
            }

            if (
                $promoted
                && $classReflection instanceof ClassReflection
                && $param->var instanceof Variable
                && \is_string($param->var->name)
                && $this->ancestorDeclaresProperty($classReflection, $param->var->name)
            ) {
                $this->protectMutableParameter($param);
                $this->recordPreservedPromotedProperty($param);

                continue;
            }

            $rewritten = $this->rewrittenType($param->type);

            if ($rewritten !== null) {
                $param->type = $rewritten;
                $changed = true;

                if ($coMigratedColumn) {
                    $this->doctrineColumnDetector->migrateColumnType($param);
                }

                if ($param->var instanceof Variable && \is_string($param->var->name)) {
                    $syncedParamNames[] = $param->var->name;
                }
            }
        }

        if (
            $this->typehints
            && !$hasExplicitMutableFactory
            && !$hasMutableBoundary
            && !$returnsPreservedMutable
            && $functionLike->returnType instanceof \PhpParser\Node
        ) {
            $rewritten = $this->rewrittenType($functionLike->returnType);

            if ($rewritten !== null) {
                $functionLike->returnType = $rewritten;
                $returnRewritten = true;
                $changed = true;
            }
        }

        if ($syncedParamNames !== [] || $returnRewritten) {
            $this->syncSignatureDocblock($functionLike, $syncedParamNames, $returnRewritten);
        }

        return $changed;
    }

    /**
     * Docblock tags migrate only together with the native type of the same
     * declaration: the tag then provably described the migrated contract. A
     * docblock-only declaration carries no runtime evidence and stays as-is.
     */
    private function syncPropertyDocblock(Property $property): void
    {
        $docComment = $property->getDocComment();

        if (!$docComment instanceof Doc) {
            return;
        }

        $rewritten = $this->docblockTypeRewriter->rewrittenVarTag($docComment->getText());

        if ($rewritten !== null) {
            $property->setDocComment(new Doc($rewritten));
        }
    }

    /**
     * @param list<string> $paramNames
     */
    private function syncSignatureDocblock(
        ClassMethod|Function_|Closure|ArrowFunction $functionLike,
        array $paramNames,
        bool $returnRewritten,
    ): void {
        $docComment = $functionLike->getDocComment();

        if (!$docComment instanceof Doc) {
            return;
        }

        $text = $docComment->getText();
        $changed = false;

        foreach ($paramNames as $paramName) {
            $rewritten = $this->docblockTypeRewriter->rewrittenParamTag($text, $paramName);

            if ($rewritten !== null) {
                $text = $rewritten;
                $changed = true;
            }
        }

        if ($returnRewritten) {
            $rewritten = $this->docblockTypeRewriter->rewrittenReturnTag($text);

            if ($rewritten !== null) {
                $text = $rewritten;
                $changed = true;
            }
        }

        if ($changed) {
            $functionLike->setDocComment(new Doc($text));
        }
    }

    private function protectMutableBoundariesWithin(FileNode $file): void
    {
        foreach ($this->nodeFinder->findInstanceOf($file->stmts, FunctionLike::class) as $functionLike) {
            if ($functionLike instanceof Node) {
                $scope = ScopeFetcher::fetch($functionLike);
                $this->lexicalRanges[$scope->getFile()][] = [
                    'start' => $functionLike->getStartFilePos(),
                    'end' => $functionLike->getEndFilePos(),
                ];
            }
        }

        $calls = $this->nodeFinder->find(
            $file->stmts,
            static fn(Node $node): bool => $node instanceof CallLike,
        );
        $assignments = $this->nodeFinder->findInstanceOf($file->stmts, Assign::class);
        $markedStorages = [];

        foreach ($calls as $call) {
            if (!$call instanceof CallLike) {
                continue;
            }

            $findings = $this->boundaryAnalyzer->unsafeArguments($call);

            if ($findings === []) {
                continue;
            }

            $this->mutableBoundaryReturnScopes[$this->lexicalScopeKey($call)] = true;

            foreach ($findings as $finding) {
                $this->skipConstructionWithin($finding['argument']->value);
                $storageKey = $this->storageKey($finding['argument']->value);

                if ($storageKey !== null) {
                    $markedStorages[$storageKey] = true;
                }
            }
        }

        do {
            $changed = false;

            foreach ($assignments as $assignment) {
                $targetKey = $this->storageKey($assignment->var);
                $sourceKey = $this->storageKey($assignment->expr);

                if ($targetKey === null || $sourceKey === null) {
                    continue;
                }

                if (isset($markedStorages[$targetKey]) && !isset($markedStorages[$sourceKey])) {
                    $markedStorages[$sourceKey] = true;
                    $changed = true;
                }

                if (isset($markedStorages[$sourceKey]) && !isset($markedStorages[$targetKey])) {
                    $markedStorages[$targetKey] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        foreach (array_keys($markedStorages) as $storageKey) {
            $this->mutableBoundaryStorages[$storageKey] = true;
        }

        foreach ($assignments as $assignment) {
            $targetKey = $this->storageKey($assignment->var);

            if ($targetKey !== null && isset($markedStorages[$targetKey])) {
                $this->skipConstructionWithin($assignment->expr);
            }
        }
    }

    private function isMutableBoundaryProperty(Property $property): bool
    {
        foreach ($property->props as $propertyItem) {
            $key = $this->propertyStorageKey($property, $propertyItem->name->toString());

            if ($key !== null && isset($this->mutableBoundaryStorages[$key])) {
                return true;
            }
        }

        return false;
    }

    private function isMutableBoundaryParameter(Param $param): bool
    {
        if (!$param->var instanceof Variable || !\is_string($param->var->name)) {
            return false;
        }

        return isset(
            $this->mutableBoundaryStorages[$this->lexicalScopeKey($param) . '|variable:' . $param->var->name],
        );
    }

    private function storageKey(Expr $expr): ?string
    {
        if ($expr instanceof Variable && \is_string($expr->name) && $expr->name !== 'this') {
            return $this->lexicalScopeKey($expr) . '|variable:' . $expr->name;
        }

        if (
            $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $this->propertyStorageKey($expr, $expr->name->toString());
        }

        if (
            $expr instanceof StaticPropertyFetch
            && $expr->class instanceof Name
            && $expr->name instanceof VarLikeIdentifier
            && \in_array(strtolower($expr->class->toString()), ['self', 'static'], true)
        ) {
            return $this->propertyStorageKey($expr, $expr->name->toString());
        }

        return null;
    }

    private function propertyStorageKey(Node $node, string $propertyName): ?string
    {
        $scope = ScopeFetcher::fetch($node);
        $className = $scope->getClassReflection()?->getName();

        return $className === null
            ? null
            : $scope->getFile() . '|' . $className . '|property:' . $propertyName;
    }

    private function lexicalScopeKey(Node $node): string
    {
        $scope = ScopeFetcher::fetch($node);
        $position = $node->getStartFilePos();
        $selectedRange = null;

        foreach ($this->lexicalRanges[$scope->getFile()] ?? [] as $range) {
            if ($position < $range['start'] || $position > $range['end']) {
                continue;
            }

            if ($selectedRange === null
                || ($range['end'] - $range['start']) < ($selectedRange['end'] - $selectedRange['start'])
            ) {
                $selectedRange = $range;
            }
        }

        return $scope->getFile() . '|lexical:' . ($selectedRange['start'] ?? 'global');
    }

    private function rewrittenType(Identifier|Name|ComplexType $type): Name|NullableType|UnionType|null
    {
        return $this->typeRewriter->rewritten(
            $type,
            fn(Name $name): bool => $this->isName($name, 'DateTime'),
            fn(Name $name): bool => $this->isName($name, 'DateTimeImmutable'),
        );
    }

    private function resolveClassReflection(Class_|Enum_ $class): ?ClassReflection
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

    private function hasExplicitMutableFactory(
        ClassMethod|Function_|Closure|ArrowFunction $functionLike,
    ): bool {
        $nodes = $functionLike instanceof ArrowFunction
            ? [$functionLike->expr]
            : ($functionLike->stmts ?? []);

        return $this->nodeFinder->findFirst(
            $nodes,
            fn(Node $node): bool => $node instanceof StaticCall
                && $node->class instanceof Name
                && $this->isName($node->class, 'DateTime')
                && $node->name instanceof Identifier
                && strtolower($node->name->toString()) === 'createfromimmutable',
        ) instanceof Node;
    }

    private function protectMutableProperty(Class_|Enum_ $class, Property $property): void
    {
        $this->skipConstructionWithin($property);

        $propertyNames = [];

        foreach ($property->props as $propertyItem) {
            $propertyNames[] = $propertyItem->name->toString();
            $storageKey = $this->propertyStorageKey($property, $propertyItem->name->toString());

            if ($storageKey !== null) {
                $this->preservedMutablePropertyKeys[$storageKey] = true;
            }
        }

        $assignments = $this->nodeFinder->find(
            $class->stmts,
            fn(Node $node): bool => ($node instanceof Assign || $node instanceof AssignOp)
                && $this->isPropertyAssignmentTarget($node->var, $propertyNames, $class),
        );

        foreach ($assignments as $assignment) {
            if ($assignment instanceof Assign || $assignment instanceof AssignOp) {
                $this->skipConstructionWithin($assignment->expr);
            }
        }
    }

    /**
     * @param list<string> $propertyNames
     */
    private function isPropertyAssignmentTarget(
        Expr $expr,
        array $propertyNames,
        Class_|Enum_ $class,
    ): bool {
        $className = $class->namespacedName;
        $expressionClass = ScopeFetcher::fetch($expr)->getClassReflection();

        if (
            $className instanceof Name
            && $expressionClass instanceof ClassReflection
            && $expressionClass->getName() !== $className->toString()
        ) {
            return false;
        }

        if (
            $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return \in_array($expr->name->toString(), $propertyNames, true);
        }

        if (
            !$expr instanceof StaticPropertyFetch
            || !$expr->name instanceof VarLikeIdentifier
            || !\in_array($expr->name->toString(), $propertyNames, true)
            || !$expr->class instanceof Name
        ) {
            return false;
        }

        if (\in_array(strtolower($expr->class->toString()), ['self', 'static', 'parent'], true)) {
            return true;
        }

        return $className instanceof Name
            && $this->isName($expr->class, $className->toString());
    }

    private function recordPreservedPromotedProperty(Param $param): void
    {
        if (!$param->var instanceof Variable || !\is_string($param->var->name)) {
            return;
        }

        $storageKey = $this->propertyStorageKey($param, $param->var->name);

        if ($storageKey !== null) {
            $this->preservedMutablePropertyKeys[$storageKey] = true;
        }
    }

    /**
     * A return type fed directly by a preserved mutable property must stay
     * mutable: the runtime value keeps being `DateTime`, so rewriting the
     * declaration would guarantee a `TypeError`.
     */
    private function returnsPreservedMutableStorage(
        ClassMethod|Function_|Closure|ArrowFunction $functionLike,
    ): bool {
        if ($this->preservedMutablePropertyKeys === []) {
            return false;
        }

        if ($functionLike instanceof ArrowFunction) {
            return $this->referencesPreservedMutableStorage($functionLike->expr);
        }

        foreach ($this->nodeFinder->findInstanceOf($functionLike->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof Expr && $this->referencesPreservedMutableStorage($return->expr)) {
                return true;
            }
        }

        return false;
    }

    private function referencesPreservedMutableStorage(Expr $expr): bool
    {
        foreach ($this->directValueBranches->branches($expr) as $branch) {
            $storageKey = $this->storageKey($branch);

            if ($storageKey !== null && isset($this->preservedMutablePropertyKeys[$storageKey])) {
                return true;
            }
        }

        return false;
    }

    private function protectMutableSignature(
        ClassMethod|Function_|Closure|ArrowFunction $functionLike,
    ): void {
        foreach ($functionLike->params as $param) {
            $this->protectMutableParameter($param);
        }

        $this->protectMutableReturn($functionLike);
    }

    private function protectMutableParameter(Param $param): void
    {
        if ($param->default instanceof Expr) {
            $this->skipConstructionWithin($param->default);
        }
    }

    private function protectMutableReturn(
        ClassMethod|Function_|Closure|ArrowFunction $functionLike,
    ): void {
        if (!$functionLike->returnType instanceof Node) {
            return;
        }

        if ($functionLike instanceof ArrowFunction) {
            $this->skipConstructionWithin($functionLike->expr);

            return;
        }

        $returns = $this->nodeFinder->findInstanceOf($functionLike->stmts ?? [], Return_::class);

        foreach ($returns as $return) {
            if ($return->expr instanceof Expr) {
                $this->skipConstructionWithin($return->expr);
            }
        }
    }

    private function skipConstructionWithin(Node $node): void
    {
        $scope = $node->getAttribute(AttributeKey::SCOPE);
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        if (!$scope instanceof Scope || $start < 0 || $end < 0) {
            return;
        }

        $this->constructionSkipRanges[$scope->getFile()][] = [
            'start' => $start,
            'end' => $end,
        ];
    }

    private function isConstructionSkipped(Node $node): bool
    {
        $scope = ScopeFetcher::fetch($node);
        $position = $node->getStartFilePos();

        foreach ($this->constructionSkipRanges[$scope->getFile()] ?? [] as $range) {
            if ($position >= $range['start'] && $position <= $range['end']) {
                return true;
            }
        }

        return false;
    }
}
