<?php

declare(strict_types=1);

namespace Proget\PHPStan\Yii2\Type;

use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

final class ActiveRecordDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return 'yii\db\ActiveRecord';
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return \in_array($methodReflection->getName(), ['hasOne', 'hasMany'], true);
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        $argType = $scope->getType($methodCall->args[0]->value);
        if (!($argType instanceof ConstantStringType)) {
            throw new ShouldNotHappenException(sprintf('Invalid argument provided to method %s' . PHP_EOL . 'Hint: You should use ::class instead of ::className()', $methodReflection->getName()));
        }

        $model = new ObjectType($argType->getValue());
        $findMethod = $scope->getMethodReflection($model, 'find');

        $type = ParametersAcceptorSelector::selectSingle($findMethod->getVariants())->getReturnType();
        if (!($type instanceof ObjectType)) {
            throw new ShouldNotHappenException(strtr('ObjectType return type not found for {c}::{m}()', [
                '{c}' => $argType->getValue(),
                '{m}' => 'find',
            ]));
        }

        if (!$type->getClassReflection()->isSubclassOf(ActiveQuery::class)) {
            throw new ShouldNotHappenException(strtr('{c} must be subclass of {p}', [
                '{c}' => $type->getClassName(),
                '{p}' => ActiveQuery::class,
            ]));
        }

        return $type;
    }
}
