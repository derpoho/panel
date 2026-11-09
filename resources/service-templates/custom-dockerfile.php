<?php

return [
    'name' => 'Your own Dockerfile',
    'category' => 'Apps',
    'description' => 'Builds the Dockerfile at the root of your repository, as is.',
    'icon' => 'docker',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => 'Dockerfile',
    'notes' => 'Folders your Dockerfile declares with VOLUME (like VOLUME /data) are kept between deploys, so put anything the app saves there. Everything else in the container starts fresh with each new version.',
    'env' => [],
];
