<?php

return [
    'name' => 'Ruby on Rails',
    'category' => 'Apps',
    'description' => 'Ruby 3.3, bundles, precompiles assets and migrates on boot.',
    'icon' => 'rails',
    'kind' => 'app',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/up',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key base', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'RAILS_ENV', 'label' => 'Environment', 'default' => 'production'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM ruby:3.3-slim
RUN apt-get update && apt-get install -y --no-install-recommends build-essential git libpq-dev libyaml-dev pkg-config && rm -rf /var/lib/apt/lists/*
ENV RAILS_ENV=production BUNDLE_WITHOUT=development:test RAILS_SERVE_STATIC_FILES=1 RAILS_LOG_TO_STDOUT=1
WORKDIR /app
COPY Gemfile Gemfile.lock ./
RUN bundle install
COPY . .
RUN SECRET_KEY_BASE_DUMMY=1 bundle exec rails assets:precompile || true
EXPOSE 3000
CMD ["sh", "-c", "bundle exec rails db:prepare && bundle exec rails server -b 0.0.0.0 -p ${PORT:-3000}"]
DOCKERFILE,
    ],
];
