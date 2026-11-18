<?php

use App\Models\User;

it('keeps flashed errors through a background fetch so the page visit after an action still gets them', function () {
    $this->actingAs(User::factory()->create());
    $this->withSession(['_flash' => ['old' => ['success'], 'new' => []], 'success' => 'Saved.']);

    $this->withHeaders(['Sec-Fetch-Mode' => 'cors', 'Accept' => '*/*'])->get('/threads/list')->assertOk();

    expect(session('_flash.old'))->toContain('success');
});
