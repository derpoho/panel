<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        // The website's front page on serveros.com; signing up on a panel without one.
        $response = $this->followingRedirects()->get(route('home'));

        $response->assertOk();
    }
}
