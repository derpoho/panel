<?php

return [
    'name' => 'Micronaut',
    'category' => 'Apps',
    'description' => 'Java 21. Builds with the Gradle or Maven wrapper and runs the fat jar.',
    'icon' => 'openjdk',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM eclipse-temurin:21-jdk AS build
WORKDIR /src
COPY . .
RUN if [ -f gradlew ]; then chmod +x gradlew && ./gradlew --no-daemon -q shadowJar && cp "$(ls -S build/libs/*-all.jar | head -1)" /app.jar; \
    else chmod +x mvnw && ./mvnw -q -B -DskipTests package && cp "$(ls -S target/*.jar | grep -v '/original-' | head -1)" /app.jar; fi

FROM eclipse-temurin:21-jre
COPY --from=build /app.jar /app.jar
EXPOSE 8080
CMD ["sh", "-c", "exec java -Dmicronaut.server.host=0.0.0.0 -Dmicronaut.server.port=${PORT:-8080} -jar /app.jar"]
DOCKERFILE,
    ],
];
