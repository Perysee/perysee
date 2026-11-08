<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The configuration audit reads the tracker's session rules from config/perysee.php, while resources/js/lueur.js has
 * the same numbers in its code and does not read PHP. This test reads the script as text so that changing one without
 * the other fails here.
 */
class TrackerConstantsTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(base_path('resources/js/lueur.js'));
    }

    private function constant(string $pattern, string $what): int
    {
        $this->assertSame(1, preg_match($pattern, $this->script(), $match), "lueur.js no longer matches the expected shape for {$what}: update this test and the configuration together.");

        return (int) $match[1];
    }

    public function test_the_session_inactivity_is_the_configured_one(): void
    {
        $this->assertSame(config('perysee.tracker.session_idle_ms'), $this->constant('/const idle = (\d+);/', 'the session inactivity'));
    }

    public function test_the_session_lifetime_is_the_configured_one(): void
    {
        $this->assertSame(config('perysee.tracker.session_lifetime_ms'), $this->constant('/const lifetime = (\d+);/', 'the session lifetime'));
    }

    public function test_the_cookie_max_age_cap_is_the_configured_one(): void
    {
        $this->assertSame(config('perysee.tracker.cookie_max_age_seconds'), $this->constant('/Math\.min\((\d+), Math\.ceil\(\(record\.born \+ lifetime - now\) \/ 1000\)\)/', 'the Max-Age of the cookie'));
    }

    public function test_the_cookie_is_host_only_and_named_after_the_site(): void
    {
        $script = $this->script();

        $this->assertStringNotContainsString('Domain=', $script, 'A Domain attribute would share the identifier between subdomains.');
        $this->assertMatchesRegularExpression("/const cookieName = 'perysee_lueur_' \+ site\./", $script);
        $this->assertStringContainsString("'; Path=/; SameSite=Lax; Max-Age='", $script);
    }

    public function test_the_values_are_whole_and_ordered(): void
    {
        $this->assertSame(0, config('perysee.tracker.session_idle_ms') % 1000);
        $this->assertGreaterThan(config('perysee.tracker.session_idle_ms'), config('perysee.tracker.session_lifetime_ms'));
        $this->assertLessThanOrEqual(config('perysee.tracker.session_idle_ms') / 1000, config('perysee.tracker.cookie_max_age_seconds'));
    }
}
