<?php

declare(strict_types=1);

/**
 * compose.prod.yaml is the self-host / Coolify contract. Secrets and
 * per-install values must be interpolated from the environment so Coolify's
 * Environment Variables UI (and a local `.env`) can supply them.
 */
function composeProdYaml(): string
{
    return file_get_contents(base_path('compose.prod.yaml'));
}

function productionEnvExample(): string
{
    return file_get_contents(base_path('.env.production.example'));
}

test('required secrets use compose required-variable syntax so Coolify blocks an empty deploy', function (string $key) {
    expect(composeProdYaml())->toContain('${'.$key.':?}');
})->with([
    'APP_KEY',
    'DB_PASSWORD',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
]);

test('the bundled postgres password stays in lockstep with DB_PASSWORD', function () {
    expect(composeProdYaml())->toContain('POSTGRES_PASSWORD: "${DB_PASSWORD:?DB_PASSWORD is required}"');
});

test('coolify can prefill APP_URL from the domain assigned to the app service', function () {
    expect(composeProdYaml())->toContain('${SERVICE_URL_APP');
});

test('the coolify compose file does not start caddy (coolify already proxies and ignores profiles)', function () {
    expect(composeProdYaml())->not->toMatch('/^\s+caddy:/m');
});

test('production logs go to stderr so a platform like Coolify surfaces Laravel exceptions', function () {
    expect(composeProdYaml())->toContain('LOG_CHANNEL: "${LOG_CHANNEL:-stderr}"');
});

test('the production env example documents every required compose secret', function (string $key) {
    expect(productionEnvExample())->toMatch('/^'.$key.'=/m');
})->with([
    'APP_KEY',
    'APP_URL',
    'DB_PASSWORD',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
]);
