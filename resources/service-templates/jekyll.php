<?php

return [
    'name' => 'Jekyll',
    'category' => 'Apps',
    'description' => 'Builds your Jekyll site with Bundler and serves _site/ with Nginx.',
    'icon' => 'jekyll',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => false,
    'health' => '/',
    'notes' => 'Needs a Gemfile with jekyll in it. Uses the url and baseurl in your _config.yml.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM ruby:3.3 AS build
WORKDIR /site
COPY Gemfile* ./
RUN bundle install
COPY . .
RUN JEKYLL_ENV=production bundle exec jekyll build -d /out

FROM nginx:1.27-alpine
COPY --from=build /out /usr/share/nginx/html
DOCKERFILE,
    ],
];
