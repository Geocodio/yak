<?php

it('renders the login video with the walker clip and poster', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('/videos/yak-walker-hair-lift.mp4', false)
        ->assertSee('/videos/yak-walker-hair-lift-poster.jpg', false)
        ->assertSee('data-testid="google-signin"', false)
        ->assertDontSee('Team access only');
});
