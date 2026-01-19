# PMU Backend - Architecture Technique

## Vue d'ensemble

Backend Laravel pour système de prédiction de courses hippiques avec:
- Fetch automatique des données PMU
- Stockage MySQL normalisé
- Calcul de probabilités basé sur algorithme
- API REST pour frontend Vue.js

## Architecture en Couches

```
┌─────────────────────────────────────────────┐
│           Frontend Vue.js                    │
│     (par_mutuel_urbain project)             │
└──────────────────┬──────────────────────────┘
                   │ HTTP/JSON
                   ▼
┌─────────────────────────────────────────────┐
│         API Layer (Controllers)              │
│  - PMUController (REST endpoints)           │
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│         Service Layer                        │
│  - PMUFetcherService  (API externe)         │
│  - PMUStorageService  (Parsing/Storage)     │
│  - PMUStatisticsService (Calculs)           │
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│         Data Layer (Models)                  │
│  - Horse, Jockey, Trainer, Race             │
│  - Performance (relation many-to-many)      │
└──────────────────┬──────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│         MySQL Database                       │
│  - Normalized schema with relationships     │
└─────────────────────────────────────────────┘
```

## Flux de Données

### 1. Fetch Automatique (Scheduler)

```
Cron (06:00, 14:00)
    ↓
php artisan pmu:fetch
    ↓
PMUFetcherService::fetchProgramme()
    ↓
PMU API (https://online.turfinfo.api.pmu.fr)
    ↓
JSON Response
    ↓
PMUStorageService::storeRaceData()
    ↓
MySQL Database
```

### 2. Calcul de Prédictions

```
Frontend Request: GET /api/pmu/races/{id}/predictions
    ↓
PMUController::getRacePredictions()
    ↓
PMUStatisticsService::calculateProbability()
    ↓
    ├─→ calculateFormScore() (40%)
    │   └─→ parseMusique() + temporal weighting
    │
    ├─→ calculateClassScore() (25%)
    │   └─→ career gains / races
    │
    ├─→ calculateJockeyScore() (25%)
    │   └─→ jockey-trainer synergy
    │
    └─→ calculateAptitudeScore() (10%)
        └─→ draw + weight penalties
    ↓
Probability Score (0-10)
    ↓
JSON Response to Frontend
```

## Modèle de Données

### Relations Principales

```sql
horses
  ├─ id_cheval_pmu (PK)
  ├─ father_id → horses.id_cheval_pmu
  └─ mother_id → horses.id_cheval_pmu

performances
  ├─ horse_id → horses.id_cheval_pmu
  ├─ race_id → races.id
  ├─ jockey_id → jockeys.id
  └─ trainer_id → trainers.id
```

### Exemple de Données

```json
{
  "horse": {
    "id_cheval_pmu": "CHEVAL_123",
    "name": "MADAME LY",
    "age": 4,
    "father": {
      "id": "PERE_GREAT_STALLION",
      "name": "GREAT STALLION"
    }
  },
  "performance": {
    "race_id": 1,
    "jockey": "J. VERBEECK",
    "rank": 1,
    "raw_musique": "1p(25)4p1p"
  }
}
```

## Services Détaillés

### PMUFetcherService

**Responsabilité**: Communication avec API PMU externe

**Méthodes**:
- `fetchProgramme(date)`: Programme complet du jour
- `fetchReunion(date, num)`: Détails d'une réunion
- `fetchCourse(date, reunion, course)`: Participants d'une course

**URL Format**: `https://online.turfinfo.api.pmu.fr/rest/client/1/programme/{DDMMYYYY}/R{N}/C{N}/participants`

### PMUStorageService

**Responsabilité**: Transformation JSON → Base de données

**Pipeline**:
1. `createRace()`: Créer/Mettre à jour course
2. `storeParticipant()`: Pour chaque cheval
   - Créer/Récupérer Horse
   - Créer/Récupérer Jockey
   - Créer/Récupérer Trainer
   - Créer Performance (lien)
3. `createHorse()`: Gestion généalogie (père, mère)

**Transaction**: Tout ou rien (DB::transaction)

### PMUStatisticsService

**Responsabilité**: Algorithme de prédiction

**Parsing Musique**:
```
Input:  "1p(25)4p1p"
Output: {
  2026: ['1p'],
  2025: ['4p', '1p']
}
```

**Calcul Score**:
- Rang 1: 10 points
- Rang 2: 7 points
- Rang 3: 5 points
- Rang 4-5: 3 points
- DNF: 0 points

**Pondération Temporelle**:
- Année courante (2026): × 1.0
- Année -1 (2025): × 0.5
- Année -2 (2024): × 0.25

## API Endpoints Détaillés

### GET /api/pmu/{date}

**Proxy**: PMU API Programme

**Response**:
```json
{
  "programme": {
    "reunions": [
      {
        "numOrdre": 1,
        "hippodrome": "VINCENNES",
        "courses": [...]
      }
    ]
  }
}
```

### GET /api/pmu/races/{id}/predictions

**Source**: Database + Calculs

**Response**:
```json
{
  "race": {
    "id": 1,
    "date": "2026-01-19 14:30:00",
    "hippodrome": "VINCENNES",
    "distance": 2100,
    "discipline": "ATTELE"
  },
  "predictions": [
    {
      "horse_name": "MADAME LY",
      "probability": 8.5,
      "odds_ref": 3.2,
      "value_bet": true,
      "draw": 5,
      "jockey_name": "J. VERBEECK"
    }
  ]
}
```

### GET /api/pmu/horses/{id}

**Source**: Database

**Response**:
```json
{
  "horse": {
    "id": "CHEVAL_123",
    "name": "MADAME LY",
    "sex": "FEMELLES",
    "age": 4
  },
  "genealogy": {
    "father": {"name": "GREAT STALLION"},
    "mother": {"name": "GREAT MARE"}
  },
  "career_stats": {
    "total_races": 45,
    "wins": 12,
    "places": 25,
    "total_gains": 125000,
    "win_rate": 26.67
  },
  "recent_performances": [...]
}
```

## Scheduler Configuration

### Kernel.php

```php
$schedule->command('pmu:fetch')
    ->dailyAt('06:00')
    ->withoutOverlapping();

$schedule->command('pmu:fetch')
    ->dailyAt('14:00')
    ->withoutOverlapping();
```

### Crontab Entry

```
* * * * * cd /path/to/project && php artisan schedule:run
```

## Performance Optimizations

### Indexes

```sql
-- Queries fréquentes optimisées
INDEX idx_race_date_hippodrome (race_date, hippodrome)
INDEX idx_horse_race (horse_id, race_id)
INDEX idx_jockey_trainer (jockey_id, trainer_id)
```

### Caching (Future)

```php
Cache::remember('race_predictions_' . $raceId, 3600, function() {
    return $this->stats->getRacePredictions($raceId);
});
```

### Queue Jobs (Future)

```php
dispatch(new FetchPMUDataJob($date));
```

## Sécurité

### Rate Limiting

```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->ip());
});
```

### CORS

```php
// config/cors.php
'allowed_origins' => [
    'http://localhost:5173', // Vite dev server
    env('FRONTEND_URL')
],
```

## Tests

### Unit Tests

```bash
php artisan test --filter=PMUStatisticsServiceTest
```

### Example Test

```php
public function test_probability_calculation()
{
    $performance = Performance::factory()->create([
        'raw_musique' => '1p(25)2p3p',
        'weight' => 55000
    ]);
    
    $probability = $this->stats->calculateProbability($performance);
    
    $this->assertGreaterThan(5.0, $probability);
}
```

## Déploiement

### Requirements Serveur

- PHP 8.2+ with extensions: pdo_mysql, mbstring, json
- MySQL 8.0+
- Composer
- Cron daemon

### Steps

```bash
git clone <repo>
cd pmu-laravel-backend
./setup.sh
```

### Environment Variables

```env
APP_ENV=production
APP_DEBUG=false
DB_DATABASE=pmu_production
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

## Monitoring

### Logs

```bash
tail -f storage/logs/laravel.log
tail -f storage/logs/pmu-fetch.log
```

### Metrics

- Fetch success rate
- API response times
- Database query performance
- Prediction accuracy

## Evolution Future

### Phase 2

- [ ] Machine Learning integration
- [ ] Real-time odds tracking
- [ ] Historical analysis dashboard
- [ ] Multi-user betting strategies

### Phase 3

- [ ] Microservices architecture
- [ ] GraphQL API
- [ ] WebSocket live updates
- [ ] Mobile app support
