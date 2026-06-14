<?php

namespace Tests\Unit\Casts;

use App\Casts\StoreScraperStrategySetCast;
use App\Dto\StoreScraperStrategySetDto;
use App\Models\Store;
use PHPUnit\Framework\TestCase;

class StoreScraperStrategySetCastTest extends TestCase
{
    private StoreScraperStrategySetCast $cast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new StoreScraperStrategySetCast;
    }

    public function test_get_returns_dto_from_json(): void
    {
        $json = json_encode(['title' => ['type' => 'selector', 'value' => 'h1']]);

        $dto = $this->cast->get(new Store, 'scrape_strategy', $json, []);

        $this->assertInstanceOf(StoreScraperStrategySetDto::class, $dto);
        $this->assertSame('h1', $dto->title->value);
    }

    public function test_get_returns_empty_dto_for_null(): void
    {
        $dto = $this->cast->get(new Store, 'scrape_strategy', null, []);

        $this->assertInstanceOf(StoreScraperStrategySetDto::class, $dto);
        $this->assertNull($dto->title);
    }

    public function test_set_accepts_array_and_dto_identically(): void
    {
        $array = ['title' => ['type' => 'selector', 'value' => 'h1']];
        $dto = StoreScraperStrategySetDto::fromArray($array);

        $fromArray = $this->cast->set(new Store, 'scrape_strategy', $array, []);
        $fromDto = $this->cast->set(new Store, 'scrape_strategy', $dto, []);

        $this->assertSame($fromArray, $fromDto);
        $this->assertSame(json_encode(['title' => ['type' => 'selector', 'value' => 'h1']]), $fromArray);
    }

    public function test_set_handles_null(): void
    {
        $this->assertNull($this->cast->set(new Store, 'scrape_strategy', null, []));
    }
}
