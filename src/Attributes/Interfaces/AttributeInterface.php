<?php

namespace CatPaw\Attributes\Interfaces;

use Amp\Promise;
use CatPaw\Http\HttpContext;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;
use ReflectionParameter;
use ReflectionProperty;

interface AttributeInterface {

	public static function findByFunction(ReflectionFunction $reflectionMethod): Promise;

	public static function findByMethod(ReflectionMethod $reflectionMethod): Promise;

	public static function findByClass(ReflectionClass $reflectionClass): Promise;

	public static function findByProperty(ReflectionProperty $reflectionProperty): Promise;

	/**
	 * Triggers whenever the attribute it assigned to a parameter.
	 * @param ReflectionParameter $reflection the reflection of the parameter.
	 * @param mixed               $value the current value of the parameter.
	 * @param false|HttpContext   $http the HttpContext if available, false otherwise.
	 * @return Promise<void>
	 */
	public function onParameter(ReflectionParameter $reflection, mixed &$value, false|HttpContext $http): Promise;


	/**
	 * Triggers whenever the attribute is assigned to a route handler.<br/>
	 * Route handlers are closure functions assigned using "Route::get", "Route::post", "Route::update", etc.<br/>
	 * @see https://github.com/tncrazvan/catpaw-core/blob/main/docs/1.RouteHandlers.md
	 * @see https://github.com/tncrazvan/catpaw-core/blob/main/docs/9.Filters.md
	 * @param ReflectionFunction $reflection
	 * @param Closure            $value
	 * @param bool               $isFilter
	 * @return Promise
	 */
	public function onRouteHandler(ReflectionFunction $reflection, Closure &$value, bool $isFilter): Promise;
}