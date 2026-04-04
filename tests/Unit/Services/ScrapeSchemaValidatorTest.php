<?php

namespace Tests\Unit\Services;

use App\Dto\Scraping\ScrapeSchemaDto;
use App\Exceptions\ScrapeSchemaValidationException;
use App\Services\ScrapeSchemaValidator;
use Tests\TestCase;

class ScrapeSchemaValidatorTest extends TestCase
{
    public function test_valid_schema_passes_validation(): void
    {
        $schema = ScrapeSchemaDto::fromArray([
            'title' => [
                'type' => 'css',
                'value' => 'h1',
                'prepend' => 'Title: ',
            ],
            'availability' => [
                'type' => 'selector',
                'value' => '.availability',
                'match' => [
                    'default' => 'in_stock',
                    'out_of_stock' => ['type' => 'regex', 'value' => 'Sold out'],
                ],
            ],
        ]);

        $validated = (new ScrapeSchemaValidator)->validate($schema);

        $this->assertSame($schema, $validated);
    }

    public function test_invalid_schema_reports_actionable_errors(): void
    {
        $schema = ScrapeSchemaDto::fromArray([
            'title' => [
                'type' => 'bogus',
                'value' => '',
                'prepend' => 1,
            ],
            'availability' => [
                'type' => 'regex',
                'value' => '[invalid',
                'match' => [
                    'out_of_stock' => ['type' => 'regex', 'value' => '[broken'],
                ],
            ],
        ]);

        try {
            (new ScrapeSchemaValidator)->validate($schema);
            $this->fail('Expected ScrapeSchemaValidationException to be thrown.');
        } catch (ScrapeSchemaValidationException $exception) {
            $this->assertContains('title.type must be one of: selector, css, xpath, regex, json, schema_org', $exception->errors());
            $this->assertContains('title.value must be a non-empty string', $exception->errors());
            $this->assertContains('title.prepend must be a string or null', $exception->errors());
            $this->assertContains('availability.value must be a valid regular expression', $exception->errors());
            $this->assertContains('availability.match.out_of_stock.value must be a valid regular expression', $exception->errors());
        }
    }

    public function test_wrap_regex_preserves_escaped_tildes(): void
    {
        $validator = new ScrapeSchemaValidator;
        $method = new \ReflectionMethod($validator, 'wrapRegex');
        $method->setAccessible(true);

        $this->assertSame('~foo\~bar~i', $method->invoke($validator, 'foo\~bar'));
        $this->assertSame('~foo\~bar~i', $method->invoke($validator, 'foo~bar'));
    }
}
