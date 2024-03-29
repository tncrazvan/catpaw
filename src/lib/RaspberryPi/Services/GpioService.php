<?php

namespace CatPaw\RaspberryPi\Services;

use CatPaw\Core\Attributes\Service;

use CatPaw\Core\Directory;
use function CatPaw\Core\error;
use CatPaw\Core\File;
use CatPaw\Core\Unsafe;
use CatPaw\RaspberryPi\Interfaces\GpioReader;
use CatPaw\RaspberryPi\Interfaces\GpioWriter;

#[Service]
class GpioService {
    private const READ        = 0;
    private const WRITE       = 1;
    private const HEADER7     = 4;
    private const HEADER11    = 17;
    private const HEADER12    = 18;
    private const HEADER13rv1 = 21;
    private const HEADER13rv2 = 27;
    private const HEADER15    = 22;
    private const HEADER16    = 23;
    private const HEADER18    = 24;
    private const HEADER22    = 25;

    /**
     * Export the pin and return its file handler.
     * @param  string       $pin       can be one of the following: `7`,`11`,`12`,`13rv1`,`13`,`13rv2`,`15`,`16`,`18`,`22`.
     * @param  int          $direction direction of the pin, `0` means `read` and `1` means `write`.
     * @return Unsafe<File>
     */
    private function export(string $pin, int $direction): Unsafe {
        $originalPin = $pin;
        $pin         = match ($pin) {
            '7'     => self::HEADER7,
            '11'    => self::HEADER11,
            '12'    => self::HEADER12,
            '13rv1' => self::HEADER13rv1,
            '13rv2',
            '13'    => self::HEADER13rv2,
            '15'    => self::HEADER15,
            '16'    => self::HEADER16,
            '18'    => self::HEADER18,
            '22'    => self::HEADER22,
            default => -1,
        };

        if (-1 === $pin) {
            return error("Pin name must be one of the following: `7`,`11`,`12`,`13rv1`,`13`,`13rv2`,`15`,`16`,`18`,`22`. Received '$originalPin'.");
        }

        // execute('echo "'.$pin.'" > /sys/class/gpio/export');
        $exportFile = File::open('/sys/class/gpio/export', 'a')->try($error);
        if ($error) {
            return error($error);
        }
        
        $exportFile->write($pin);
        $exportFile->close();

        if (!File::exists("/sys/class/gpio/gpio$pin/direction")) {
            Directory::create("/sys/class/gpio/gpio$pin")->try($error);
            if ($error) {
                return error($error);
            }
            $directionFile = File::open("/sys/class/gpio/gpio$pin/direction", 'w')->try($error);
            if ($error) {
                return error($error);
            }
            $directionFile->write('')->await()->try($error);
            if ($error) {
                return error($error);
            }
            $directionFile->close();
        }

        $directionFile = File::open("/sys/class/gpio/gpio$pin/direction", 'a')->try($error);
        if ($error) {
            return error($error);
        }
        $directionFile->write($direction > 0 ? 'out' : 'in');
        $directionFile->close();


        if (!File::exists("/sys/class/gpio/gpio$pin/value")) {
            Directory::create("/sys/class/gpio/gpio$pin")->try($error);
            if ($error) {
                return error($error);
            }
            $valueFile = File::open("/sys/class/gpio/gpio$pin/value", 'w')->try($error);
            if ($error) {
                return error($error);
            }
            $valueFile->write('')->await()->try($error);
            if ($error) {
                return error($error);
            }
            $valueFile->close();
        }

        return File::open("/sys/class/gpio/gpio$pin/value", $direction > 0 ? 'a' : 'r');
    }

    /**
     * Create a pin reader.
     * @param  string     $pin can be one of the following: `7`,`11`,`12`,`13rv1`,`13`,`13rv2`,`15`,`16`,`18`,`22`.
     * @return GpioReader
     */
    public function createReader(string $pin):GpioReader {
        $export = fn () => $this->export($pin, self::READ);
        return new class($export) implements GpioReader {
            private File|false $file = false;
            /**
             * 
             * @param  callable():Unsafe<File> $export
             * @return void
             */
            public function __construct(private $export) {
            }
            
            public function read():Unsafe {
                if (!$this->file) {
                    $export = $this->export;
                    $file   = $export()->try($error);
                    if ($error) {
                        return error($error);
                    }
                    $this->file = $file;
                }
                return $this->file->read()->await();
            }

            public function close():void {
                $this->file->close();
            }
        };
    }

    /**
     * Create a pin writer.
     * @param  string     $pin can be one of the following: `7`,`11`,`12`,`13rv1`,`13`,`13rv2`,`15`,`16`,`18`,`22`.
     * @return GpioWriter
     */
    public function createWriter(string $pin):GpioWriter {
        $export = fn () => $this->export($pin, self::WRITE);
        return new class($export) implements GpioWriter {
            private File|false $file = false;
            /**
             * 
             * @param  callable():Unsafe<File> $export
             * @return void
             */
            public function __construct(private $export) {
            }
            
            public function write(string $data):Unsafe {
                if (!$this->file) {
                    $export = $this->export;
                    $file   = $export()->try($error);
                    if ($error) {
                        return error($error);
                    }
                    $this->file = $file;
                }
                return $this->file->write($data)->await();
            }

            public function close():void {
                $this->file->close();
            }
        };
    }
}
