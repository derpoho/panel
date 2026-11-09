<?php

return [
    'name' => 'Sinatra',
    'category' => 'Apps',
    'description' => 'Ruby 3.3 running your Sinatra app with Puma, from config.ru or app.rb.',
    'icon' => 'ruby',
    'kind' => 'app',
    'port' => 4567,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Needs a Gemfile with sinatra and puma in it. Uses config.ru if you have one, otherwise runs app.rb.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM ruby:3.3-slim
RUN apt-get update && apt-get install -y --no-install-recommends build-essential libyaml-dev pkg-config && rm -rf /var/lib/apt/lists/*
ENV APP_ENV=production RACK_ENV=production BUNDLE_WITHOUT=development:test
WORKDIR /app
COPY Gemfile* ./
RUN bundle install
COPY . .
EXPOSE 4567
CMD ["sh", "-c", "if [ -f config.ru ]; then exec bundle exec puma -b tcp://0.0.0.0:${PORT:-4567}; else exec bundle exec ruby app.rb -o 0.0.0.0 -p ${PORT:-4567}; fi"]
DOCKERFILE,
    ],
];
