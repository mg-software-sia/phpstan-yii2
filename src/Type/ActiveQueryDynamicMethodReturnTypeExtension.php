<?php

declare(strict_types=1);

namespace Proget\PHPStan\Yii2\Type;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use yii\db\ActiveQuery;

final class ActiveQueryDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return ActiveQuery::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // Needed for Array type forwarding
        $returnType = ParametersAcceptorSelector::selectSingle($methodReflection->getVariants())->getReturnType();
        if ($returnType instanceof ObjectType && $returnType->isInstanceOf(ActiveQuery::class)) {
            return true;
        }

        return \in_array($methodReflection->getName(), ['asArray', 'one', 'all'], true);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $methodName = $methodReflection->getName();

        if (\in_array($methodName, ['asArray'])) {
            return $this->_asArray($methodReflection, $methodCall, $scope);
        }

        if (\in_array($methodName, ['one', 'all'])) {
            return $this->_oneOrAll($methodReflection, $methodCall, $scope);
        }

        // Forward type
        return $scope->getType($methodCall->var);
    }

    private function _asArray(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ObjectType
    {
        $argType = isset($methodCall->args[0]) ? $scope->getType($methodCall->args[0]->value) : new ConstantBooleanType(true);
        if (!$argType instanceof ConstantBooleanType) {
            throw new ShouldNotHappenException(sprintf('Invalid argument provided to asArray method at line %d', $methodCall->getLine()));
        }

        $calledOnType = $scope->getType($methodCall->var);

        switch (true) {
            case $calledOnType instanceof ObjectType:
                $className = $calledOnType->getClassName();
                break;
            case $calledOnType instanceof ThisType:
                throw new ShouldNotHappenException('Please, use asArray outside Query class.');
            default:
                throw new ShouldNotHappenException('Unknown scope type: ' . get_class($calledOnType));
        }

        if ($argType->getValue()) {
            return new ArrayActiveQueryObjectType($className);
        }

        return new ObjectType($className);
    }

    private function _oneOrAll(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $methodName = $methodReflection->getName();
        $calledOnType = $scope->getType($methodCall->var);
        $objectType = $this->_findObjectType($methodReflection->getVariants());
        if (!$objectType) {
            throw new ShouldNotHappenException(strtr('ObjectType return type not found for {c}::{m}()', [
                '{c}' => $methodReflection->getDeclaringClass()->getName(),
                '{m}' => $methodReflection->getName(),
            ]));
        }

        $targetType = $calledOnType instanceof ArrayActiveQueryObjectType ? new ArrayType(new StringType(), new MixedType()) : $objectType;

        // One
        if ($methodName === 'one') {
            return TypeCombinator::union(
                new NullType(),
                $targetType,
            );
        }

        // All
        return new ArrayType(
            new IntegerType(),
            $targetType,
        );
    }

    /**
     * Recursive ObjectType finder
     *
     * @param mixed $input
     * @return ObjectType|null
     */
    private function _findObjectType($input): ?ObjectType
    {
        // Walk through array
        if (is_array($input)) {
            foreach ($input as $value) {
                if ($result = $this->_findObjectType($value)) {
                    return $result;
                }
            }
            return null;
        }

        if ($input instanceof ParametersAcceptor) {
            return $this->_findObjectType($input->getReturnType());
        }

        if ($input instanceof UnionType) {
            return $this->_findObjectType($input->getTypes());
        }

        if ($input instanceof ArrayType) {
            return $this->_findObjectType($input->getItemType());
        }

        // Finally got it
        if ($input instanceof ObjectType) {
            return $input;
        }

        return null;
    }
}
