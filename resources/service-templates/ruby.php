<?php

return [
    'name' => 'Ruby',
    'category' => 'Apps',
    'description' => 'Any Rack app on Ruby 3.3: bundles, then serves config.ru with Puma.',
    'icon' => 'ruby',
    'kind' => 'app',
    'port' => 9292,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Needs a Gemfile with puma in it and a config.ru.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM ruby:3.3-slim
RUN apt-get update && apt-get install -y --no-install-recommends build-essential libyaml-dev pkg-config && rm -rf /var/lib/apt/lists/*
ENV RACK_ENV=production BUNDLE_WITHOUT=development:test
WORKDIR /app
COPY Gemfile* ./
RUN bundle install
COPY . .
EXPOSE 9292
CMD ["sh", "-c", "exec bundle exec puma -b tcp://0.0.0.0:${PORT:-9292}"]
DOCKERFILE,
    ],
];
