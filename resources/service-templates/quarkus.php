<?php

return [
    'name' => 'Quarkus',
    'category' => 'Apps',
    'description' => 'Java 21. Builds with the Maven or Gradle wrapper and runs the Quarkus fast-jar.',
    'icon' => 'quarkus',
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
RUN if [ -f mvnw ]; then chmod +x mvnw && ./mvnw -q -B -DskipTests package && cp -r target/quarkus-app /quarkus-app; \
    else chmod +x gradlew && ./gradlew --no-daemon -q build -x test && cp -r build/quarkus-app /quarkus-app; fi

FROM eclipse-temurin:21-jre
WORKDIR /app
COPY --from=build /quarkus-app /app
EXPOSE 8080
CMD ["sh", "-c", "exec java -Dquarkus.http.host=0.0.0.0 -Dquarkus.http.port=${PORT:-8080} -jar /app/quarkus-run.jar"]
DOCKERFILE,
    ],
];
