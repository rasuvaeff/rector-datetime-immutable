<?php

declare(strict_types=1);

namespace Rasuvaeff\RectorDateTimeImmutable\Internal;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Identifier;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ObjectType;
use Rector\NodeTypeResolver\PHPStan\ParametersAcceptorSelectorVariantsWrapper;
use Rector\PHPStan\ScopeFetcher;
use Rector\Reflection\ReflectionResolver;

/**
 * Resolves call arguments whose declared parameter accepts mutable `DateTime`
 * but rejects `DateTimeImmutable`.
 *
 * @internal
 */
final readonly class MutableDateTimeBoundaryAnalyzer
{
    private ObjectType $mutableType;
    private ObjectType $immutableType;

    public function __construct(
        private ReflectionResolver $reflectionResolver,
    ) {
        $this->mutableType = new ObjectType(\DateTime::class);
        $this->immutableType = new ObjectType(\DateTimeImmutable::class);
    }

    /**
     * @return list<array{argument: Arg, parameter: string}>
     */
    public function unsafeArguments(CallLike $call): array
    {
        if ($call->isFirstClassCallable()) {
            return [];
        }

        $reflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($call);

        if (!$reflection instanceof FunctionReflection && !$reflection instanceof MethodReflection) {
            return [];
        }

        if (!$this->isStableBoundary($reflection)) {
            return [];
        }

        $parameters = ParametersAcceptorSelectorVariantsWrapper::select(
            $reflection,
            $call,
            ScopeFetcher::fetch($call),
        )->getParameters();
        $unsafe = [];

        foreach (array_values($call->getArgs()) as $position => $argument) {
            if ($argument->unpack) {
                continue;
            }

            $parameter = $this->resolveParameter($argument, $position, $parameters);

            if (!$parameter instanceof ParameterReflection || !$this->requiresMutableDateTime($parameter)) {
                continue;
            }

            $argumentType = ScopeFetcher::fetch($argument->value)->getType($argument->value);

            if ($argumentType->isSuperTypeOf($this->mutableType)->no()
                && $this->mutableType->isSuperTypeOf($argumentType)->no()
            ) {
                continue;
            }

            $unsafe[] = [
                'argument' => $argument,
                'parameter' => '$' . $parameter->getName(),
            ];
        }

        return $unsafe;
    }

    private function requiresMutableDateTime(ParameterReflection $parameter): bool
    {
        $type = $parameter->getType();

        return $type->isSuperTypeOf($this->mutableType)->yes()
            && $type->isSuperTypeOf($this->immutableType)->no();
    }

    private function isStableBoundary(FunctionReflection|MethodReflection $reflection): bool
    {
        $docComment = $reflection->getDocComment();

        if (is_string($docComment) && str_contains($docComment, '@mutable-datetime')) {
            return true;
        }

        if ($reflection instanceof FunctionReflection) {
            return $reflection->isBuiltin() || $this->isVendorFile($reflection->getFileName());
        }

        $declaringClass = $reflection->getDeclaringClass();

        if ($declaringClass->isBuiltin() || $declaringClass->isInterface() || $declaringClass->isAbstract()) {
            return true;
        }

        if ($this->isVendorFile($declaringClass->getFileName())) {
            return true;
        }

        foreach ([...$declaringClass->getParents(), ...$declaringClass->getInterfaces()] as $ancestor) {
            if ($ancestor->hasNativeMethod($reflection->getName())) {
                return true;
            }
        }

        return false;
    }

    private function isVendorFile(?string $file): bool
    {
        if ($file === null) {
            return false;
        }

        return str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    /**
     * @param list<ParameterReflection> $parameters
     */
    private function resolveParameter(
        Arg $argument,
        int $position,
        array $parameters,
    ): ?ParameterReflection {
        if ($argument->name instanceof Identifier) {
            foreach ($parameters as $parameter) {
                if ($parameter->getName() === $argument->name->toString()) {
                    return $parameter;
                }
            }

            return null;
        }

        $parameter = $parameters[$position] ?? null;

        if ($parameter instanceof ParameterReflection) {
            return $parameter;
        }

        $lastParameter = end($parameters);

        return $lastParameter instanceof ParameterReflection && $lastParameter->isVariadic()
            ? $lastParameter
            : null;
    }
}
