<?php

it('shows live updates and catches up after a reconnect', function () {
    // Runs once W2-THREADPAGE exposes data-testid="thread-event-state"; the reducer test and
    // ThreadReconnectJourneyTest cover journey 3 in the meantime.
})->skip('needs the thread page conversation slot from W2-THREADPAGE');
