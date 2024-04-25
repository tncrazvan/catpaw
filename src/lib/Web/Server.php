<?php

namespace CatPaw\Web;

use Amp\CompositeException;
use Amp\DeferredFuture;
use function Amp\File\isDirectory;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Middleware;
use function Amp\Http\Server\Middleware\stackMiddleware;
use Amp\Http\Server\SocketHttpServer;
use CatPaw\Core\Bootstrap;
use CatPaw\Core\Container;
use CatPaw\Core\Directory;
use function CatPaw\Core\error;
use CatPaw\Core\File;
use function CatPaw\Core\isPhar;

use CatPaw\Core\None;
use function CatPaw\Core\ok;
use CatPaw\Core\Signal;
use CatPaw\Core\Unsafe;
use CatPaw\Web\Interfaces\FileServerInterface;
use CatPaw\Web\Interfaces\SessionInterface;
use Error;
use Phar;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

class Server {
    private static Server $singleton;
    /** @var array<callable(HttpServer):(void|Unsafe<void>)> */
    private static array $onStartListeners = [];

    /**
     * Invoke a function when the server starts.
     * @param  callable(HttpServer):(void|Unsafe<void>) $function the function to invoke, with the http server as parameter.
     * @return Unsafe<None>
     */
    public static function onStart(callable $function):Unsafe {
        self::$onStartListeners[] = $function;
        if (isset(self::$singleton) && isset(self::$singleton->httpServer) && self::$singleton->httpServerStarted) {
            $result = $function(self::$singleton->httpServer);
            if ($result instanceof Unsafe) {
                $result->try($error);
                if ($error) {
                    return error($error);
                }
            }
        }
        return ok();
    }

    /**
     *
     * @param  array<string> $www
     * @throws Error
     * @return string
     */
    private static function findFirstValidWebDirectory(array $www):string {
        if (isPhar()) {
            $phar = Phar::running();
            foreach ($www as $directory) {
                $directory = "$phar/$directory";
                if (File::exists($directory)) {
                    if (isDirectory($directory)) {
                        return $directory;
                    }
                }
            }
        } else {
            foreach ($www as $directory) {
                if (isDirectory($directory)) {
                    return $directory;
                }
            }
        }
        return '';
    }


    /**
     *
     * @param  array<string> $api
     * @throws Error
     * @return string
     */
    private static function findFirstValidRoutesDirectory(array $api) : string {
        foreach ($api as $directory) {
            if (File::exists($directory)) {
                if (isDirectory($directory)) {
                    return $directory;
                }
            }

            $isPhar = isPhar();
            $phar   = Phar::running();

            if ($isPhar) {
                $directory = "$phar/$directory";
                if (File::exists($directory)) {
                    if (isDirectory($directory)) {
                        return $directory;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Get the current server instance.
     * @return Unsafe<Server>
     */
    public static function get(): Unsafe {
        if (!isset(self::$singleton)) {
            return error("Server not initialized.");
        }
        return ok(self::$singleton);
    }

    /**
     *  Create a new server.
     * @param  string         $interface            network interface to bind to.
     * @param  string         $secureInterface      same as `$interfaces` but using secure certificates.
     * @param  string         $api                  api directory, this is relative to the project directory.
     * @param  string         $www                  static assets directory, this is relative to the project directory.
     * @param  string         $apiPrefix            a prefix to add to the api path.
     * @param  bool           $enableCompression
     * @param  int            $connectionLimit
     * @param  int            $connectionLimitPerIp
     * @param  int            $concurrencyLimit
     * @param  array<string>  $allowedMethods
     * @throws Error
     * @return Unsafe<Server>
     */
    public static function create(
        string $interface = '127.0.0.1:8080',
        string $secureInterface = '',
        string $api = './server/api/',
        string $www = './server/www/',
        string $apiPrefix = '/',
        bool $enableCompression = true,
        int $connectionLimit = 1000,
        int $connectionLimitPerIp = 10,
        int $concurrencyLimit = 1000,
        array $allowedMethods = [],
    ): Unsafe {
        if (isset(self::$singleton)) {
            return error('You can only have one server instance at any time. Use `Server::get()` to get the current instance.');
        }
        if (!str_starts_with($apiPrefix, '/')) {
            $apiPrefix = "/$apiPrefix";
        }
        $api = preg_replace('/\/+$/', '', $api);
        $www = preg_replace('/\/+$/', '', $www);


        $logger = Container::create(LoggerInterface::class)->try($error);
        if ($error) {
            return error($error);
        }

        if ((!$www = self::findFirstValidWebDirectory([$www]))) {
            $logger->warning("Could not find a valid web root directory.");
        }

        if ((!$api = self::findFirstValidRoutesDirectory([$api]))) {
            $logger->warning("Could not find a valid api directory.");
        }

        if (!Container::isProvided(SessionInterface::class)) {
            Container::provide(SessionInterface::class, SessionWithMemory::create(...));
        }

        return ok(self::$singleton = new self(
            interface        : $interface,
            secureInterface  : $secureInterface,
            apiPrefix        : $apiPrefix,
            api              : $api,
            www              : $www,
            enableCompression: $enableCompression,
            connectionLimit: $connectionLimit,
            connectionLimitPerIp: $connectionLimitPerIp,
            concurrencyLimit: $concurrencyLimit,
            allowedMethods: $allowedMethods,
            router           : Router::create(),
            logger           : $logger,
        ));
    }

    private SocketHttpServer $httpServer;
    private RouteResolver $resolver;
    private FileServerInterface $fileServer;
    /** @var array<Middleware> */
    private array $middleware       = [];
    private bool $httpServerStarted = false;

    /**
     *
     * @param string          $interface
     * @param string          $secureInterface
     * @param string          $apiPrefix
     * @param string          $api
     * @param string          $www
     * @param bool            $enableCompression
     * @param int             $connectionLimit
     * @param int             $connectionLimitPerIp
     * @param int             $concurrencyLimit
     * @param array<string>   $allowedMethods
     * @param Router          $router
     * @param LoggerInterface $logger
     */
    private function __construct(
        public readonly string $interface,
        public readonly string $secureInterface,
        public readonly string $apiPrefix,
        public readonly string $api,
        public readonly string $www,
        public readonly bool $enableCompression,
        public readonly int $connectionLimit,
        public readonly int $connectionLimitPerIp,
        public readonly int $concurrencyLimit,
        public readonly array $allowedMethods,
        public readonly Router $router,
        public readonly LoggerInterface $logger,
    ) {
        self::initializeRoutes(
            logger: $this->logger,
            router: $router,
            apiPrefix: $this->apiPrefix,
            api: $this->api,
        )->try($error);

        if ($error) {
            $logger->error((string)$error);
        }

        Bootstrap::onKill(function() {
            $this->stop();
        });

        $this->resolver = RouteResolver::create($this);
    }

    public function middleware(Middleware $middleware): void {
        $this->middleware[] = $middleware;
    }

    public function setFileServer(FileServerInterface $fileServer):self {
        $this->fileServer = $fileServer;
        return $this;
    }

    /**
     * Start the server.
     *
     * This method will resolve when `::stop` is invoked or one of the following signals is sent to the program `SIGHUP`, `SIGINT`, `SIGQUIT`, `SIGTERM`.
     * @param  false|Signal $ready the server will trigger this signal whenever it's ready to serve requests.
     * @return Unsafe<None>
     */
    public function start(false|Signal $ready = false):Unsafe {
        if (isset($this->httpServer)) {
            if ($this->httpServerStarted) {
                return error("Server already started.");
            }
            return error("Server already created.");
        }
        $endSignal = new DeferredFuture;
        try {
            if (!isset($this->fileServer)) {
                $fileServer = FileServer::create($this)->try($error);
                if ($error) {
                    return error($error);
                }
                $this->fileServer = $fileServer;
            }

            $stopper = function(string $callbackId) {
                EventLoop::cancel($callbackId);
                $this->stop();
                Bootstrap::kill();
            };

            EventLoop::onSignal(SIGHUP, $stopper);
            EventLoop::onSignal(SIGINT, $stopper);
            EventLoop::onSignal(SIGQUIT, $stopper);
            EventLoop::onSignal(SIGTERM, $stopper);

            $requestHandler   = ServerRequestHandler::create($this->logger, $this->fileServer, $this->resolver);
            $stackedHandler   = stackMiddleware($requestHandler, $this->middleware);
            $errorHandler     = ServerErrorHandler::create($this->logger);
            $this->httpServer = SocketHttpServer::createForDirectAccess(
                logger: $this->logger,
                enableCompression: $this->enableCompression,
                connectionLimit: $this->connectionLimit,
                connectionLimitPerIp: $this->connectionLimitPerIp,
                concurrencyLimit: $this->concurrencyLimit,
                allowedMethods: $this->allowedMethods?:null,
            );

            $this->httpServer->onStop(static function() use ($endSignal) {
                if (!$endSignal->isComplete()) {
                    $endSignal->complete();
                }
            });
            $this->httpServer->expose($this->interface);

            $this->httpServer->start($stackedHandler, $errorHandler);
            $this->httpServerStarted = true;
            if ($ready) {
                $ready->send();
            }

            foreach (self::$onStartListeners as $function) {
                $result = $function($this->httpServer);
                if ($result instanceof Unsafe) {
                    $result->try($error);
                    if ($error) {
                        return error($error);
                    }
                }
            }

            $endSignal->getFuture()->await();
            return ok();
        } catch (Throwable $e) {
            if (!$endSignal->isComplete()) {
                $endSignal->complete();
            }
            return error($e);
        }
    }

    /**
     * Stop the server.
     * @return Unsafe<None>
     */
    public function stop(): Unsafe {
        if (isset($this->httpServer)) {
            try {
                $this->httpServer->stop();
                return ok();
            } catch(CompositeException $e) {
                return error($e);
            }
        }
        return ok();
    }

    /**
     * ù
     * @param  LoggerInterface $logger
     * @param  Router          $router
     * @param  string          $apiPrefix
     * @param  string          $api
     * @return Unsafe<None>
     */
    private static function initializeRoutes(
        LoggerInterface $logger,
        Router $router,
        string $apiPrefix,
        string $api,
    ): Unsafe {
        if ($api) {
            $flatList = Directory::flat($api)->try($error);
            if ($error) {
                return error($error);
            }

            foreach ($flatList as $fileName) {
                if (!str_ends_with(strtolower($fileName), '.php')) {
                    continue;
                }
                $offset       = strpos($fileName, $api);
                $offset       = $offset?:0;
                $relativePath = substr($fileName, $offset + strlen($api));

                if (!str_starts_with($relativePath, '.'.DIRECTORY_SEPARATOR)) {
                    if ($handler = require_once $fileName) {
                        $fileName = preg_replace('/\.php$/i', '', preg_replace('/\.\/+/', '/', '.'.DIRECTORY_SEPARATOR.$relativePath));

                        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                            $fileName = preg_replace('/\\\\/', '/', $fileName);
                        }

                        if (!preg_match('/^(.*)(\.|\/)(.*)$/', $fileName, $matches)) {
                            $logger->error("Invalid api path for $fileName.", ["matches" => $matches]);
                            continue;
                        }

                        $symbolicPath   = $apiPrefix.$matches[1];
                        $symbolicPath   = preg_replace(['/^\/+/','/\/index$/'], ['/',''], $symbolicPath);
                        $symbolicMethod = strtoupper($matches[3] ?? 'get');

                        $routeExists = $router->routeExists($symbolicMethod, $symbolicPath);

                        if (!$routeExists) {
                            $cwd = dirname($api.$fileName)?:'';
                            $router->initialize($symbolicMethod, $symbolicPath, $handler, $cwd)->try($error);
                            if ($error) {
                                return error($error);
                            }
                        } else {
                            $logger->info("Route `$symbolicMethod $symbolicPath` already exists. Will not overwrite.");
                        }
                    }
                }
            }
        }
        return ok();
    }
}
