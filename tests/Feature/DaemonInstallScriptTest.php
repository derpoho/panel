<?php

test('the installer points at this panel and its release feed', function () {
    config()->set('daemon.install_url', 'https://panel.example.com/install.sh');
    config()->set('daemon.panel_url', 'https://panel.example.com/');
    config()->set('daemon.releases_url', 'https://releases.example.com');

    $script = $this->get('/install.sh')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

    expect($script)->toStartWith("#!/usr/bin/env bash\n")
        ->toContain('curl -fsSL https://panel.example.com/install.sh | sudo bash -s -- --token=ENROL_TOKEN')
        ->toContain('PANEL="${SERVEROS_PANEL:-https://panel.example.com}"')
        ->toContain('RELEASES="${SERVEROS_RELEASES:-https://releases.example.com}"')
        ->not->toContain('__SERVEROS_')
        ->not->toContain('serveros.com');
});

test('an address that could break out of the script is left out, so it asks for --panel', function () {
    config()->set('daemon.panel_url', 'https://x.example.com/"; rm -rf / #');

    expect($this->get('/install.sh')->assertOk()->getContent())
        ->toContain('PANEL="${SERVEROS_PANEL:-}"')
        ->toContain('[ -n "$PANEL" ] || fail "no panel address.');
});

test('a package can serve its own installer', function () {
    $own = tempnam(sys_get_temp_dir(), 'install');
    file_put_contents($own, "#!/bin/sh\necho ours\n");
    config()->set('daemon.install_script', $own);

    expect($this->get('/install.sh')->assertOk()->getContent())->toBe("#!/bin/sh\necho ours\n");
});
