<?php

namespace Tests\Unit\Services;

use App\Dto\Scraping\ScrapeSchemaDto;
use App\Services\ScrapeSchemaCompiler;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ScrapeSchemaCompilerTest extends TestCase
{
    public function test_compiler_resolves_all_supported_extractor_types(): void
    {
        $scraper = new class
        {
            public array $calls = [];

            public function getSelector(string $selector, string $method = 'text', array $args = []): Collection
            {
                $this->calls[] = ['getSelector', $selector, $method, $args];

                if ($selector === '.availability') {
                    return collect(['Sold out']);
                }

                return collect(['selector:' . $selector . ':' . $method . ':' . implode(',', $args)]);
            }

            public function getXpath(string $value): Collection
            {
                $this->calls[] = ['getXpath', $value];

                return collect(['xpath:' . $value]);
            }

            public function getRegex(string $value): Collection
            {
                $this->calls[] = ['getRegex', $value];

                return collect(['regex:' . $value]);
            }

            public function getJson(string $value): Collection
            {
                $this->calls[] = ['getJson', $value];

                return collect(['json:' . $value]);
            }

            public function getSchemaOrg(): Collection
            {
                $this->calls[] = ['getSchemaOrg'];

                return collect([
                    [
                        '@type' => 'Product',
                        'name' => 'Schema title',
                        'description' => 'Schema description',
                        'offers' => [
                            'price' => '19.99',
                            'availability' => 'OutOfStock',
                        ],
                    ],
                ]);
            }
        };

        $schema = ScrapeSchemaDto::fromArray([
            'title' => [
                'type' => 'css',
                'value' => 'h1|content',
                'prepend' => 'Title: ',
            ],
            'description' => [
                'type' => 'xpath',
                'value' => '//article/p',
            ],
            'price' => [
                'type' => 'regex',
                'value' => '\\$(\\d+\\.\\d{2})',
                'append' => ' USD',
            ],
            'json_field' => [
                'type' => 'json',
                'value' => 'data.price',
            ],
            'availability_text' => [
                'type' => 'selector',
                'value' => '.availability',
                'match' => [
                    'default' => 'in_stock',
                    'out_of_stock' => ['type' => 'regex', 'value' => 'Sold out'],
                ],
            ],
            'availability' => [
                'type' => 'schema_org',
                'value' => null,
            ],
        ]);

        $result = (new ScrapeSchemaCompiler)->fromDto($schema, $scraper);

        $this->assertSame('Title: selector:h1:attr:content', $result['title']['value']);
        $this->assertSame('xpath://article/p', $result['description']['value']);
        $this->assertSame('regex:~\\$(\\d+\\.\\d{2})~i USD', $result['price']['value']);
        $this->assertSame('json:data.price', $result['json_field']['value']);
        $this->assertSame('OutOfStock', $result['availability']['value']);
        $this->assertSame('Sold out', $result['availability_text']['value']);
        $this->assertSame('out_of_stock', $result['availability_text']['match']);
        $this->assertSame([
            'getSelector',
            'h1',
            'attr',
            ['content'],
        ], $scraper->calls[0]);
    }

    public function test_compiler_uses_default_match_when_no_rule_matches(): void
    {
        $scraper = new class
        {
            public function getSelector(string $selector, string $method = 'text', array $args = []): Collection
            {
                return collect(['available now']);
            }

            public function getSchemaOrg(): Collection
            {
                return collect([]);
            }
        };

        $schema = ScrapeSchemaDto::fromArray([
            'availability' => [
                'type' => 'selector',
                'value' => '.availability',
                'match' => [
                    'default' => 'in_stock',
                    'out_of_stock' => ['type' => 'match', 'value' => 'Sold out'],
                ],
            ],
        ]);

        $result = (new ScrapeSchemaCompiler)->fromDto($schema, $scraper);

        $this->assertSame('available now', $result['availability']['value']);
        $this->assertSame('in_stock', $result['availability']['match']);
    }

    public function test_compiler_skips_invalid_fields_without_failing_valid_ones(): void
    {
        $scraper = new class
        {
            public function getSelector(string $selector, string $method = 'text', array $args = []): Collection
            {
                return collect(['selector:' . $selector]);
            }
        };

        $schema = ScrapeSchemaDto::fromArray([
            'title' => [
                'type' => 'css',
                'value' => 'h1',
            ],
            'broken_field' => [
                'type' => 'regex',
                'value' => '[broken',
            ],
        ]);

        $result = (new ScrapeSchemaCompiler)->fromDto($schema, $scraper);

        $this->assertSame('selector:h1', $result['title']['value']);
        $this->assertNull($result['broken_field']['value']);
        $this->assertNull($result['broken_field']['match']);
    }
}
