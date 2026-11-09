<?php

return [
    'name' => '.NET',
    'category' => 'Apps',
    'description' => '.NET 8. Publishes your project and runs it with ASP.NET Core.',
    'icon' => 'dotnet',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM mcr.microsoft.com/dotnet/sdk:8.0 AS build
WORKDIR /src
COPY . .
RUN dotnet publish -c Release -o /out

FROM mcr.microsoft.com/dotnet/aspnet:8.0
WORKDIR /app
COPY --from=build /out .
EXPOSE 8080
CMD ["sh", "-c", "ASPNETCORE_HTTP_PORTS=${PORT:-8080} exec dotnet $(ls *.runtimeconfig.json | head -n1 | sed 's/.runtimeconfig.json$/.dll/')"]
DOCKERFILE,
    ],
];
