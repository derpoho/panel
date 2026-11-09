<?php

return [
    'name' => 'Spring Boot',
    'category' => 'Apps',
    'description' => 'Java 21. Builds with the Maven or Gradle wrapper and runs the jar.',
    'icon' => 'spring',
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
RUN if [ -f mvnw ]; then chmod +x mvnw && ./mvnw -q -DskipTests package && cp target/*.jar /app.jar; \
    else chmod +x gradlew && ./gradlew -q bootJar && cp build/libs/*[!n].jar /app.jar; fi

FROM eclipse-temurin:21-jre
COPY --from=build /app.jar /app.jar
EXPOSE 8080
CMD ["sh", "-c", "exec java -Dserver.port=${PORT:-8080} -jar /app.jar"]
DOCKERFILE,
    ],
];
