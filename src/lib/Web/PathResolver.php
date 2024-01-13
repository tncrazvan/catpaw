<?php

namespace CatPaw\Web;

use function CatPaw\Core\error;
use function CatPaw\Core\ok;
use CatPaw\Core\ReflectionTypeManager;

use CatPaw\Core\Unsafe;
use CatPaw\Web\Attributes\Param;

class PathResolver {
    /** @var array<string,array<PathResolver>> */
    private static array $cache = [];

    /**
     * @return Unsafe<void>
     */
    public static function cacheResolver(string $symbolicMethod, string $symbolicPath, array $parameters):Unsafe {
        self::findResolver($symbolicMethod, $symbolicPath, $parameters)->try($error);
        if ($error) {
            return error($error);
        }

        return ok();
    }

    /**
     * @return Unsafe<array<PathResolver>>
     */
    public static function findResolver(string $symbolicMethod, string $symbolicPath, array $parameters):Unsafe {
        $key = "$symbolicMethod:$symbolicPath";
        if (isset(self::$cache[$key])) {
            return ok(self::$cache[$key]);
        }

        $configurations = self::findMatchingPathConfigurations($symbolicPath, $parameters)->try($error);
        if ($error) {
            return error($error);
        }

        return ok(self::$cache[$key] = new PathResolver($configurations));
    }



    /**
     * @return Unsafe<array<MatchingPathConfiguration>>
     */
    public static function findMatchingPathConfigurations(string $path, array $reflectionParameters):Unsafe {
        /** @var array<MatchingPathConfiguration> $configurations */
        $configurations = [];

        foreach ($reflectionParameters as $reflectionParameter) {
            $reflectionParameterName = $reflectionParameter->getName();
            /** @var Param $param */
            $param = Param::findByParameter($reflectionParameter)->try($error);
            if ($error) {
                return error($error);
            }
            if (!$param) {
                continue;
            }

            $typeName = ReflectionTypeManager::unwrap($reflectionParameter) ?? 'string';

            if ('' === $param->getRegex()) {
                switch ($typeName) {
                    case 'int':
                        $param->setRegex('[-+]?[0-9]+');
                        break;
                    case 'float':
                        $param->setRegex('[-+]?[0-9]+\.[0-9]+');
                        break;
                    case 'string':
                        $param->setRegex('[^\/]*');
                        break;
                    case 'bool':
                        $param->setRegex('(0|1|no?|y(es)?|false|true)');
                        break;
                }
            }

            $configurations[] = new MatchingPathConfiguration(
                param: $param,
                name: $reflectionParameterName,
                namePattern: '\{'.$reflectionParameterName.'\}',
                isStatic: false,
            );
        }
        
        $result = [];

        if (preg_match_all('/{([^{}]+)}/', $path, $matches)) {
            foreach ($matches[1] as $key => $match) {
                if (!$configuration = $configurations[$key] ?? false) {
                    $param         = new Param('[^\/]*');
                    $configuration = new MatchingPathConfiguration(
                        param: $param,
                        name: $match,
                        namePattern: '\{'.$match.'\}',
                        isStatic: false,
                    );
                } else if (!MatchingPathConfiguration::findInArrayByName($configurations, $configuration)) {
                    $param         = new Param('[^\/]*');
                    $configuration = new MatchingPathConfiguration(
                        param: $param,
                        name: $match,
                        namePattern: '\{'.$match.'\}',
                        isStatic: false,
                    );
                }

                $result[] = $configuration;
            }
        }


        return ok(MatchingPathConfiguration::mergeWithPath($result, $path));
    }

    /**
     * 
     * @param  array<MatchingPathConfiguration> $configurations
     * @return void
     */
    public function __construct(private readonly array $configurations) {
    }

    public function findParametersFromPath(string $path):false|array {
        return MatchingPathConfiguration::findParametersFromPath($this->configurations, $path);
    }
}