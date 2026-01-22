# Guide d'Utilisation - Nouvelles Fonctionnalités PMU

## Installation

### 1. Copier les fichiers
```bash
# Services
cp ImprovedServices.php backend/par_mutuel_urbain_back/app/Services/ValueBetService.php
cp ImprovedServices.php backend/par_mutuel_urbain_back/app/Services/CombinationService.php

# Controller
cp AdvancedPMUController.php backend/par_mutuel_urbain_back/app/Http/Controllers/Api/AdvancedPMUController.php

# Routes (ajouter dans routes/api.php)
cat advanced_routes.php >> backend/par_mutuel_urbain_back/routes/api.php
```

### 2. Enregistrer les services
```php
// Dans app/Providers/AppServiceProvider.php

use App\Services\ValueBetService;
use App\Services\CombinationService;

public function register(): void
{
    $this->app->singleton(ValueBetService::class);
    $this->app->singleton(CombinationService::class);
}
```

### 3. Clear cache
```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```

---

## Endpoints API

### 1. Value Bets avec Kelly Criterion

**GET** `/api/v1/pmu/races/{raceId}/value-bets?bankroll=1000`

Retourne les chevaux avec une valeur positive et la mise optimale.

**Paramètres** :
- `raceId` (required) : ID de la course
- `bankroll` (optional, default: 1000) : Capital disponible en €

**Réponse** :
```json
{
  "race_id": 123,
  "bankroll": 1000,
  "value_bets": [
    {
      "horse_id": "456",
      "horse_name": "Lightning Bolt",
      "probability": 35.5,
      "odds": 4.2,
      "kelly_data": {
        "is_value": true,
        "kelly_fraction": 3.25,
        "full_kelly": 13.0,
        "recommended_stake": 32.50,
        "edge": 0.128,
        "expected_value": 12.8,
        "roi_per_bet": 394.0
      }
    }
  ],
  "summary": {
    "count": 3,
    "total_stake": 85.75,
    "bankroll_usage": "8.58%",
    "total_expected_value": "31.5%"
  },
  "recommendation": "Excellent value bets detected! Strong betting opportunity."
}
```

**Exemple d'utilisation** :
```javascript
// Frontend
const response = await fetch('/api/v1/pmu/races/123/value-bets?bankroll=1000');
const data = await response.json();

data.value_bets.forEach(bet => {
  console.log(`${bet.horse_name}: Miser ${bet.kelly_data.recommended_stake}€`);
  console.log(`EV: +${bet.kelly_data.expected_value}%`);
});
```

---

### 2. Combinaisons Tiercé

**GET** `/api/v1/pmu/races/{raceId}/combinations/tierce?ordre=false&limit=10`

Génère les meilleures combinaisons Tiercé.

**Paramètres** :
- `raceId` (required) : ID de la course
- `ordre` (optional, default: false) : Ordre (true) ou désordre (false)
- `limit` (optional, default: 10) : Nombre de combinaisons à retourner

**Réponse** :
```json
{
  "race_id": 123,
  "type": "TIERCE_DESORDRE",
  "combinations": [
    {
      "type": "TIERCE_DESORDRE",
      "horses": ["Lightning Bolt", "Thunder Storm", "Wind Runner"],
      "horse_ids": ["456", "457", "458"],
      "probability": 8.45,
      "estimated_odds": 42.5,
      "base_ranks": [1, 2, 3],
      "ev_analysis": {
        "stake": 2,
        "estimated_payout": 50,
        "probability": 8.45,
        "expected_gain": 4.23,
        "expected_loss": 1.83,
        "expected_value": 2.40,
        "ev_percentage": 120.0,
        "is_profitable": true
      }
    }
  ],
  "best_combination": { /* première combinaison */ },
  "metadata": {
    "generated_at": "2025-01-22T...",
    "total_combinations": 10
  }
}
```

**Exemple d'utilisation** :
```javascript
const response = await fetch('/api/v1/pmu/races/123/combinations/tierce?ordre=false&limit=5');
const data = await response.json();

console.log('Top 5 Tiercé Désordre:');
data.combinations.forEach((combo, i) => {
  console.log(`${i+1}. ${combo.horses.join(' - ')}`);
  console.log(`   Probabilité: ${combo.probability}%`);
  console.log(`   EV: ${combo.ev_analysis.ev_percentage}%`);
});
```

---

### 3. Combinaisons Quinté

**GET** `/api/v1/pmu/races/{raceId}/combinations/quinte?limit=10`

Génère les meilleures combinaisons Quinté+ désordre.

**Paramètres** :
- `raceId` (required) : ID de la course
- `limit` (optional, default: 10) : Nombre de combinaisons

**Réponse** :
```json
{
  "race_id": 123,
  "type": "QUINTE_DESORDRE",
  "combinations": [
    {
      "type": "QUINTE_DESORDRE",
      "horses": ["Horse1", "Horse2", "Horse3", "Horse4", "Horse5"],
      "horse_ids": ["h1", "h2", "h3", "h4", "h5"],
      "probability": 2.15,
      "estimated_odds": 465.0,
      "base_ranks": [1, 2, 3, 4, 5],
      "ev_analysis": {
        "stake": 2,
        "estimated_payout": 500,
        "probability": 2.15,
        "expected_gain": 10.75,
        "expected_loss": 1.96,
        "expected_value": 8.79,
        "ev_percentage": 439.5,
        "is_profitable": true
      }
    }
  ],
  "best_combination": { /* ... */ },
  "metadata": { /* ... */ }
}
```

---

### 4. Stratégie Globale

**GET** `/api/v1/pmu/races/{raceId}/strategy?budget=50`

Recommande la meilleure stratégie de paris pour un budget donné.

**Paramètres** :
- `raceId` (required) : ID de la course
- `budget` (optional, default: 50) : Budget disponible en €

**Réponse** :
```json
{
  "race_id": 123,
  "budget": 50,
  "strategy": {
    "recommendations": [
      {
        "type": "TIERCE_DESORDRE",
        "combination": { /* ... */ },
        "ev_data": { /* ... */ },
        "priority": 120.5
      },
      {
        "type": "QUINTE_DESORDRE",
        "combination": { /* ... */ },
        "ev_data": { /* ... */ },
        "priority": 85.3
      }
    ],
    "budget_distribution": [
      {
        "type": "TIERCE_DESORDRE",
        "horses": ["Horse1", "Horse2", "Horse3"],
        "stake": 4,
        "expected_value": 4.82
      },
      {
        "type": "QUINTE_DESORDRE",
        "horses": ["H1", "H2", "H3", "H4", "H5"],
        "stake": 4,
        "expected_value": 17.58
      }
    ],
    "total_expected_value": 22.40
  },
  "summary": {
    "total_bets": 2,
    "total_stake": 8,
    "remaining_budget": 42,
    "total_expected_profit": 22.40,
    "roi_estimate": "280%",
    "risk_level": "HIGH"
  }
}
```

**Exemple d'utilisation** :
```javascript
const response = await fetch('/api/v1/pmu/races/123/strategy?budget=50');
const data = await response.json();

console.log(`Budget: ${data.budget}€`);
console.log(`Mises totales: ${data.summary.total_stake}€`);
console.log(`Profit attendu: ${data.summary.total_expected_profit}€`);
console.log(`ROI: ${data.summary.roi_estimate}`);

data.strategy.budget_distribution.forEach(bet => {
  console.log(`\n${bet.type}:`);
  console.log(`Chevaux: ${bet.horses.join(', ')}`);
  console.log(`Mise: ${bet.stake}€`);
});
```

---

### 5. Simulation de Stratégies

**POST** `/api/v1/pmu/races/{raceId}/simulate`

Compare différentes stratégies de paris.

**Body** :
```json
{
  "bankroll": 100,
  "strategies": [
    {
      "type": "value_bets",
      "budget": 50
    },
    {
      "type": "tierce",
      "budget": 30
    },
    {
      "type": "quinte",
      "budget": 20
    }
  ]
}
```

**Réponse** :
```json
{
  "race_id": 123,
  "bankroll": 100,
  "simulations": [
    {
      "type": "VALUE_BETS",
      "budget": 50,
      "expected_return": 15.5,
      "risk_level": "MEDIUM"
    },
    {
      "type": "TIERCE",
      "budget": 30,
      "expected_return": 8.2,
      "risk_level": "HIGH",
      "combinations_count": 5
    },
    {
      "type": "QUINTE",
      "budget": 20,
      "expected_return": 22.1,
      "risk_level": "VERY_HIGH",
      "combinations_count": 3
    }
  ],
  "best_strategy": {
    "type": "QUINTE",
    "budget": 20,
    "expected_return": 22.1,
    "risk_level": "VERY_HIGH"
  }
}
```

---

## Exemples Frontend Vue.js

### Composable pour Value Bets

```javascript
// composables/useValueBets.js
import { ref } from 'vue';

export function useValueBets() {
  const valueBets = ref([]);
  const loading = ref(false);
  const error = ref(null);

  const fetchValueBets = async (raceId, bankroll = 1000) => {
    loading.value = true;
    error.value = null;

    try {
      const response = await fetch(
        `/api/v1/pmu/races/${raceId}/value-bets?bankroll=${bankroll}`
      );
      
      if (!response.ok) throw new Error('Failed to fetch value bets');
      
      const data = await response.json();
      valueBets.value = data.value_bets;
      
      return data;
    } catch (err) {
      error.value = err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  return {
    valueBets,
    loading,
    error,
    fetchValueBets
  };
}
```

### Composable pour Combinaisons

```javascript
// composables/useCombinations.js
import { ref } from 'vue';

export function useCombinations() {
  const combinations = ref([]);
  const loading = ref(false);

  const fetchTierce = async (raceId, ordre = false) => {
    loading.value = true;
    
    try {
      const response = await fetch(
        `/api/v1/pmu/races/${raceId}/combinations/tierce?ordre=${ordre}&limit=10`
      );
      
      const data = await response.json();
      combinations.value = data.combinations;
      
      return data;
    } finally {
      loading.value = false;
    }
  };

  const fetchQuinte = async (raceId) => {
    loading.value = true;
    
    try {
      const response = await fetch(
        `/api/v1/pmu/races/${raceId}/combinations/quinte?limit=10`
      );
      
      const data = await response.json();
      combinations.value = data.combinations;
      
      return data;
    } finally {
      loading.value = false;
    }
  };

  return {
    combinations,
    loading,
    fetchTierce,
    fetchQuinte
  };
}
```

### Composant Vue pour afficher les Value Bets

```vue
<!-- components/ValueBets.vue -->
<template>
  <div class="value-bets">
    <h2>Value Bets (Kelly Criterion)</h2>
    
    <div class="controls">
      <label>
        Bankroll:
        <input v-model.number="bankroll" type="number" min="10" />€
      </label>
      <button @click="loadValueBets" :disabled="loading">
        {{ loading ? 'Chargement...' : 'Analyser' }}
      </button>
    </div>

    <div v-if="data">
      <div class="summary">
        <p><strong>{{ data.summary.count }}</strong> value bets détectés</p>
        <p>Mise totale: <strong>{{ data.summary.total_stake }}€</strong></p>
        <p>EV Total: <strong>+{{ data.summary.total_expected_value }}</strong></p>
      </div>

      <div class="bets-list">
        <div 
          v-for="bet in data.value_bets" 
          :key="bet.horse_id"
          class="bet-card"
        >
          <div class="horse-name">{{ bet.horse_name }}</div>
          <div class="bet-details">
            <span>Cote: {{ bet.odds }}</span>
            <span>Proba: {{ bet.probability }}%</span>
          </div>
          <div class="kelly-info">
            <div class="stake">
              Miser: <strong>{{ bet.kelly_data.recommended_stake }}€</strong>
            </div>
            <div class="ev">
              EV: <strong class="positive">+{{ bet.kelly_data.expected_value }}%</strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useValueBets } from '@/composables/useValueBets';

const props = defineProps({
  raceId: {
    type: Number,
    required: true
  }
});

const { fetchValueBets, loading } = useValueBets();
const bankroll = ref(1000);
const data = ref(null);

const loadValueBets = async () => {
  data.value = await fetchValueBets(props.raceId, bankroll.value);
};
</script>

<style scoped>
.value-bets {
  padding: 20px;
  background: white;
  border-radius: 8px;
}

.controls {
  display: flex;
  gap: 15px;
  margin-bottom: 20px;
  align-items: center;
}

.summary {
  background: #e3f2fd;
  padding: 15px;
  border-radius: 5px;
  margin-bottom: 20px;
}

.bets-list {
  display: grid;
  gap: 15px;
}

.bet-card {
  border: 1px solid #ddd;
  padding: 15px;
  border-radius: 5px;
  background: #f9f9f9;
}

.horse-name {
  font-size: 18px;
  font-weight: bold;
  margin-bottom: 10px;
}

.bet-details {
  display: flex;
  gap: 20px;
  color: #666;
  margin-bottom: 10px;
}

.kelly-info {
  display: flex;
  justify-content: space-between;
  padding-top: 10px;
  border-top: 1px solid #ddd;
}

.stake {
  color: #1976d2;
}

.ev .positive {
  color: #2e7d32;
}
</style>
```

---

## Tests

### Test avec cURL

```bash
# Value Bets
curl "http://localhost:8000/api/v1/pmu/races/1/value-bets?bankroll=1000"

# Tiercé Désordre
curl "http://localhost:8000/api/v1/pmu/races/1/combinations/tierce?ordre=false&limit=5"

# Quinté
curl "http://localhost:8000/api/v1/pmu/races/1/combinations/quinte?limit=3"

# Stratégie
curl "http://localhost:8000/api/v1/pmu/races/1/strategy?budget=50"

# Simulation
curl -X POST "http://localhost:8000/api/v1/pmu/races/1/simulate" \
  -H "Content-Type: application/json" \
  -d '{
    "bankroll": 100,
    "strategies": [
      {"type": "value_bets", "budget": 50},
      {"type": "tierce", "budget": 30}
    ]
  }'
```

---

## Cas d'Usage Réels

### Scénario 1: Parieur Conservateur
```javascript
// Budget: 100€, Risk: LOW
const data = await fetch('/api/v1/pmu/races/123/value-bets?bankroll=100');
const valueBets = await data.json();

// Miser uniquement sur les value bets avec EV > 15%
valueBets.value_bets
  .filter(bet => bet.kelly_data.expected_value > 15)
  .forEach(bet => {
    console.log(`Miser ${bet.kelly_data.recommended_stake}€ sur ${bet.horse_name}`);
  });
```

### Scénario 2: Parieur Agressif
```javascript
// Budget: 50€, Risk: HIGH
const strategy = await fetch('/api/v1/pmu/races/123/strategy?budget=50');
const data = await strategy.json();

// Suivre la distribution recommandée
data.strategy.budget_distribution.forEach(bet => {
  console.log(`${bet.type}: ${bet.stake}€ sur ${bet.horses.join(', ')}`);
});
```

### Scénario 3: Optimisation
```javascript
// Comparer plusieurs stratégies
const response = await fetch('/api/v1/pmu/races/123/simulate', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    bankroll: 200,
    strategies: [
      { type: 'value_bets', budget: 100 },
      { type: 'tierce', budget: 50 },
      { type: 'quinte', budget: 50 }
    ]
  })
});

const sim = await response.json();
console.log('Meilleure stratégie:', sim.best_strategy.type);
console.log('Retour attendu:', sim.best_strategy.expected_return);
```

---

## Performance & Optimisation

### Mise en Cache
Les combinaisons étant gourmandes, pensez à cacher :

```php
// Dans AdvancedPMUController
use Illuminate\Support\Facades\Cache;

public function getQuinteCombinations(int $raceId, Request $request): JsonResponse
{
    $cacheKey = "quinte_combos_{$raceId}";
    
    $combinations = Cache::remember($cacheKey, 3600, function() use ($raceId, $request) {
        $predictions = $this->stats->getRacePredictions($raceId);
        return $this->combinations->generateQuinteDesordre($predictions, 10);
    });
    
    // ...
}
```

### Rate Limiting
Ajoutez un throttle plus strict sur les endpoints gourmands :

```php
Route::get('/races/{raceId}/combinations/quinte', [...])
    ->middleware('throttle:10,1'); // 10 requêtes/minute max
```

---

## Prochaines Étapes

1. Implémenter le backtesting pour valider Kelly
2. Ajouter d'autres types de paris (2sur4, Multi, etc.)
3. Créer un dashboard de suivi des performances
4. Intégrer des données météo en temps réel
5. Machine Learning pour améliorer les probabilités