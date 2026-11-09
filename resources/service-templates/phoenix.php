<?php

return [
    'name' => 'Phoenix',
    'category' => 'Apps',
    'description' => 'Elixir 1.17 Phoenix app: builds assets, runs migrations, then the server.',
    'icon' => 'phoenix',
    'kind' => 'app',
    'port' => 4000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'notes' => 'Using Ecto? Add DATABASE_URL=ecto://USER:PASSWORD@HOST/DATABASE under extra environment.',
    'env' => [
        ['key' => 'SECRET_KEY_BASE', 'label' => 'Secret key base', 'default' => '{random:64}', 'secret' => true],
        ['key' => 'PHX_HOST', 'label' => 'Host', 'default' => 'localhost', 'hint' => 'The domain the app is served on, like app.example.com.'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM elixir:1.17
RUN mix local.hex --force && mix local.rebar --force
WORKDIR /app
ENV MIX_ENV=prod
COPY mix.exs mix.lock* ./
COPY config config
RUN mix deps.get --only prod && mix deps.compile
COPY . .
RUN mix compile && (mix assets.deploy || true)
ENV PHX_SERVER=true PORT=4000
EXPOSE 4000
CMD ["sh", "-c", "(mix ecto.migrate || true) && exec mix phx.server"]
DOCKERFILE,
    ],
];
