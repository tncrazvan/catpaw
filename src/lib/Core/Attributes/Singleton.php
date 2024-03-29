<?php
namespace CatPaw\Core\Attributes;

use Attribute;
use function CatPaw\Core\error;
use CatPaw\Core\Interfaces\AttributeInterface;
use CatPaw\Core\Interfaces\OnClassInstantiation;

use function CatPaw\Core\ok;
use CatPaw\Core\Traits\CoreAttributeDefinition;
use CatPaw\Core\Unsafe;

use ReflectionClass;
use Throwable;

/**
 * Attach this attribute to a class and catpaw will treat it as a singleton.
 */
#[Attribute(flags: Attribute::TARGET_CLASS)]
class Singleton implements AttributeInterface, OnClassInstantiation {
    use CoreAttributeDefinition;

    public function __construct() {
    }

    private static array $cache = [];

    /**
     * Clear all cached singletons.<br/>
     * Next time you create a new instance the cache will miss.
     * @return void
     * @internal
     */
    public static function clearAll():void {
        self::$cache = [];
    }

    /**
     * Manually cache the instance of a class.<br/>
     * Next time you try create an instance of given class the cache will hit.
     * @param  string $className
     * @param  mixed  $value
     * @return void
     * @internal
     */
    public static function set(string $className, mixed $value):void {
        self::$cache[$className] = $value;
    }

    /**
     * Check if a given class is cached.
     * @param  string $className
     * @return bool
     * @internal
     */
    public static function exists(string $className):bool {
        return isset(self::$cache[$className]);
    }

    /**
     * Get the instance of a given class.<br/>
     * The created instance is cached, which means all classes are singletons.
     * @param  string $className
     * @return mixed
     * @internal
     */
    public static function get(string $className):mixed {
        return self::$cache[$className] ?? false;
    }

    /**
     * Invoked whenever the instance is created.
     * @param  ReflectionClass $reflection
     * @param  mixed           $instance
     * @param  array           $dependencies
     * @return Unsafe
     * @internal
     */
    public function onClassInstantiation(ReflectionClass $reflection, mixed &$instance, array $dependencies): Unsafe {
        try {
            $className               = $reflection->getName();
            $instance                = new $className(...$dependencies);
            self::$cache[$className] = $instance;
        } catch(Throwable $e) {
            return error($e);
        }
        return ok();
    }
}
