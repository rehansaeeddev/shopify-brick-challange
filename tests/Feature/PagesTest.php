<?php

namespace Tests\Feature;

use Tests\TestCase;

class PagesTest extends TestCase
{
    public function test_the_root_page_does_not_error_without_a_vite_build(): void
    {
        $this->get('/')->assertStatus(200);
    }

    public function test_the_privacy_policy_is_public(): void
    {
        $this->get('/privacy')
            ->assertStatus(200)
            ->assertSee('Privacy policy')
            ->assertSee('daily_scores')
            ->assertSee('shop/redact');
    }

    public function test_the_embedded_pages_render_with_a_frame_ancestors_policy(): void
    {
        foreach (['/shopify/app', '/shopify/app/leaderboard'] as $uri) {
            $this->get($uri)
                ->assertStatus(200)
                ->assertHeader(
                    'Content-Security-Policy',
                    'frame-ancestors https://admin.shopify.com https://*.myshopify.com;'
                );
        }
    }

    public function test_the_game_page_loads_the_external_game_script(): void
    {
        // The game lives in public/js/game.js so the Node tests can run against
        // the same file the browser loads.
        $this->get('/shopify/app')
            ->assertStatus(200)
            ->assertSee('js/game.js', escape: false);
    }
}
