<?php

namespace CatPaw\Core\Interfaces;

use CatPaw\Core\DependenciesOptions;
use CatPaw\Core\None;
use CatPaw\Core\Unsafe;
use ReflectionParameter;

interface OnParameterMount {
    /**
     * Invoked when this attribute is detected on a parameter.
     * @param  ReflectionParameter $reflection Reflection of the parameter.
     * @param  mixed               $value      Current value of the parameter.
     * @param  DependenciesOptions $options    Options used to find dependencies.
     * @return Unsafe<None>
     */
    public function onParameterMount(ReflectionParameter $reflection, mixed &$value, DependenciesOptions $options):Unsafe;
}
