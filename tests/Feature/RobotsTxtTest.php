<?php

test('a panel asks search engines to stay out, where the web server serves no robots.txt of its own', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee("User-agent: *\nDisallow: /\n", false);
});
