<?php

declare(strict_types=1);

namespace Proget\PHPStan\Yii2\Type;

use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

class ActiveQueryObjectType extends ObjectType
{
    /**
     * @var string
     */
    private $modelClass;

    /**
     * @var bool
     */
    private $asArray;

    public function __construct(string $modelClass, bool $asArray, string $queryClass = 'yii\db\ActiveQuery')
    {
        parent::__construct($queryClass);

        $this->modelClass = $modelClass;
        $this->asArray = $asArray;
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function isAsArray(): bool
    {
        return $this->asArray;
    }

    public function describe(VerbosityLevel $level): string
    {
        return sprintf('%s<%s>', parent::describe($level), $this->modelClass);
    }
}
