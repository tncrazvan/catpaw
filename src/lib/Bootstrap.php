<?php

namespace CatPaw;

use function Amp\ByteStream\getStdout;
use function Amp\call;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Promise;
use CatPaw\Attributes\AttributeLoader;
use CatPaw\Attributes\Entry;
use CatPaw\Utilities\Container;
use CatPaw\Utilities\Dir;
use CatPaw\Utilities\Strings;
use Closure;
use Generator;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;

class Bootstrap {
    private static function checkEntryChange(string $fileName, array &$store) {
        $size = filesize($fileName);
        $lastChange = filemtime($fileName);
        if (isset($store[$fileName]) && $store[$fileName]['lastChange'] < $lastChange) {
            Loop::stop();
        } else {
            $store[$fileName] = [
                'name' => $fileName,
                'size' => $size,
                'lastChange' => $lastChange,
            ];
        }
    }

    private static function checkFileChange(string $dirname, array &$store) {
        $files = [];
        Dir::findFilesRecursive($dirname, $files);

        foreach ($files as $file) {
            $name = $file['name'];
            if (isset($store[$name])) {
                if ($store[$name]['lastChange'] < $file['lastChange']) {
                    Loop::stop();
                    return;
                }
            }
            $store[$name] = $file;
        }
    }

    private static function watch(string $entryFileName, array $dirs, int $sleep) {
        $store = [];

        self::checkEntryChange($entryFileName, $store);

        foreach ($dirs as $dir) {
            self::checkFileChange($dir, $store);
        }


        Loop::repeat($sleep, function() use ($dirs, $store, $entryFileName) {
            self::checkEntryChange($entryFileName, $store);
            foreach ($dirs as $dir) {
                self::checkFileChange($dir, $store);
            }
        });
    }


    /**
     * @throws ReflectionException
     */
    private static function entry(string $className): array|false {
        $i = new ReflectionClass($className);
        foreach ($i->getMethods() as $method) {
            if (($attributes = $method->getAttributes(Entry::class))) {
                if (count($attributes) > 0) {
                    return [$i, $method];
                }
            }
        }
        return [$i, false];
    }

    /**
     * @param  MainConfiguration   $config
     * @param  string              $filename
     * @throws ReflectionException
     * @return Generator
     */
    private static function init(MainConfiguration $config, string $filename): Generator {
        if (is_file($filename)) {
            $filename = realpath($filename);
            $owd = getcwd();
            chdir(dirname($filename));
            require_once $filename;
            chdir($owd);
            /** @var mixed $result */
            if (!function_exists('main')) {
                die(Strings::red("Please define a global main function.\n"));
            }
            $main = new ReflectionFunction('main');

            foreach ($main->getAttributes() as $attribute) {
                $attributeArguments = $attribute->getArguments();
                $className = $attribute->getName();
                /** @var ReflectionFunction $entry */
                [$klass, $entry] = self::entry($className);
                $object = $klass->newInstance(...$attributeArguments);
                if ($entry) {
                    $arguments = yield Container::dependencies($entry);
                    yield \Amp\call(fn() => $entry->invoke($object, ...$arguments));
                }
            }
            yield Container::run($main);
        } else {
            die(Strings::red("Could not find php entry file \"$filename\".\n"));
        }
    }

    public static function start(string $filename, bool $watch = false, int $watchSleep = 100, false|Closure $callback = false) {
        $config = new class($watch, $watchSleep) extends MainConfiguration {
            public function __construct(bool $watch, int $watchSleep) {
                $handler = new StreamHandler(getStdout());
                $handler->setFormatter(new ConsoleFormatter());
                $logger = new Logger('app');
                $logger->pushHandler($handler);

                $this->logger = $logger;
                Container::setObject(Logger::class, $logger);
                Container::setObject(LoggerInterface::class, $logger);

                $this->watch["enabled"] = $watch;
                $this->watch["sleep"] = $watchSleep;
            }
        };

        Container::setObject(MainConfiguration::class, $config);

        set_time_limit(0);
        ob_implicit_flush();
        ini_set('memory_limit', '-1');

        if (!$filename) {
            die(Strings::red("Please point to a php entry file.\n"));
        }

        Loop::run(function() use ($filename, $callback, $config) {
            $loader = new AttributeLoader();
            $loader->setLocation(getcwd());

            $dirs = [];
            $namespaces = $loader->getDefinedNamespaces();
            foreach ($namespaces as $namespace => $locations) {
                $loader->loadModulesFromNamespace($namespace);
                yield $loader->loadClassesFromNamespace($namespace);
                $dirs = array_merge($dirs, $loader->getNamespaceDirectories($namespace));
            }

            if ($config->watch["enabled"]) {
                self::watch(
                    entryFileName: $filename,
                    dirs         : $dirs,
                    sleep        : $config->watch["sleep"]
                );
            }


            yield from self::init($config, $filename);

            if ($callback) {
                yield \Amp\call($callback);
            }
        });
    }
}