# AI API Key Round-Robin Implementation

## Overview

The system now supports multiple AI providers (Gemini and Perplexity) with automatic round-robin load balancing.

## Components

### 1. AiApiKeyService (`app/Services/AiApiKeyService.php`)

- **Purpose**: Manages round-robin selection of active API keys
- **Key Methods**:
  - `getNextApiKey()`: Returns next active API key using round-robin
  - `getKeyByModel($model)`: Get specific API key by model name
  - `resetRoundRobin()`: Reset the round-robin counter

### 2. Updated SeoGeneratorService (`app/Services/SeoGeneratorService.php`)

- **Changes**:
  - Now uses `AiApiKeyService` for dynamic API key selection
  - Supports both Gemini and Perplexity APIs
  - Automatically routes requests based on model name
  - Falls back to manual SEO generation if no keys available

### 3. AI Provider Support

- **Gemini**: Models containing "gemini" (e.g., `gemini-2.5-flash`)
- **Perplexity**: Models containing "perplexity" (e.g., `perplexity-sonar`)

## How It Works

1. When `generateEventSeo()` is called, it requests the next API key via round-robin
2. Only API keys with `status = true` are used
3. The system detects the provider based on the model name
4. Requests are routed to the appropriate API (Gemini or Perplexity)
5. The round-robin index increments for the next request
6. If all API calls fail, fallback SEO is generated

## Database Setup

### Example API Keys in Database:

```sql
INSERT INTO ai_api_keys (model, apikey, status) VALUES
('gemini-2.5-flash', 'YOUR_GEMINI_API_KEY', true),
('perplexity-sonar', 'YOUR_PERPLEXITY_API_KEY', true);
```

## Usage Flow

```
Request 1 → Gemini API (index 0)
Request 2 → Perplexity API (index 1)
Request 3 → Gemini API (index 0)
Request 4 → Perplexity API (index 1)
...and so on
```

## Adding New Providers

To add a new AI provider:

1. Add the API key to the database with appropriate model name
2. Add a new method in `SeoGeneratorService` (e.g., `callOpenAI()`)
3. Update `callAiProvider()` to route to the new provider
4. Ensure the model name contains a unique identifier

## Testing

Test the round-robin behavior:

```bash
# Call the SEO generation endpoint multiple times
curl -X GET http://your-domain/api/dark/ai-data/generate-seo/{event_key}
```

Check logs to see which API key is being used for each request.

## Cache Management

- Round-robin index is cached for 1 hour
- To reset: Call `AiApiKeyService::resetRoundRobin()`
- Cache key: `ai_api_key_round_robin_index`
