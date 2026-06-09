<?php

namespace Tests\Feature\Services;

use App\Services\OllamaService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaServiceTest extends TestCase
{
    public function test_lists_model_names_from_the_tags_endpoint(): void
    {
        Http::fake([
            '*/api/tags' => Http::response([
                'models' => [
                    ['name' => 'gemma4:e4b'],
                    ['name' => 'qwen2.5-coder:7b'],
                ],
            ]),
        ]);

        $models = OllamaService::new()->listModels('http://ai.example:11434');

        $this->assertSame(['gemma4:e4b', 'qwen2.5-coder:7b'], $models);
    }

    public function test_trims_trailing_slash_and_requests_the_tags_endpoint(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []])]);

        OllamaService::new()->listModels('http://ai.example:11434/');

        Http::assertSent(fn ($request) => $request->url() === 'http://ai.example:11434/api/tags');
    }

    public function test_returns_empty_list_when_no_models_present(): void
    {
        Http::fake(['*/api/tags' => Http::response(['models' => []])]);

        $models = OllamaService::new()->listModels('http://ai.example:11434');

        $this->assertSame([], $models);
    }

    public function test_propagates_a_connection_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(ConnectionException::class);

        OllamaService::new()->listModels('http://unreachable:11434');
    }
}
