<?php
namespace CatPaw\Web;

use function Amp\async;
use Amp\CompositeException;
use Amp\DeferredFuture;

use function Amp\File\isDirectory;
use Amp\Future;
use Amp\Http\Server\Middleware;

use function Amp\Http\Server\Middleware\stackMiddleware;
use Amp\Http\Server\SocketHttpServer;

use CatPaw\Core\Bootstrap;
use CatPaw\Core\Container;
use CatPaw\Core\Directory;
use function CatPaw\Core\error;
use CatPaw\Core\File;
use function CatPaw\Core\isPhar;

use function CatPaw\Core\ok;
use CatPaw\Core\Signal;

use CatPaw\Core\Unsafe;
use CatPaw\Web\Interfaces\FileServerInterface;
use Phar;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

class Server {
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
     * 
     * @param  string         $interface       network interface to bind to.
     * @param  string         $secureInterface same as `$interfaces` but using secure certificates.
     * @param  string         $api             api directory, this is relative to the project directory.
     * @param  string         $www             static assets directory, this is relative to the project directory.
     * @param  string         $apiPrefix       a prefix to add to the api path.
     * @return Unsafe<Server>
     */
    public static function create(
        string $interface = '127.0.0.1:8080',
        string $secureInterface = '',
        string $api = './server/api/',
        string $www = './server/www/',
        string $apiPrefix = '',
        false|SessionOperationsInterface $sessionOperations = false,
    ): Unsafe {
        if (!str_starts_with($api, './')) {
            $api = "./$api";
            // return error("The api directory must be a relative path and within the project directory.");
        }
        if (!str_starts_with($www, './')) {
            $www = "./$www";
            // return error("The web root directory must be a relative path and within the project directory.");
        }
        $api = preg_replace('/\/+$/', '', $api);
        $www = preg_replace('/\/+$/', '', $www);

        
        $logger = Container::create(LoggerInterface::class)->try($error);
        if ($error) {
            return error($error);
        }

        if (!str_starts_with($apiPrefix, "/")) {
            $apiPrefix = "/$apiPrefix";
        }

        if ((!$www = self::findFirstValidWebDirectory([$www]))) {
            $logger->warning("Could not find a valid web root directory.");
        }
        
        if ((!$api = self::findFirstValidRoutesDirectory([$api]))) {
            $logger->warning("Could not find a valid api directory.");
        }

        if (!$sessionOperations) {
            $sessionOperations = FileSystemSessionOperations::create(
                ttl          : 1_440,
                directoryName: ".sessions",
                keepAlive    : false,
            );
        }

        return ok(new self(
            interface        : $interface,
            secureInterface  : $secureInterface,
            apiPrefix        : $apiPrefix,
            api              : $api,
            www              : $www,
            router           : Router::create(),
            logger           : $logger,
            sessionOperations: $sessionOperations,
        ));
    }

    private SocketHttpServer $server;
    private RouteResolver $resolver;
    private FileServerInterface $fileServer;
    /** @var array<Middleware> */
    private array $middlewares = [];

    private function __construct(
        public readonly string $interface,
        public readonly string $secureInterface,
        public readonly string $apiPrefix,
        public readonly string $api,
        public readonly string $www,
        public readonly Router $router,
        public readonly LoggerInterface $logger,
        public readonly SessionOperationsInterface $sessionOperations,
    ) {
        self::initializeRoutes(
            logger: $this->logger,
            router: $router,
            apiPrefix: $this->apiPrefix,
            api: $this->api,
        )->try($error);

        if ($error) {
            $logger->error($error->getMessage());
        }

        Bootstrap::onKill(function() {
            $this->stop();
        });

        $this->resolver = RouteResolver::create($this);
    }

    public function appendMiddleware(Middleware $middleware): void {
        $this->middlewares[] = $middleware;
    }

    public function setFileServer(FileServerInterface $fileServer):self {
        $this->fileServer = $fileServer;
        return $this;
    }

    /**
     * Start the server.
     * 
     * This method will resolve when `::stop` is invoked or one of the following signals is sent to the program `SIGHUP`, `SIGINT`, `SIGQUIT`, `SIGTERM`.
     * @param  false|Signal         $signal the server will trigger this signal whenever it's ready to serve requests.
     * @return Future<Unsafe<void>>
     */
    public function start(false|Signal $signal = false):Future {
        return async(function() use ($signal) {
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
    
                $logger = Container::create(LoggerInterface::class)->try($error);
                if ($error) {
                    return error($error);
                }
                
                $requestHandler = ServerRequestHandler::create($logger, $this->fileServer, $this->resolver);
                $stackedHandler = stackMiddleware($requestHandler, ...$this->middlewares);
                $errorHandler   = ServerErrorHandler::create($logger);
                $this->server   = SocketHttpServer::createForDirectAccess($logger);
                $this->server->onStop(static function() use ($endSignal) {
                    if (!$endSignal->isComplete()) {
                        $endSignal->complete();
                    }
                });
                $this->server->expose($this->interface);
                $this->server->start($stackedHandler, $errorHandler);
                if ($signal) {
                    $signal->sigterm();
                }
                $endSignal->getFuture()->await();
                return ok();
            } catch (Throwable $e) {
                if (!$endSignal->isComplete()) {
                    $endSignal->complete();
                }
                return error($e);
            }
        });
    }

    /**
     * Stop the server.
     * @return Unsafe<void>
     */
    public function stop(): Unsafe {
        if (isset($this->server)) {
            try {
                $this->server->stop();
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
     * @return Unsafe<void>
     */
    private static function initializeRoutes(
        LoggerInterface $logger,
        Router $router,
        string $apiPrefix,
        string $api,
    ): Unsafe {
        if ($api) {
            if (isPhar()) {
                $api = Phar::running()."/$api";
            }
            
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

                        $symbolicMethod = preg_replace('/^\\//', '', strtoupper(preg_replace('/^.*(?=\/)/', '', $fileName)));
                        $symbolicPath   = preg_replace('/\\/$/', '', preg_replace('/(?<=\/)[^\/]*$/', '', "$apiPrefix$fileName"))?:'/';

                        $routeExists = $router->routeExists($symbolicMethod, $symbolicPath);

                        if (!$routeExists) {
                            $cwd = dirname($api.$fileName)?:'';
                            $router->initialize($symbolicMethod, $symbolicPath, $handler, $cwd)->try($error);
                            if ($error) {
                                return error($error);
                            }
                        } else {
                            $logger->info("Route $symbolicMethod $symbolicPath already exists. Will not overwrite.");
                        }
                    }
                }
            }
        }
        return ok();
    }
}