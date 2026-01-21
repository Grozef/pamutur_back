<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PMUStatisticsService;
use App\Models\Performance;
use App\Models\Horse;
use App\Models\Race;
use App\Models\Jockey;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
// use Illuminate\Foundation\Testing\RefreshDatabase;

class PMUStatisticsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PMUStatisticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PMUStatisticsService();
    }

    public function test_probability_calculation_returns_value_between_1_and_100(): void
    {
        $performance = $this->createTestPerformance();

        $probability = $this->service->calculateProbability($performance);

        $this->assertGreaterThanOrEqual(1, $probability);
        $this->assertLessThanOrEqual(100, $probability);
    }

    public function test_probability_calculation_with_good_form(): void
    {
        $performance = $this->createTestPerformance([
            'raw_musique' => '1p1p2p(25)1p1p' // Excellent form
        ]);

        $probability = $this->service->calculateProbability($performance);

        $this->assertGreaterThan(60, $probability, 'Good form should result in high probability');
    }

    public function test_probability_calculation_with_poor_form(): void
    {
        $performance = $this->createTestPerformance([
            'raw_musique' => '0p0pDa(25)0p' // Poor form
        ]);

        $probability = $this->service->calculateProbability($performance);

        $this->assertLessThan(40, $probability, 'Poor form should result in low probability');
    }

    public function test_get_race_predictions_returns_collection(): void
    {
        $race = $this->createTestRace();
        $this->createMultiplePerformances($race, 5);

        $predictions = $this->service->getRacePredictions($race->id);

        $this->assertNotEmpty($predictions);
        $this->assertCount(5, $predictions);
    }

    public function test_predictions_are_sorted_by_probability(): void
    {
        $race = $this->createTestRace();
        $this->createMultiplePerformances($race, 5);

        $predictions = $this->service->getRacePredictions($race->id);

        $probabilities = $predictions->pluck('probability')->toArray();
        $sortedProbabilities = collect($probabilities)->sortDesc()->values()->toArray();

        $this->assertEquals($sortedProbabilities, $probabilities, 'Predictions should be sorted by probability desc');
    }

    public function test_probabilities_sum_to_approximately_100(): void
    {
        $race = $this->createTestRace();
        $this->createMultiplePerformances($race, 5);

        $predictions = $this->service->getRacePredictions($race->id);

        $sum = $predictions->sum('probability');

        $this->assertGreaterThan(95, $sum);
        $this->assertLessThan(105, $sum);
    }

    public function test_scenario_detection_dominant_favorite(): void
    {
        $race = $this->createTestRace();

        // Create one horse with much better stats
        $this->createTestPerformance([
            'race_id' => $race->id,
            'raw_musique' => '1p1p1p1p1p',
            'weight' => 55000,
            'draw' => 1
        ]);

        // Create others with poor stats
        for ($i = 0; $i < 4; $i++) {
            $this->createTestPerformance([
                'race_id' => $race->id,
                'raw_musique' => '0p0p0p',
                'weight' => 65000,
                'draw' => 10
            ]);
        }

        $predictions = $this->service->getRacePredictions($race->id);
        $scenario = $predictions->first()['race_scenario'] ?? null;

        $this->assertNotNull($scenario);
        $this->assertEquals('DOMINANT_FAVORITE', $scenario['scenario']);
    }

    public function test_value_bet_detection(): void
    {
        $performance = $this->createTestPerformance([
            'raw_musique' => '1p1p2p',
            'odds_ref' => 5.0 // High odds
        ]);

        $predictions = $this->service->getRacePredictions($performance->race_id);
        $firstPrediction = $predictions->first();

        // With good form and high odds, should be value bet
        $this->assertTrue($firstPrediction['value_bet']);
    }

    public function test_weight_penalty_applied(): void
    {
        $performance1 = $this->createTestPerformance([
            'raw_musique' => '1p1p1p',
            'weight' => 55000, // 55kg - good weight
            'race_id' => null
        ]);

        $performance2 = $this->createTestPerformance([
            'raw_musique' => '1p1p1p', // Same form
            'weight' => 70000, // 70kg - heavy weight
            'race_id' => null
        ]);

        $prob1 = $this->service->calculateProbability($performance1);
        $prob2 = $this->service->calculateProbability($performance2);

        $this->assertGreaterThan($prob2, $prob1, 'Heavy weight should reduce probability');
    }

    public function test_draw_position_impact(): void
    {
        $race = $this->createTestRace();

        $performance1 = $this->createTestPerformance([
            'race_id' => $race->id,
            'raw_musique' => '1p1p1p',
            'draw' => 1 // Good draw
        ]);

        $performance2 = $this->createTestPerformance([
            'race_id' => $race->id,
            'raw_musique' => '1p1p1p', // Same form
            'draw' => 15 // Bad draw
        ]);

        // Create more performances to have proper race context
        for ($i = 0; $i < 5; $i++) {
            $this->createTestPerformance([
                'race_id' => $race->id,
                'raw_musique' => '5p6p7p',
                'draw' => $i + 5
            ]);
        }

        $predictions = $this->service->getRacePredictions($race->id);

        $pred1 = $predictions->where('draw', 1)->first();
        $pred2 = $predictions->where('draw', 15)->first();

        $this->assertGreaterThan($pred2['probability'], $pred1['probability'],
            'Good draw should have higher probability');
    }

    public function test_handles_empty_musique_gracefully(): void
    {
        $performance = $this->createTestPerformance([
            'raw_musique' => null
        ]);

        $probability = $this->service->calculateProbability($performance);

        $this->assertIsFloat($probability);
        $this->assertGreaterThanOrEqual(1, $probability);
    }

    public function test_handles_few_horses_race(): void
    {
        $race = $this->createTestRace();
        $this->createMultiplePerformances($race, 2); // Only 2 horses

        $predictions = $this->service->getRacePredictions($race->id);

        $this->assertCount(2, $predictions);
        $sum = $predictions->sum('probability');
        $this->assertEqualsWithDelta(100, $sum, 1);
    }

    // Helper methods

    private function createTestPerformance(array $attributes = []): Performance
    {
        if (!isset($attributes['race_id'])) {
            $race = $this->createTestRace();
            $attributes['race_id'] = $race->id;
        }

        if (!isset($attributes['horse_id'])) {
            $horse = Horse::create([
                'id_cheval_pmu' => 'TEST_HORSE_' . uniqid(),
                'name' => 'Test Horse ' . uniqid(),
                'age' => 4
            ]);
            $attributes['horse_id'] = $horse->id_cheval_pmu;
        }

        return Performance::create(array_merge([
            'raw_musique' => '1p2p3p',
            'weight' => 58000,
            'draw' => 5,
            'odds_ref' => 3.5
        ], $attributes));
    }

    private function createTestRace(): Race
    {
        return Race::create([
            'race_date' => now(),
            'race_code' => 'R1C' . uniqid(),
            'hippodrome' => 'TEST',
            'distance' => 2100,
            'discipline' => 'TROT'
        ]);
    }

    private function createMultiplePerformances(Race $race, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $horse = Horse::create([
                'id_cheval_pmu' => 'TEST_HORSE_' . uniqid(),
                'name' => 'Test Horse ' . $i,
                'age' => rand(3, 8)
            ]);

            Performance::create([
                'race_id' => $race->id,
                'horse_id' => $horse->id_cheval_pmu,
                'raw_musique' => $this->randomMusique(),
                'weight' => rand(52000, 62000),
                'draw' => $i + 1,
                'odds_ref' => rand(20, 100) / 10
            ]);
        }
    }

    private function randomMusique(): string
    {
        $positions = ['1p', '2p', '3p', '4p', '5p', '0p', 'Da'];
        $length = rand(3, 6);
        $musique = '';

        for ($i = 0; $i < $length; $i++) {
            $musique .= $positions[array_rand($positions)];
        }

        return $musique;
    }
}