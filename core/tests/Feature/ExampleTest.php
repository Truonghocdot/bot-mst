<?php

test('the application redirects to the admin dashboard entrypoint', function () {
    $response = $this->get('/');

    $response->assertRedirect('/admin');
});
