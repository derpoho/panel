<?php

return [
    'name' => 'Java (Gradle)',
    'category' => 'Apps',
    'description' => 'Java 21. Builds your project with Gradle and runs the jar.',
    'icon' => 'gradle',
    'kind' => 'app',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Build a runnable jar from assemble (the Shadow plugin or a fat jar task does this). Listen on 0.0.0.0 and the PORT environment variable.',
    'dockerfile' => '.serveros/Dockerfile',
    'env' => [
        ['key' => 'JAVA_OPTS', 'label' => 'JVM options', 'default' => '-XX:MaxRAMPercentage=75'],
    ],
    'files' => [
        '.serveros/Dockerfile' => <<<'DOCKERFILE'
FROM gradle:8-jdk21 AS build
WORKDIR /src
COPY . .
RUN if [ -f gradlew ]; then chmod +x gradlew && ./gradlew --no-daemon -q assemble -x test; else gradle --no-daemon -q assemble -x test; fi \
    && cp "$(ls -S build/libs/*.jar | grep -v -e '-plain\.jar$' -e '-sources\.jar$' -e '-javadoc\.jar$' | head -1)" /app.jar

FROM eclipse-temurin:21-jre
COPY --from=build /app.jar /app.jar
ENV PORT=8080
EXPOSE 8080
CMD ["sh", "-c", "exec java $JAVA_OPTS -jar /app.jar"]
DOCKERFILE,
    ],
];
