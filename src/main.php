<?php
use Amp\ByteStream\ClosedException;
use function CatPaw\Core\anyError;
use CatPaw\Core\Attributes\Option;
use function CatPaw\Core\Build\build;
use CatPaw\Core\Container;
use function CatPaw\Core\error;
use CatPaw\Core\File;
use function CatPaw\Core\ok;
use function CatPaw\Core\out;
use CatPaw\Core\Unsafe;
use CatPaw\Cui\C\CuiContract;
use CatPaw\Cui\Services\CuiService;
use function CatPaw\Text\foreground;
use function CatPaw\Text\nocolor;

/**
 * @param  bool            $tips
 * @param  bool            $hi
 * @param  bool            $build
 * @param  bool            $buildOptimize
 * @throws ClosedException
 * @return Unsafe<void>
 */
function main(
    // ===> TIPS
    #[Option("--tips")]
    bool $tips,

    // ===> Hi
    #[Option("--hi")]
    bool $hi,

    // ===> BUILD
    #[Option("--build")]
    bool $build = false,
    #[Option("--build-optimize")]
    bool $buildOptimize = false,
): Unsafe {
    return anyError(fn () => match (true) {
        $build  => build(buildOptimize:$buildOptimize),
        $tips   => tips(),
        $hi     => hi(),
        default => true,
    });
}

function hi():Unsafe {
    $cui = Container::create(CuiService::class)->try($error);
    if ($error) {
        return error($error);
    }

    $cui->load()->try($error);
    if ($error) {
        return error($error);
    }

    /** @var CuiContract $lib */
    $cui->loop(function($lib) {
        $maxX = $lib->MaxX();
        $maxY = $lib->MaxY();

        $message = "hello";
        $len     = strlen($message);

        $x0 = ($maxX / 2) - ($len / 2);
        $y0 = ($maxY / 2) - 1;
        $x1 = ($maxX / 2) + ($len / 2) + 1;
        $y1 = ($maxY / 2) + 1;
        if ($view = $lib->NewView("main", $x0, $y0, $x1, $y1)) {
            $lib->Fprintln($view, $message);
        }
    });


    return ok();
}

function tips() {
    try {
        $message = '';

        if (
            File::exists('.git/hooks')
            && !File::exists('.git/hooks/pre-commit')
        ) {
            $message = join([
                foreground(170, 140, 40),
                "Remember to run `",
                foreground(140, 170, 40),
                "composer dev:precommit",
                foreground(170, 140, 40),
                "` if you want to sanitize your code before committing.",
                nocolor(),
                PHP_EOL,
            ]);
        }

        out()->write($message);
        return ok();
    } catch (\Throwable $error) {
        return error($error);
    }
}
