<?php
namespace CatPaw\Web\Attributes;

use Attribute;
use CatPaw\Core\Attributes\Entry;
use function CatPaw\Core\error;
use CatPaw\Core\Interfaces\AttributeInterface;
use function CatPaw\Core\ok;
use CatPaw\Core\Traits\CoreAttributeDefinition;
use CatPaw\Core\Unsafe;

use CatPaw\Web\ConsumedRequest;
use CatPaw\Web\Services\OpenApiService;

/**
 * Define the type of content the route handler consumes.
 * 
 * Some examples:
 * 
 * - `#[Consumes("string", "application/json")]`
 * - `#[Consumes("string", "text/plain")]`
 * 
 * ### Note
 * Specifically the type `"application/json"` will allow object and array mappings using `#[Body]`.
 * @see Body
 * @package CatPaw\Web\Attributes
 */
#[Attribute]
class Consumes implements AttributeInterface {
    use CoreAttributeDefinition;

    /** @var array<ConsumedRequest> */
    private array $request = [];
    

    /**
     * @param string|array $schema       usually `string`, but can also be a class name to indicate the structure of the content.
     * @param string|array $contentTypes the http content-type, like `application/json`, `text/html` etc.
     * @param mixed        $example
     */
    public function __construct(
        string|array $schema = 'string',
        string|array $contentTypes = 'application/json',
        mixed $example = '',
    ) {
        if (is_string($contentTypes)) {
            $contentTypes = [$contentTypes];
        }

        foreach ($contentTypes as $contentType) {
            $this->request[] = ConsumedRequest::create(
                className  : $schema,
                type       : $contentType,
                example    : $example,
            );
        }
    }

    #[Entry] public function setup(OpenApiService $oa):Unsafe {
        foreach ($this->request as $request) {
            $request->setup($oa)->try($error);
            if ($error) {
                return error($error);
            }
        }
        return ok();
    }

    /**
     * Get the types of content available to consume.
     *
     * @return array<string>
     */
    public function getContentType(): array {
        $contentType = [];
        foreach ($this->request as $request) {
            $contentType[] = $request->getContentType();
        }
        return $contentType;
    }

    /**
     * Get the shaped responses available to consume.
     *
     * @return array<ConsumedRequest>
     */
    public function getRequest():array {
        return $this->request;
    }
}