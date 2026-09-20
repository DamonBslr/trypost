<?php

declare(strict_types=1);

test('login page exposes the runtime reverb app key for echo', function () {
    config()->set('broadcasting.connections.reverb.key', 'coolify-runtime-key');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="reverb-app-key" content="coolify-runtime-key">', escape: false);
});
