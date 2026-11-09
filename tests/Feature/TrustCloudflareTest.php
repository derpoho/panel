<?php

use Illuminate\Support\Facades\Route;

beforeEach(fn () => Route::get('/__ip', fn () => request()->ip()));

test('through Cloudflare, the visitor address is the client IP', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '162.158.33.152'])
        ->withHeaders(['CF-Connecting-IP' => '203.0.113.9'])
        ->get('/__ip')
        ->assertSee('203.0.113.9');
});

test('from anywhere else, the header is ignored', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['CF-Connecting-IP' => '203.0.113.9'])
        ->get('/__ip')
        ->assertSee('198.51.100.7');

    // A Cloudflare edge with a nonsense header keeps the edge address.
    $this->withServerVariables(['REMOTE_ADDR' => '162.158.33.152'])
        ->withHeaders(['CF-Connecting-IP' => 'not-an-ip'])
        ->get('/__ip')
        ->assertSee('162.158.33.152');
});
