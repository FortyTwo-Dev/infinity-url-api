<?php

use Laravel\Sanctum\Sanctum;
use Tests\Support\Api;

it('keeps the canonical https domain when no public base is configured', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $link = $this->linkOn($workspace, $domain, 'canonical1');

    $this->getJson(Api::LINKS.'/'.$link->public_id)
        ->assertOk()
        ->assertJsonPath('short_url', 'https://iurl.fr/canonical1');
});

it('builds the short URL from the local public base when configured', function () {
    config(['redirect.url' => 'http://iurl.test:8001/']);

    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $link = $this->linkOn($workspace, $domain, 'localdev1');

    $this->getJson(Api::LINKS.'/'.$link->public_id)
        ->assertOk()
        ->assertJsonPath('short_url', 'http://iurl.test:8001/localdev1');

    $this->getJson(Api::LINKS)
        ->assertOk()
        ->assertJsonPath('data.0.short_url', 'http://iurl.test:8001/localdev1');
});
