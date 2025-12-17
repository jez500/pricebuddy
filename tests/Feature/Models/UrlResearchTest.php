<?php

namespace Tests\Feature\Models;

use App\Models\UrlResearch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_setter_sets_null_for_long_strings()
    {
        $shortImage = 'https://example.com/image.jpg';
        $urlResearch = UrlResearch::create([
            'url' => 'https://example.com/test',
            'image' => $shortImage,
        ]);
        $this->assertEquals($shortImage, $urlResearch->fresh()->image);

        // String exactly 1024 characters should be set to null
        $longImage = 'https://example.com/'.str_repeat('a', 1004);
        $urlResearch = UrlResearch::create([
            'url' => 'https://example.com/test2',
            'image' => $longImage,
        ]);
        $this->assertNull($urlResearch->fresh()->image);

        // String over 1024 characters should be set to null
        $veryLongImage = 'https://example.com/'.str_repeat('b', 2000);
        $urlResearch = UrlResearch::create([
            'url' => 'https://example.com/test3',
            'image' => $veryLongImage,
        ]);
        $this->assertNull($urlResearch->fresh()->image);

        // Null value should remain null
        $urlResearch = UrlResearch::create([
            'url' => 'https://example.com/test4',
            'image' => null,
        ]);
        $this->assertNull($urlResearch->fresh()->image);
    }

    public function test_url_setter_sets_null_for_long_strings()
    {
        $shortUrl = 'https://example.com/product';
        $urlResearch = UrlResearch::create([
            'url' => $shortUrl,
        ]);
        $this->assertEquals($shortUrl, $urlResearch->fresh()->url);

        // String exactly 1024 characters should be set to null
        $longUrl = 'https://example.com/'.str_repeat('a', 1004);

        $urlResearch = new UrlResearch([
            'url' => $longUrl,
        ]);
        $this->assertNull($urlResearch->url);
        // Can't save an empty url.
        $this->expectException(QueryException::class);
        $urlResearch->save();

        // String over 1024 characters should be set to null
        $longUrl = 'https://example.com/'.str_repeat('a', 2000);

        $urlResearch = new UrlResearch([
            'url' => $longUrl,
        ]);
        $this->assertNull($urlResearch->url);
        // Can't save an empty url.
        $this->expectException(QueryException::class);
        $urlResearch->save();
    }
}
