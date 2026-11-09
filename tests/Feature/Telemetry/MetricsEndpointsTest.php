<?php

it('does not serve the prometheus endpoint', function () {
    $this->get('/prometheus')->assertNotFound();
});

it('does not serve the queue metrics api to guests', function () {
    $this->get('/queue-metrics/workers')->assertForbidden();
    $this->get('/queue-metrics/prometheus')->assertForbidden();
});
