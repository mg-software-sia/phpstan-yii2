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
use yii\db\ActiveRecord;

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

        return \in_array($methodReflection->getName(), ['asArray', 'one', 'all', 'each', 'batch'], true);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $methodName = $methodReflection->getName();

        switch ($methodName) {
            case 'asArray':
                return $this->_asArray($methodReflection, $methodCall, $scope);
            case 'one':
            case 'all':
                return $this->_oneOrAll($methodReflection, $methodCall, $scope);
            case 'each':
            case 'batch':
                return $this->_eachOrBatch($methodReflection, $methodCall, $scope);
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
        $targetType = $this->_findSearchTarget($calledOnType, $methodReflection);

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

    private function _eachOrBatch(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $methodName = $methodReflection->getName();
        $calledOnType = $scope->getType($methodCall->var);
        $targetType = $this->_findSearchTarget(
            $calledOnType,
            $scope->getMethodReflection($calledOnType, 'one')
        );

        $each = new ArrayType(
            new IntegerType(),
            $targetType,
        );

        // Each
        if ($methodName === 'each') {
            return $each;
        }

        // Batch
        return new ArrayType(
            new IntegerType(),
            $each,
        );
    }

    private function _findSearchTarget(Type $calledOnType, MethodReflection $methodReflection): Type
    {
        $objectType = $this->_findObjectType($methodReflection->getVariants());
        if (!$objectType) {
            throw new ShouldNotHappenException(strtr('ObjectType return type not found for {c}::{m}()', [
                '{c}' => $methodReflection->getDeclaringClass()->getName(),
                '{m}' => $methodReflection->getName(),
            ]));
        }

        if (!$objectType->getClassReflection()->isSubclassOf(ActiveRecord::class)) {
            throw new ShouldNotHappenException(strtr('{c} must be subclass of {p}', [
                '{c}' => $objectType->getClassName(),
                '{p}' => ActiveRecord::class,
            ]));
        }

        if ($calledOnType instanceof ArrayActiveQueryObjectType) {
            return new ArrayType(new StringType(), new MixedType());
        }

        return $objectType;
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
