<?php

namespace App\Services;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Trainer;
use App\Models\Race;
use App\Models\Performance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PMUStorageService
{
    /**
     * Store complete race data with participants
     */
    public function storeRaceData(array $data, string $date, int $reunionNum, int $courseNum): ?Race
    {
        try {
            DB::beginTransaction();

            // Create or update race
            $race = $this->createRace($data, $date, $reunionNum, $courseNum);

            // Store participants
            if (isset($data['participants']) && is_array($data['participants'])) {
                foreach ($data['participants'] as $participant) {
                    $this->storeParticipant($participant, $race);
                }
            }

            DB::commit();
            return $race;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error storing race data: {$e->getMessage()}", [
                'date' => $date,
                'reunion' => $reunionNum,
                'course' => $courseNum
            ]);
            return null;
        }
    }

    /**
     * Create race record
     */
    private function createRace(array $data, string $date, int $reunionNum, int $courseNum): Race
    {
        $raceDate = \DateTime::createFromFormat('dmY', $date);
        if (isset($data['heureDepart'])) {
            $time = $data['heureDepart'];
            $raceDate->setTime(
                (int)substr($time, 0, 2),
                (int)substr($time, 2, 2)
            );
        }

        return Race::updateOrCreate(
            [
                'race_code' => "R{$reunionNum}C{$courseNum}",
                'race_date' => $raceDate
            ],
            [
                'hippodrome' => $data['hippodrome'] ?? null,
                'distance' => $data['distance'] ?? null,
                'discipline' => $data['discipline'] ?? null,
                'track_condition' => $data['penetrometre'] ?? null
            ]
        );
    }

    /**
     * Store participant (horse) data
     */
    private function storeParticipant(array $participant, Race $race): void
    {
        // Create or get horse
        $horse = $this->createHorse($participant);

        // Create or get jockey
        $jockey = null;
        if (!empty($participant['driver'])) {
            $jockey = Jockey::firstOrCreate(['name' => $participant['driver']]);
        }

        // Create or get trainer
        $trainer = null;
        if (!empty($participant['entraineur'])) {
            $trainer = Trainer::firstOrCreate(['name' => $participant['entraineur']]);
        }

        // Create performance record
        Performance::updateOrCreate(
            [
                'horse_id' => $horse->id_cheval_pmu,
                'race_id' => $race->id
            ],
            [
                'jockey_id' => $jockey?->id,
                'trainer_id' => $trainer?->id,
                'rank' => null, // Will be updated after race
                'weight' => isset($participant['handicapPoids'])
                    ? (int)($participant['handicapPoids'] )
                    : null,
                'draw' => $participant['placeCorde'] ?? null,
                'raw_musique' => $participant['musique'] ?? null,
                'odds_ref' => $participant['dernierRapportDirect'] ?? null,
                'gains_race' => null // Will be updated after race
            ]
        );
    }

    /**
     * Create or update horse with genealogy
     */
    private function createHorse(array $participant): Horse
    {
        $horseId = $participant['idCheval'];

        // Create father if exists
        $fatherId = null;
        if (!empty($participant['nomPere'])) {
            $father = Horse::firstOrCreate(
                ['id_cheval_pmu' => 'PERE_' . $participant['nomPere']],
                ['name' => $participant['nomPere']]
            );
            $fatherId = $father->id_cheval_pmu;
        }

        // Create mother if exists
        $motherId = null;
        if (!empty($participant['nomMere'])) {
            $mother = Horse::firstOrCreate(
                ['id_cheval_pmu' => 'MERE_' . $participant['nomMere']],
                ['name' => $participant['nomMere']]
            );
            $motherId = $mother->id_cheval_pmu;
        }

        return Horse::updateOrCreate(
            ['id_cheval_pmu' => $horseId],
            [
                'name' => $participant['nom'],
                'sex' => $this->mapSex($participant['sexe'] ?? null),
                'age' => $participant['age'] ?? null,
                'father_id' => $fatherId,
                'mother_id' => $motherId,
                'dam_sire_name' => $participant['nomPereMere'] ?? null,
                'breed' => $participant['race'] ?? null
            ]
        );
    }

    /**
     * Map sex from PMU format to database enum
     */
    private function mapSex(?string $sex): ?string
    {
        if (!$sex) return null;

        $sexUpper = strtoupper($sex);

        if (in_array($sexUpper, ['M', 'MALE'])) return 'MALES';
        if (in_array($sexUpper, ['F', 'FEMELLE'])) return 'FEMELLES';
        if (in_array($sexUpper, ['H', 'HONGRE'])) return 'HONGRES';

        return null;
    }

    /**
     * Update performance with race results
     */
    public function updateRaceResults(Race $race, array $results): void
    {
        foreach ($results as $result) {
            $performance = Performance::where('race_id', $race->id)
                ->where('horse_id', $result['idCheval'])
                ->first();

            if ($performance) {
                $performance->update([
                    'rank' => $result['classementArrivee'] ?? 0,
                    'gains_race' => $result['gainsParticipant'] ?? 0
                ]);
            }
        }
    }
}
