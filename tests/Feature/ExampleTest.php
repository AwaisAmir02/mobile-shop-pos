<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guests_see_the_marketing_home_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertSee('on one screen.')->assertSee(route('login'));
    }
}
