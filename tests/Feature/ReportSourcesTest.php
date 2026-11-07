<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use App\Modules\Contracts\ReportSource;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use App\Modules\Reports\ReportSection;
use App\Modules\Reports\ReportSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ReportSourcesTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config(['perysee.modules_path' => $this->modulesPath, 'perysee.version' => '0.4.0']);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(ModuleScope::class);
        app()->forgetInstance(ReportSources::class);
    }

    private function install(string $id): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id);
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode(['id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'core' => '0.1.0']));
        app(ModuleRegistry::class)->enable($id);
    }

    private function sources(): ReportSources
    {
        return app(ReportSources::class);
    }

    private function source(callable $sections): ReportSource
    {
        return new class($sections) implements ReportSource
        {
            public function __construct(private $sections) {}

            public function label(): string
            {
                return "Fake\x1b source";
            }

            public function sections(int $siteId, Carbon $from, Carbon $to): array
            {
                return ($this->sections)($siteId, $from, $to);
            }
        };
    }

    private function period(): array
    {
        return [Carbon::parse('2026-11-01', 'UTC'), Carbon::parse('2026-11-08', 'UTC')];
    }

    public function test_the_core_provides_a_traffic_source_read_from_the_consolidated_statistics(): void
    {
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $other = Website::factory()->create();
        $goal = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => 'Signup', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $metrics = ['pageviews' => 0, 'custom_events' => 0, 'sessions' => 0, 'session_pageviews' => 0, 'unknown_pageviews' => 0, 'dimension_pageviews' => 0, 'country_pageviews' => 0];
        foreach (['2026-10-31' => 100, '2026-11-01' => 10, '2026-11-07' => 5, '2026-11-08' => 100] as $date => $pageviews) {
            DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $date, 'pageviews' => $pageviews, 'sessions' => 2, 'custom_events' => 1] + $metrics);
        }
        DB::table('stats_daily')->insert(['website_id' => $other->id, 'stat_date' => '2026-11-02', 'pageviews' => 999] + $metrics);
        foreach (['2026-11-03' => 4, '2026-11-09' => 50] as $date => $conversions) {
            DB::table('stats_goal_daily')->insert(['website_id' => $site->id, 'goal_id' => $goal, 'version' => 1, 'stat_date' => $date, 'bucket_start' => $date.' 00:00:00', 'segment_hash' => hash('sha256', $date), 'dimensions' => '{}', 'conversions' => $conversions, 'hits' => $conversions]);
        }

        [$from, $to] = $this->period();
        $report = $this->sources()->collect($site->id, $from, $to);

        $this->assertSame('core.traffic', $report[0]['key']);
        $traffic = $report[0]['sections'];
        $this->assertSame([['label' => 'Pageviews', 'value' => '15'], ['label' => 'Sessions', 'value' => '4'], ['label' => 'Custom events', 'value' => '2']], $traffic[0]->rows);
        $this->assertSame('Goal conversions', $traffic[1]->title);
        $this->assertSame([['label' => 'Signup', 'value' => '4']], $traffic[1]->rows);
    }

    public function test_a_module_source_is_collected_with_the_site_and_the_period(): void
    {
        $this->install('digests');
        $site = Website::factory()->create();
        $this->sources()->register('digests', 'digests.incidents', $this->source(fn (int $id, Carbon $from, Carbon $to): array => [new ReportSection('Incidents', [['label' => 'Site', 'value' => $id], ['label' => 'Days', 'value' => $from->diffInDays($to)]])]));

        [$from, $to] = $this->period();
        $report = collect($this->sources()->collect($site->id, $from, $to))->firstWhere('key', 'digests.incidents');

        $this->assertSame('Fake  source', $report['label']);
        $this->assertSame([['label' => 'Site', 'value' => (string) $site->id], ['label' => 'Days', 'value' => '7']], $report['sections'][0]->rows);
    }

    public function test_a_failing_source_is_isolated_and_counted_against_its_module(): void
    {
        $this->install('digests');
        $site = Website::factory()->create();
        $this->sources()->register('digests', 'digests.bad', $this->source(function (): array {
            throw new RuntimeException('boom');
        }));
        $this->sources()->register('digests', 'digests.good', $this->source(fn (): array => [new ReportSection('Fine', [])]));

        [$from, $to] = $this->period();
        $keys = array_column($this->sources()->collect($site->id, $from, $to), 'key');

        $this->assertSame(['core.traffic', 'digests.good'], $keys);
        $this->assertSame(1, app(ModuleFailures::class)->total('digests'));
    }

    public function test_only_report_sections_are_kept_and_their_text_is_plain_and_capped(): void
    {
        $this->install('digests');
        $site = Website::factory()->create();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['label' => "L{$i}\x07".str_repeat('x', 100), 'value' => str_repeat('v', 300)];
        }
        $rows[] = ['label' => 'no value'];
        $this->sources()->register('digests', 'digests.big', $this->source(fn (): array => [
            '<script>alert(1)</script>', ['title' => 'array not allowed'], new ReportSection("Ti\ntle".str_repeat('t', 200), $rows),
        ]));

        [$from, $to] = $this->period();
        $sections = collect($this->sources()->collect($site->id, $from, $to))->firstWhere('key', 'digests.big')['sections'];

        $this->assertCount(1, $sections);
        $this->assertSame(ReportSection::TITLE_LIMIT, mb_strlen($sections[0]->title));
        $this->assertStringNotContainsString("\n", $sections[0]->title);
        $this->assertCount(ReportSection::ROW_LIMIT, $sections[0]->rows);
        $this->assertSame(ReportSection::LABEL_LIMIT, mb_strlen($sections[0]->rows[0]['label']));
        $this->assertSame(ReportSection::VALUE_LIMIT, mb_strlen($sections[0]->rows[0]['value']));
    }

    public function test_the_number_of_sections_is_capped_per_source_and_in_total(): void
    {
        $this->install('digests');
        $site = Website::factory()->create();
        $many = fn (): array => array_map(fn (int $i): ReportSection => new ReportSection("S{$i}", []), range(1, 30));
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $this->sources()->register('digests', 'digests.'.$name, $this->source($many));
        }

        [$from, $to] = $this->period();
        $report = $this->sources()->collect($site->id, $from, $to);

        foreach ($report as $entry) {
            $this->assertLessThanOrEqual(ReportSources::SECTIONS_PER_SOURCE, count($entry['sections']));
        }
        $this->assertLessThanOrEqual(ReportSources::SECTIONS_TOTAL, array_sum(array_map(fn (array $entry): int => count($entry['sections']), $report)));
    }

    public function test_a_source_is_skipped_for_a_site_its_module_is_not_active_on_and_when_disabled(): void
    {
        $this->install('digests');
        $this->install('paused');
        $site = Website::factory()->create();
        $other = Website::factory()->create();
        app(ModuleScope::class)->limitTo('digests', [$other->id]);
        $this->sources()->register('digests', 'digests.x', $this->source(fn (): array => [new ReportSection('X', [])]));
        $this->sources()->register('paused', 'paused.x', $this->source(fn (): array => [new ReportSection('Y', [])]));
        app(ModuleRegistry::class)->disable('paused');

        [$from, $to] = $this->period();

        $this->assertSame(['core.traffic'], array_column($this->sources()->collect($site->id, $from, $to), 'key'));
        $this->assertContains('digests.x', array_column($this->sources()->collect($other->id, $from, $to), 'key'));
    }

    public function test_keys_must_carry_the_module_prefix_and_be_unique(): void
    {
        $this->sources()->register('digests', 'digests.x', $this->source(fn (): array => []));
        foreach ([['digests', 'digests.x'], ['digests', 'core.traffic'], ['digests', 'other.x'], ['core', 'core.traffic']] as [$module, $key]) {
            try {
                $this->sources()->register($module, $key, $this->source(fn (): array => []));
                $this->fail("{$key} must be refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
