<?php

it('exposes a health endpoint without touching the database', function () {
    $this->get('/up')->assertOk();
});

it('answers 404 for an unknown api route', function () {
    $this->getJson('/api/v1/does-not-exist')->assertNotFound();
});
