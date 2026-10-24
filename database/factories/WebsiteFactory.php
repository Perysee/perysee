<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return ['name' => fake()->company(), 'domain' => $domain, 'allowed_domains' => [$domain], 'timezone' => 'UTC'];
    }
}
