<?php

return [
    'name' => 'Microsoft SQL Server',
    'category' => 'Databases',
    'description' => 'SQL Server 2022 on Linux, Express edition by default.',
    'icon' => 'database',
    'kind' => 'compose',
    'port' => 1433,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Connect as sa with the password below at 127.0.0.1,PORT. Needs an x86 machine with at least 2 GB of memory. Express is free for production; Developer is free but not for production. It only listens locally. Apps you deploy on this machine connect through {bridge} instead of 127.0.0.1.',
    'env' => [
        ['key' => 'ACCEPT_EULA', 'label' => 'I accept the SQL Server license terms (aka.ms/useterms)', 'accept' => true, 'required' => true],
        ['key' => 'MSSQL_SA_PASSWORD', 'label' => 'sa password', 'default' => '{random:32}', 'secret' => true, 'hint' => 'At least 8 characters, with upper and lower case letters and a number.'],
        ['key' => 'MSSQL_PID', 'label' => 'Edition', 'default' => 'Express', 'hint' => 'Express, Developer, Standard or Enterprise (the last two need a license).'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mssql:
    image: mcr.microsoft.com/mssql/server:2022-latest
    restart: unless-stopped
    environment:
      ACCEPT_EULA: ${ACCEPT_EULA}
      MSSQL_SA_PASSWORD: ${MSSQL_SA_PASSWORD}
      MSSQL_PID: ${MSSQL_PID}
    ports:
      - "127.0.0.1:${PORT}:1433"
      - "${DOCKER_BRIDGE}:${PORT}:1433"
    volumes:
      - data:/var/opt/mssql
volumes:
  data:
YAML,
    ],
];
