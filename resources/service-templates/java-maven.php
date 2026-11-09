<?php

return [
    'name' => 'Java (Maven)',
    'category' => 'Apps',
    'description' => 'Java 21. Builds your project with Maven and runs the jar.',
    'icon' => 'apachemaven',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Package a runnable jar (with a Main-Class and its dependencies, like the shade or assembly plugin makes). Listen on 0.0.0.0 and the PORT environment variable.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'JAVA_OPTS', 'label' => 'JVM options', 'default' => '-XX:MaxRAMPercentage=75'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM maven:3.9-eclipse-temurin-21 AS build
WORKDIR /src
COPY . .
RUN if [ -f mvnw ]; then chmod +x mvnw && ./mvnw -q -B -DskipTests package; else mvn -q -B -DskipTests package; fi \
    && cp "$(ls -S target/*.jar | grep -v -e '-sources\.jar$' -e '-javadoc\.jar$' -e '/original-' | head -1)" /app.jar

FROM eclipse-temurin:21-jre
COPY --from=build /app.jar /app.jar
ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "exec java $JAVA_OPTS -jar /app.jar"]
DOCKERFILE,
    ],
];
