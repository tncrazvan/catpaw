<?php
namespace CatPaw\Core;

use Error;
use FFI;
use ReflectionClass;
use Throwable;

/**
 * @template T
 * @package CatPaw\Core
 */
class GoffiContract {
    /**
     * Create a Goffi contract.
     * @template T
     * @param  class-string<T>         $interface
     * @param  string                  $fileName
     * @return Unsafe<GoffiContract&T>
     */
    public static function create(string $interface, string $fileName):Unsafe {
        $strippedFileName = preg_replace('/\.so$/', '', $fileName);
        $sharedFile       = File::open("$strippedFileName.static.h")->try($error);
        if ($error) {
            $sharedFile = File::open("$strippedFileName.h")->try($error);
            if ($error) {
                return error($error);
            }
        }

        $cdefComplex = $sharedFile->readAll()->await()->try($error);

        if ($error) {
            return error($error);
        }

        if (null === ($cdef = preg_replace('/^\s*typedef\s+(float|double)\s+_Complex.*/m', '', $cdefComplex))) {
            return error("Unknown error while trying to clear `_Complex` definitions in the header file {$sharedFile->fileName}.");
        }
        try {
            $lib = FFI::cdef($cdef, $fileName);
        } catch(Throwable $error) {
            return error($error);
        }

        $methods = self::loadContract($lib, $interface)->try($error);
        if ($error) {
            return error($error);
        }

        return ok(new self($lib, $methods));
    }

    /**
     *
     * @param  FFI&T $ffi
     * @return void
     */
    private function __construct(readonly public FFI $lib, readonly private array $methods) {
    }

    public function __call(string $name, mixed $arguments) {
        return $this->methods[$name](...$arguments);
    }

    /**
     * Parse the given `$interface` and create a _contract_ (let's call it so), which is a set of methods
     * you can use to more easily call your Go functions.
     *
     * Technically this is not necessary, you can invoke Go functions directly through `$goffi->lib->myFunction()`,
     * but it's not as simple as it looks, because some Go primitives require creating some specific structures and don't map directly to Php primitives.\
     * For example Go strings are slices, and they are defined as
     * ```c
     * typedef struct { const char *p; ptrdiff_t n; } _GoString_;
     * ```
     * Php's FFI api will not convert php `string`s into `_GoString_`s (understandably so), which means you would need to do it by hand, which is not the best experience.
     *
     * This method will create the necessary _contract methods_ that will automate your Go calls, converting php strings and other primitives to their correct Go counterparts.
     * @param  FFI    $lib
     * @param  string $interface
     * @return Unsafe
     */
    private static function loadContract(FFI $lib, string $interface):Unsafe {
        $reflectionClass = new ReflectionClass($interface);
        $methods         = [];
        foreach ($reflectionClass->getMethods() as $reflectionMethod) {
            $methodName           = $reflectionMethod->getName();
            $reflectionParameters = $reflectionMethod->getParameters();
            $methodReturnType     = $reflectionMethod->getReturnType();
            if (!$methodReturnType) {
                return error("The Go contract method `$methodName` must return `".(Unsafe::class)."`.");
            }

            $returnType = ReflectionTypeManager::unwrap($methodReturnType);

            if (Unsafe::class !== $returnType->getName()) {
                return error("The Go contract method `$methodName` must return `".(Unsafe::class)."`.");
            }


            $resolvers   = [];
            $paramsCount = 0;

            // Create parameters resolvers for Go FFI.
            // This is necessary because some Go primitives differ from Php primitives, like strings.
            foreach ($reflectionParameters as $reflectionParameter) {
                $parameterName = $reflectionParameter->getName();
                if (!$type = ReflectionTypeManager::unwrap($reflectionParameter)) {
                    return error("The Go contract method `$methodName` defines parameter `$parameterName` without a type, which is not allowed. Please make sure that parameter `$parameterName` specifies a type.");
                }
                $paramsCount++;
                $resolvers[] = match ($type->getName()) {
                    'string' => fn (string $phpString) => self::goString($lib, $phpString),
                    'int'    => fn (int $value) => ok($value),
                    'float'  => fn (float $value) => ok($value),
                    'bool'   => fn (bool $value) => ok($value),
                };
            }

            $methods[$methodName] = function(...$args) use (
                $resolvers,
                $paramsCount,
                $methodName,
                $interface,
                $lib,
            ) {
                // Check that the correct number of parameters are being received.
                // In the future I should check for optional/nullable parameters and decide what to do with those.
                // For now all defined parameters will be required for simplicity sake.
                $argsCount = count($args);
                if ($paramsCount !== $argsCount) {
                    return error("The Go contract method `$methodName` in interface `$interface` is expecting $paramsCount parameters, but only $argsCount have been received. Please make sure the correct number of parameters are being passed.");
                }

                $resolvedArgs = [];
                foreach ($args as $key => $arg) {
                    $resolver = $resolvers[$key];
                    /** @var Error $error */
                    $resolvedArgs[] = $resolver($arg)->try($error);
                    if ($error) {
                        return error($error);
                    }
                }
                return ok($lib->$methodName(...$resolvedArgs));
            };
        }
        return ok($methods);
    }

    /**
     * Create a Go string from a Php string.
     * @param  FFI           $lib
     * @param  string        $phpString
     * @return Unsafe<mixed> the Go string.
     */
    private static function goString(FFI $lib, string $phpString):Unsafe {
        try {
            $struct    = $lib->new('_GoString_');
            $count     = strlen($phpString);
            $struct->p = $lib->new('char['.$count.']', 0);
        } catch(Throwable $error) {
            return error($error);
        }

        FFI::memcpy($struct->p, $phpString, $count);
        $struct->n = 5;

        return ok($struct);
    }
}
