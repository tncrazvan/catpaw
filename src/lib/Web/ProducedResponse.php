<?php
namespace CatPaw\Web;

use CatPaw\Core\Attributes\Entry;
use function CatPaw\Core\error;
use CatPaw\Core\Interfaces\AttributeInterface;
use CatPaw\Core\None;

use function CatPaw\Core\ok;
use CatPaw\Core\Traits\CoreAttributeDefinition;
use CatPaw\Core\Unsafe;
use CatPaw\Web\Interfaces\OpenApiStateInterface;

class ProducedResponse implements AttributeInterface {
    use CoreAttributeDefinition;
    use SchemaEncoder;

    public static function create(
        string $type,
        int $status,
        string $className,
        string $description,
        mixed $example,
        bool $isPage,
        bool $isItem,
        bool $isErrorItem,
    ):self {
        return new self(
            type: $type,
            status: $status,
            className: $className,
            description: $description,
            example: $example,
            isPage: $isPage,
            isItem: $isItem,
            isErrorItem: $isErrorItem,
        );
    }

    /** @var array<mixed> */
    private array $response = [];

    /**
     *
     * @param string $type        http content type
     * @param int    $status      http status code
     * @param string $className
     * @param string $description
     * @param mixed  $example     an example of the body of the response
     * @param bool   $isPage      if set to true, the produced response will be wrapped in a page structure.
     * @param bool   $isItem      if set to true, the produced response will be wrapped in an item structure with type `item`.
     * @param bool   $isErrorItem if set to true, the produced response will be wrapped in an item structure with type `error` instead of `item`.
     */
    private function __construct(
        private readonly string $type,
        private readonly int $status,
        private readonly string $className,
        private readonly string $description,
        private mixed $example,
        private readonly bool $isPage,
        private readonly bool $isItem,
        private readonly bool $isErrorItem,
    ) {
        if ($isItem) {
            $converted     = is_array($this->example) || is_object($this->example)?(object)$this->example:$this->example;
            $this->example = (object)[
                'type'    => 'item',
                'status'  => $status,
                'message' => HttpStatus::getReason($status),
                'data'    => $converted,
            ];
        } else if ($isErrorItem) {
            $this->example = (object)[
                'type'    => 'error',
                'status'  => $status,
                'message' => HttpStatus::getReason($status),
            ];
        } else if ($isPage) {
            $converted     = is_array($this->example) || is_object($this->example)?(object)$this->example:$this->example;
            $this->example = (object)[
                'type'         => 'page',
                'status'       => $status,
                'message'      => HttpStatus::getReason($status),
                'previousHref' => 'http://example.com?start0&size=3',
                'nextHref'     => 'http://example.com?start6&size=3',
                'previous'     => [
                    'start' => 0,
                    'size'  => 3,
                ],
                'next' => [
                    'start' => 6,
                    'size'  => 3,
                ],
                'data' => [
                    $converted,
                ],
            ];
        } else {
            $this->example = HttpStatus::getReason($status);
        }
    }

    public function getStatus():int {
        return $this->status;
    }

    public function getContentType():string {
        return $this->type;
    }

    /**
     *
     * @return array<mixed>
     */
    public function getValue():array {
        return $this->response;
    }

    public function getClassName():string {
        return $this->className;
    }

    /**
     *
     * @param  OpenApiStateInterface $openApiState
     * @return Unsafe<None>
     */
    #[Entry] public function setup(OpenApiStateInterface $openApiState):Unsafe {
        $isClass   = class_exists($this->className);
        $reference = false;
        if ($isClass) {
            if ($this->isPage) {
                $reference = $openApiState->setComponentReferencePage($this->className);
            } else if ($this->isItem) {
                $reference = $openApiState->setComponentReferenceItem($this->className);
            }
            $openApiState->setComponentObject($this->className)->unwrap($error);
            if ($error) {
                return error($error);
            }
        }

        if ($isClass && $reference) {
            $schema = [
                'type' => 'object',
                '$ref' => $reference,
            ];
        } else {
            if ('array' === $this->className) {
                $schema = [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ];
            } else {
                $type = match ($this->className) {
                    'int'   => 'integer',
                    'float' => 'number',
                    'bool'  => 'boolean',
                    default => $this->className,
                };
                if ($this->isItem) {
                    $schema = $openApiState->templateForItem(className:$type, dataIsObject:false);
                } else if ($this->isErrorItem) {
                    $openApiState->setComponentReference(ErrorItem::class);
                    $openApiState->setComponentObject(ErrorItem::class)->unwrap($setError);
                    if ($setError) {
                        return error($setError);
                    }

                    $schema = $openApiState->templateForObjectComponent(className:ErrorItem::class);
                } else if ($this->isPage) {
                    $schema = $openApiState->templateForPage(className:$type, dataIsObject:false);
                } else {
                    $schema = [
                        'type' => $type,
                    ];
                }
            }
        }

        $this->response = $openApiState->createResponse(
            status: $this->status,
            description: $this->description,
            contentType: $this->type,
            schema: $schema,
            example: $this->example,
        );

        return ok();
    }
}
