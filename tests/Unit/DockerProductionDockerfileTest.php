<?php

declare(strict_types=1);

function productionDockerfile(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile');
}

function productionDockerfileStage(string $stage): string
{
    $dockerfile = productionDockerfile();
    $pattern = '/FROM .+ AS '.preg_quote($stage, '/').'\n(.*?)(?=\nFROM |\z)/s';

    expect($dockerfile)->toMatch($pattern);

    preg_match($pattern, $dockerfile, $matches);

    return $matches[1];
}

test('the production image does not run the inertia ssr vite build', function () {
    expect(productionDockerfile())->not->toMatch('/npm run build:ssr/')
        ->and(productionDockerfileStage('asset-build'))->toContain('npm run build');
});

test('the production image copies app source and public assets instead of the whole asset-build tree', function () {
    $production = productionDockerfileStage('production');

    expect($production)->not->toContain('COPY --from=asset-build /var/www/html /var/www/html')
        ->and($production)->toContain('COPY --from=asset-build /var/www/html/app /var/www/html/app')
        ->and($production)->toContain('COPY --from=asset-build /var/www/html/public /var/www/html/public')
        ->and($production)->toContain('COPY --from=composer-deps-prod /var/www/html/vendor /var/www/html/vendor')
        ->and($production)->not->toContain('node_modules')
        ->and($production)->not->toContain('/var/www/html/tests');
});

test('composer and npm install layers use buildkit cache mounts', function () {
    expect(productionDockerfile())->toContain('--mount=type=cache,target=/root/.composer/cache')
        ->and(productionDockerfile())->toContain('--mount=type=cache,target=/root/.npm');
});

test('php compile-only apk packages are virtual so they are dropped from system-base', function () {
    $systemBase = productionDockerfileStage('system-base');

    expect($systemBase)->toContain('--virtual .php-build-deps')
        ->and($systemBase)->toContain('apk del .php-build-deps')
        ->and($systemBase)->toContain('postgresql-dev');
});

test('vite build args are declared after node and npm install so key changes do not bust those layers', function () {
    $assetBuild = productionDockerfileStage('asset-build');

    expect(strpos($assetBuild, 'apk add --no-cache nodejs npm'))
        ->toBeLessThan(strpos($assetBuild, 'npm ci --no-audit --no-fund --cache /root/.npm'))
        ->and(strpos($assetBuild, 'npm ci --no-audit --no-fund --cache /root/.npm'))
        ->toBeLessThan(strpos($assetBuild, 'ARG VITE_APP_NAME=TryPost'))
        ->and(strpos($assetBuild, 'ARG VITE_APP_NAME=TryPost'))
        ->toBeLessThan(strpos($assetBuild, 'npm run build'));
});

test('the dockerfile does not declare runtime secrets as build args', function () {
    expect(productionDockerfile())->not->toMatch('/^ARG (DB_PASSWORD|APP_KEY|REDIS_PASSWORD|PASSPORT_PRIVATE_KEY|MAIL_PASSWORD)$/m');
});
