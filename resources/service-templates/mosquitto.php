<?php

return [
    'name' => 'Mosquitto',
    'category' => 'Infrastructure',
    'description' => 'MQTT broker for IoT devices and messaging, with a username and password.',
    'icon' => 'mosquitto',
    'kind' => 'compose',
    'port' => 1883,
    'web' => false,
    'port_editable' => true,
    'health' => null,
    'notes' => 'Devices connect to mqtt://USER:PASSWORD@THIS_MACHINE:PORT. The port is open to the internet.',
    'env' => [
        ['key' => 'MQTT_USER', 'label' => 'User', 'default' => 'mqtt'],
        ['key' => 'MQTT_PASSWORD', 'label' => 'Password', 'default' => '{random:24}', 'secret' => true],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  mosquitto:
    image: eclipse-mosquitto:2
    restart: unless-stopped
    environment:
      MQTT_USER: ${MQTT_USER}
      MQTT_PASSWORD: ${MQTT_PASSWORD}
    # Write the config and password file from the settings, then start.
    command:
      - sh
      - -c
      - |
        cat > /mosquitto/config/mosquitto.conf <<'CONF'
        listener 1883
        allow_anonymous false
        password_file /mosquitto/data/passwd
        persistence true
        persistence_location /mosquitto/data/
        log_dest stdout
        CONF
        mosquitto_passwd -b -c /mosquitto/data/passwd "$$MQTT_USER" "$$MQTT_PASSWORD"
        chown mosquitto:mosquitto /mosquitto/data/passwd
        chmod 0700 /mosquitto/data/passwd
        exec mosquitto -c /mosquitto/config/mosquitto.conf
    ports:
      - "${PORT}:1883"
    volumes:
      - data:/mosquitto/data
volumes:
  data:
YAML,
    ],
];
