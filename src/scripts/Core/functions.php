<?php

namespace CatPaw\Core;

use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStdin;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableIterableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Process\Process;
use CatPaw\Core\Interfaces\EnvironmentInterface;
use Error;
use Generator;
use Phar;
use Psr\Log\LoggerInterface;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Throwable;


/**
 * Get current time in milliseconds.
 * @return float
 */
function milliseconds(): float {
    return floor(microtime(true) * 1000);
}

/**
 * Check if an array is associative.
 * @template T
 * @param  array<T> $arr
 * @return bool     true if the array is associative, false otherwise.
 */
function isAssoc(array $arr): bool {
    if ([] === $arr) {
        return false;
    }
    return array_keys($arr) !== range(0, count($arr) - 1);
}


/**
 * Generate a universally unique identifier
 *
 * *Caution*: this function does not generate cryptographically secure values, and must not be used for cryptographic purposes, or purposes that require returned values to be unguessable.
 * @return string the uuid
 */
function uuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        // 32 bits for "time_low"
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),

        // 16 bits for "time_mid"
        mt_rand(0, 0xffff),

        // 16 bits for "time_hi_and_version",
        // four most significant bits holds version number 4
        mt_rand(0, 0x0fff) | 0x4000,

        // 16 bits, 8 bits for "clk_seq_hi_res",
        // 8 bits for "clk_seq_low",
        // two most significant bits holds zero and one for variant DCE1.1
        mt_rand(0, 0x3fff) | 0x8000,

        // 48 bits for "node"
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Check if the current application is running inside a .phar archive or not.
 * @return bool
 */
function isPhar(): bool {
    return strlen(Phar::running()) > 0 ? true : false;
}

/**
 * Request an input from the terminal without feeding back to the display whatever it's been typed.
 * @param  string         $prompt message to display along with the input request.
 * @return Unsafe<string>
 */
function readLineSilent(string $prompt): Unsafe {
    $command = "/usr/bin/env bash -c 'echo OK'";
    if (rtrim(shell_exec($command)) !== 'OK') {
        return error("Can't invoke bash");
    }
    $command = "/usr/bin/env bash -c 'read -s -p \""
        .addslashes($prompt)
        ."\" hidden_value && echo \$hidden_value'";
    $hiddenValue = rtrim(shell_exec($command));
    echo "\n";
    return ok($hiddenValue);
}


/**
 * @template T
 * @param  array<T> $array
 * @param  bool     $completely if true, flatten the array completely
 * @return array<T>
 */
function flatten(array $array, bool $completely = false): array {
    if ($completely) {
        return iterator_to_array(new RecursiveIteratorIterator(new RecursiveArrayIterator($array)), false);
    }

    return array_merge(...array_values($array));
}

/**
 * Get the stdout as a stream.
 */
function out(): WritableResourceStream {
    return getStdout();
}

/**
 * Get the stdin as a stream.
 */
function in(): ReadableResourceStream {
    return getStdin();
}

/**
 * Create an unsafe object with a value.
 * @template T
 * @param  T         $value
 * @return Unsafe<T>
 */
function ok(mixed $value = NONE): Unsafe {
    return new Unsafe($value, null);
}

/**
 * Create an unsafe object with an error.
 * @param  string|Error  $message
 * @return Unsafe<mixed>
 */
function error(string|Error $message): Unsafe {
    if (is_string($message)) {
        /** @var Unsafe<mixed> */
        $error = new Unsafe(null, new Error($message));
        return $error;
    }
    /** @var Unsafe<mixed> */
    return new Unsafe(null, $message);
}


/**
 * Execute a command.
 * @param  string               $command       Command to run.
 * @param  false|WritableStream $output        Send the output of the process to this stream.
 * @param  false|string         $workDirectory Work directory of the command.
 * @param  false|Signal         $kill          When this signal is triggered the process is killed.
 * @return Unsafe<int>
 */
function execute(
    string $command,
    false|WritableStream $output = false,
    false|string $workDirectory = false,
    false|Signal $kill = false,
): Unsafe {
    try {
        $logger = Container::get(LoggerInterface::class)->unwrap($error);
        if ($error) {
            return error($error);
        }
        $process = Process::start($command, $workDirectory?:null);
        if ($output) {
            pipe($process->getStdout(), $output);
            pipe($process->getStderr(), $output);
        }
        $code = $process->join();
    } catch(Throwable $error) {
        return error($error);
    }

    if ($kill) {
        $kill->listen(static function() use ($process, $logger) {
            if (!$process->isRunning()) {
                return;
            }

            try {
                $process->signal(9);
            } catch(Throwable $error) {
                $logger->error($error);
            }
        });
    }

    return ok($code);
}

/**
 * Execute a command and return its output.
 * @param  string         $command command to run
 * @return Unsafe<string>
 */
function get(string $command): Unsafe {
    [$reader, $writer] = duplex();
    execute($command, $writer)->unwrap($error);
    if ($error) {
        return error($error);
    }
    return ok(buffer($reader));
}

/**
 * Invoke a generator function and immediately return any `Error`
 * or `Unsafe` that contains an error.\
 * In both cases the result is always an `Unsafe<T>` object.
 *
 * - If you generate an `Unsafe<T>` the error within the object is transferred to a new `Unsafe<T>` for the sake of consistency.
 * - If you generate an `Error` instead, then the `Error` is wrapped in `Unsafe<T>`.
 *
 * The generator is consumed and if no error is detected then the function produces the returned value of the generator.
 *
 * ## Example
 * ```php
 * $content = anyError(function(){
 *  $file = File::open('file.txt')->unwrap();
 *  $content = $file->readAll()->unwrap();
 *  return $content;
 * });
 * ```
 * @template T
 * @param  callable():(Generator<string>|Unsafe<T>|T) $function
 * @return Unsafe<T>
 */
function anyError(callable $function): Unsafe {
    $result = $function();

    if (!($result instanceof Generator)) {
        if ($result instanceof Unsafe) {
            return $result;
        }
        /** @var Unsafe<T> */
        return ok($result);
    }

    for ($result->rewind(); $result->valid(); $result->next()) {
        $value = $result->current();
        if ($value instanceof Error) {
            return error($value);
        } elseif ($value instanceof Unsafe && $value->error) {
            return error($value->error);
        }
    }

    try {
        $return = $result->getReturn() ?? true;

        if ($return instanceof Error) {
            return error($return);
        } else if ($return instanceof Unsafe) {
            return $return;
        }

        return ok($return);
    } catch (Throwable $error) {
        return error($error);
    }
}

/**
 * Return two new streams, a readable stream and a writable one which will be writing to the first stream.
 *
 * The writer stream will automatically be disposed of when the readable stream is disposed of.
 * @param  int                                                      $bufferSize
 * @return array{0:ReadableIterableStream,1:WritableIterableStream}
 */
function duplex(int $bufferSize = 8192): array {
    $writer = new WritableIterableStream($bufferSize);
    $reader = new ReadableIterableStream($writer);
    return [$reader, $writer];
}

/**
 * Resolve on the next event loop tick.
 * @return Future<void>
 */
function tick(): Future {
    /** @var Future<void> */
    return (new DeferredFuture)->getFuture()->complete();
}


/**
 * @return DeferredFuture<mixed>
 */
function deferred(): DeferredFuture {
    /** @var DeferredFuture<mixed> */
    return new DeferredFuture;
}


/**
 * Find an environment variable by name.
 *
 * ## Example
 * ```php
 * $service->findByName("server")['www'];
 * // or better even
 * $service->$findByName("server.www");
 * ```
 * @param  string $query name of the variable or a query in the form of `"key.subkey"`.
 * @return mixed  value of the variable.
 */
function env(string $query): mixed {
    /** @var false|EnvironmentInterface */
    static $env = false;

    if (!$env) {
        $env = Container::get(EnvironmentInterface::class)->unwrap($error);
        if ($error) {
            Bootstrap::kill("Couldn't load environment service.\n$error", CommandStatus::NO_DATA_AVAILABLE);
        }
    }

    return $env->get($query);
}


/**
 * Stop the program with an error.
 * @param  string|Error $error
 * @return never
 */
function stop(string|Error $error) {
    if (is_string($error)) {
        $error = new Error($error);
    }
    Bootstrap::kill((string)$error);
}

/**
 * Given a `$path`, create a file name.
 * @param  string   ...$path
 * @return FileName
 */
function asFileName(string ...$path):FileName {
    return FileName::create($path);
}