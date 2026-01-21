<?php

namespace App\Services;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Trainer;
use App\Models\Race;
use App\Models\Performance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PMUStorageService
{
    public function storeRaceData(array $data, string $date, int $reunionNum, int $courseNum): ?Race
    {
        try {
            DB::beginTransaction();

            $race = $this->createRace($data, $date, $reunionNum, $courseNum);

            if (isset($data['participants']) && is_array($data['participants'])) {
                foreach ($data['participants'] as $participant) {
                    if ($this->validateParticipant($participant)) {
                        $this->storeParticipant($participant, $race);
                    }
                }
            }

            DB::commit();
            return $race;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error storing race data: {$e->getMessage()}", [
                'date' => $date,
                'reunion' => $reunionNum,
                'course' => $courseNum,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function createRace(array $data, string $date, int $reunionNum, int $courseNum): Race
    {
        // FIXED: Validate date format
        $raceDate = \DateTime::createFromFormat('dmY', $date);
        if ($raceDate === false) {
            throw new \InvalidArgumentException("Invalid date format: {$date}. Expected dmY format.");
        }

        // FIXED: Validate and parse time
        if (isset($data['heureDepart'])) {
            $time = str_pad($data['heureDepart'], 4, '0', STR_PAD_LEFT);

            if (strlen($time) === 4 && ctype_digit($time)) {
                $hour = (int)substr($time, 0, 2);
                $minute = (int)substr($time, 2, 2);

                // Validate hour and minute ranges
                if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                    $raceDate->setTime($hour, $minute);
                } else {
                    Log::warning("Invalid time values", [
                        'time' => $time,
                        'hour' => $hour,
                        'minute' => $minute
                    ]);
                }
            } else {
                Log::warning("Invalid time format", ['time' => $data['heureDepart']]);
            }
        }

        return Race::updateOrCreate(
            [
                'race_code' => "R{$reunionNum}C{$courseNum}",
                'race_date' => $raceDate
            ],
            [
                'hippodrome' => $data['hippodrome'] ?? null,
                'distance' => $this->validateDistance($data['distance'] ?? null),
                'discipline' => $data['discipline'] ?? null,
                'track_condition' => $data['penetrometre'] ?? null
            ]
        );
    }

    private function storeParticipant(array $participant, Race $race): void
    {
        $horse = $this->createHorse($participant);

        $jockey = null;
        if (!empty($participant['driver'])) {
            $jockey = Jockey::firstOrCreate(['name' => $participant['driver']]);
        }

        $trainer = null;
        if (!empty($participant['entraineur'])) {
            $trainer = Trainer::firstOrCreate(['name' => $participant['entraineur']]);
        }

        Performance::updateOrCreate(
            [
                'horse_id' => $horse->id_cheval_pmu,
                'race_id' => $race->id
            ],
            [
                'jockey_id' => $jockey?->id,
                'trainer_id' => $trainer?->id,
                'rank' => null,
                'weight' => $this->validateWeight($participant['handicapPoids'] ?? null),
                'draw' => $participant['placeCorde'] ?? null,
                'raw_musique' => $participant['musique'] ?? null,
                'odds_ref' => $participant['dernierRapportDirect'] ?? null,
                'gains_race' => null
            ]
        );
    }

    private function createHorse(array $participant): Horse
    {
        $horseId = $participant['idCheval'];

        // Try to find existing father by name first
        $fatherId = null;
        if (!empty($participant['nomPere'])) {
            $father = Horse::where('name', $participant['nomPere'])->first();

            if (!$father) {
                $father = Horse::firstOrCreate(
                    ['id_cheval_pmu' => 'STALLION_' . Str::slug($participant['nomPere'])],
                    ['name' => $participant['nomPere']]
                );
            }

            $fatherId = $father->id_cheval_pmu;
        }

        $motherId = null;
        if (!empty($participant['nomMere'])) {
            $mother = Horse::where('name', $participant['nomMere'])->first();

            if (!$mother) {
                $mother = Horse::firstOrCreate(
                    ['id_cheval_pmu' => 'MARE_' . Str::slug($participant['nomMere'])],
                    ['name' => $participant['nomMere']]
                );
            }

            $motherId = $mother->id_cheval_pmu;
        }

        return Horse::updateOrCreate(
            ['id_cheval_pmu' => $horseId],
            [
                'name' => $participant['nom'],
                'sex' => $this->mapSex($participant['sexe'] ?? null),
                'age' => $this->validateAge($participant['age'] ?? null),
                'father_id' => $fatherId,
                'mother_id' => $motherId,
                'dam_sire_name' => $participant['nomPereMere'] ?? null,
                'breed' => $participant['race'] ?? null
            ]
        );
    }

    private function mapSex(?string $sex): ?string
    {
        if (!$sex) return null;

        $sexUpper = strtoupper($sex);

        if (in_array($sexUpper, ['M', 'MALE'])) return 'MALES';
        if (in_array($sexUpper, ['F', 'FEMELLE'])) return 'FEMELLES';
        if (in_array($sexUpper, ['H', 'HONGRE'])) return 'HONGRES';

        return null;
    }

    public function updateRaceResults(Race $race, array $results): void
    {
        foreach ($results as $result) {
            $performance = Performance::where('race_id', $race->id)
                ->where('horse_id', $result['idCheval'])
                ->first();

            if ($performance) {
                $performance->update([
                    'rank' => $result['classementArrivee'] ?? null,
                    'gains_race' => $result['gainsParticipant'] ?? 0
                ]);
            }
        }
    }

    /**
     * Validate participant data
     */
    private function validateParticipant(array $participant): bool
    {
        if (empty($participant['idCheval']) || empty($participant['nom'])) {
            Log::warning("Missing required horse data", ['data' => $participant]);
            return false;
        }

        return true;
    }

    /**
     * Validate and sanitize age
     */
    private function validateAge(?int $age): ?int
    {
        if ($age === null) return null;

        if ($age < 2 || $age > 25) {
            Log::warning("Invalid age for horse", ['age' => $age]);
            return null;
        }

        return $age;
    }

    /**
     * Validate and sanitize weight
     */
    private function validateWeight($weight): ?int
    {
        if ($weight === null) return null;

        $weightInt = (int)$weight;
        $weightKg = $weightInt / 1000;

        if ($weightKg < 40 || $weightKg > 80) {
            Log::warning("Suspicious weight value", [
                'weight_grams' => $weightInt,
                'weight_kg' => $weightKg
            ]);
            // Accept it anyway but log
        }

        return $weightInt;
    }

    /**
     * Validate distance
     */
    private function validateDistance($distance): ?int
    {
        if ($distance === null) return null;

        $distanceInt = (int)$distance;

        if ($distanceInt < 800 || $distanceInt > 6000) {
            Log::warning("Invalid distance", ['distance' => $distanceInt]);
            return null;
        }

        return $distanceInt;
    }
}