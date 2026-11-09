<?php

return [
    'name' => 'Ktor',
    'category' => 'Apps',
    'description' => 'Kotlin on Java 21. Builds a fat jar with the Gradle wrapper and runs it.',
    'icon' => 'ktor',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Uses the Ktor Gradle plugin\'s buildFatJar task. Projects from the Ktor generator read PORT already; with embeddedServer, read it yourself and bind 0.0.0.0.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM eclipse-temurin:21-jdk AS build
WORKDIR /src
COPY . .
RUN if [ -f gradlew ]; then chmod +x gradlew && ./gradlew --no-daemon -q buildFatJar && cp "$(ls -S build/libs/*.jar | head -1)" /app.jar; \
    else chmod +x mvnw && ./mvnw -q -B -DskipTests package && cp "$(ls -S target/*.jar | head -1)" /app.jar; fi

FROM eclipse-temurin:21-jre
COPY --from=build /app.jar /app.jar
ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "exec java -jar /app.jar -port=${PORT:-8080}"]
DOCKERFILE,
    ],
];
