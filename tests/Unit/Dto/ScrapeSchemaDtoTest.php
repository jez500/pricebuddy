<?php

namespace Tests\Unit\Dto;

use App\Dto\Scraping\ScrapeSchemaDto;
use InvalidArgumentException;
use Tests\TestCase;

class ScrapeSchemaDtoTest extends TestCase
{
    public function test_from_array_hydrates_nested_match_definitions(): void
    {
        $dto = ScrapeSchemaDto::fromArray([
            'availability' => [
                'type' => 'selector',
                'value' => '.availability',
                'match' => [
                    'default' => 'in_stock',
                    'out_of_stock' => ['type' => 'regex', 'value' => 'Sold out'],
                ],
            ],
        ]);

        $this->assertSame('selector', $dto->fields['availability']->type);
        $this->assertSame('.availability', $dto->fields['availability']->value);
        $this->assertSame('in_stock', $dto->fields['availability']->match?->default);
        $this->assertSame('regex', $dto->fields['availability']->match?->rules['out_of_stock']->type);
    }

    public function test_from_json_hydrates_schema(): void
    {
        $dto = ScrapeSchemaDto::fromJson(json_encode([
            'title' => [
                'type' => 'css',
                'value' => 'h1',
                'prepend' => 'Title: ',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('css', $dto->fields['title']->type);
        $this->assertSame('h1', $dto->fields['title']->value);
        $this->assertSame('Title: ', $dto->fields['title']->prepend);
    }

    public function test_from_array_rejects_non_array_field_definitions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Scrape schema field [title] must be an array.');

        ScrapeSchemaDto::fromArray([
            'title' => 'not-an-array',
        ]);
    }
}
