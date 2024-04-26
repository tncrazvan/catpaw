<?php
namespace CatPaw\Superstyle;

use CatPaw\Ast\Block;
use CatPaw\Ast\CStyleAstSearch;
use CatPaw\Ast\Interfaces\CStyleDetectorInterface;
use function CatPaw\Core\error;

use CatPaw\Core\None;
use function CatPaw\Core\ok;

use CatPaw\Core\Unsafe;

class Superstyle {
    /**
     *
     * @param  string                           $fileName
     * @return Unsafe<SuperstyleExecutorResult>
     */
    public static function parse(string $fileName):Unsafe {
        $search = CStyleAstSearch::fromFile($fileName)->unwrap($error);
        if ($error) {
            return error($error);
        }

        /** @var array<string> */
        $globals = [];
        /** @var null|Block $main */
        $main = null;

        $search->detect(new class(globals: $globals, main: $main) implements CStyleDetectorInterface {
            /**
             *
             * @param  array<string> $globals
             * @param  null|Block    $main
             * @return void
             */
            public function __construct(
                // @phpstan-ignore-next-line
                private array &$globals,
                private null|Block &$main,
            ) {
            }

            /**
             *
             * @param  Block        $block
             * @param  int          $depth
             * @return Unsafe<None>
             */
            public function onBlock(Block $block, int $depth):Unsafe {
                if (0 === $block->depth && 'main' === $block->signature) {
                    if ($this->main) {
                        return error("Error multiple top level main blocks are not allowed.");
                    }
                    $this->main = $block;
                }
                return ok();
            }
            public function onGlobal(string $global):Unsafe {
                $this->globals[] = $global;
                return ok();
            }
        });

        if (!$main) {
            return error("A top level main block is required in order to render an application.");
        }

        $executor = new SuperstyleExecutor(block: $main);

        return $executor->execute();
    }
}
