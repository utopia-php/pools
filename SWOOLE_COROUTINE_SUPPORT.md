# Swoole Coroutine Support for Connection Pools

## Problem

When using the Pool class in a Swoole coroutine environment (e.g., WebSocket servers), race conditions occurred because multiple coroutines could simultaneously access and modify the internal `$pool` array using `array_pop()` and array operations, which are not atomic in a concurrent context.

### Symptoms
- "Pool is empty" errors even when connections should be available
- Multiple coroutines potentially receiving the same connection
- Inconsistent pool state

## Solution

The Pool class now automatically detects when it's running in a Swoole coroutine context and switches to using coroutine-safe mechanisms:

### 1. **Swoole\Coroutine\Channel for Pool Management**
- Channels are thread-safe and coroutine-safe by design
- Lazy initialization: Channel is created only when first accessed from within a coroutine
- Seamless migration: Existing array-based pool items are migrated to the channel

### 2. **Swoole\Lock for Active Connections Tracking**
- Protects the `$active` array from concurrent modifications
- Ensures connection IDs are tracked correctly across coroutines

### 3. **Swoole\Atomic for Connection ID Generation**
- Generates unique, collision-free connection IDs across coroutines
- Uses high-resolution time (`hrtime()`) and coroutine ID for uniqueness

### 4. **Coroutine-Aware Sleep**
- Uses `\Swoole\Coroutine::sleep()` when in coroutine context
- Falls back to regular `sleep()` in non-coroutine environments

## Implementation Details

### Automatic Detection
```php
// Pool automatically detects coroutine context
$pool = new Pool('my-pool', 5, fn() => createConnection());

// In coroutine context: uses Channel + Lock
// In regular context: uses array-based approach
```

### Key Changes

1. **Constructor**: No longer initializes channel (must be done in coroutine context)

2. **ensureChannel()**: Lazily initializes channel when first operation occurs in coroutine

3. **pop()**: 
   - Calls `ensureChannel()` to switch to channel mode if needed
   - Uses `popWithChannel()` for coroutine-safe operations
   - Falls back to array-based for non-coroutine contexts

4. **push()**: 
   - Protects `$active` array with lock
   - Pushes connection back to channel (or array)

5. **Connection ID Generation**:
   - Uses `Swoole\Atomic` for thread-safe counter
   - Includes coroutine ID and high-resolution timestamp

## Backward Compatibility

✅ **Fully backward compatible**
- Non-coroutine code continues to work exactly as before
- No breaking changes to the API
- Automatic detection and switching between modes

## Testing

Three comprehensive tests verify coroutine safety:

1. **testSwooleCoroutineRaceCondition**: Verifies 10 concurrent coroutines can safely share a pool of 5 connections
2. **testSwooleCoroutineHighConcurrency**: Tests 20 coroutines with a pool of 3 under high load
3. **testSwooleCoroutineConnectionUniqueness**: Ensures connections remain unique when acquired simultaneously

## Performance

- **Channel operations**: O(1) for push/pop
- **Lock overhead**: Minimal, only for `$active` array access
- **No performance impact**: When not using coroutines (automatic fallback)

## Requirements

- PHP 8.3+
- Swoole extension (optional, for coroutine support)
- `swoole/ide-helper` (dev dependency for IDE support)

## Usage Example

```php
use Utopia\Pools\Pool;
use Swoole\Coroutine;

// Create pool
$pool = new Pool('redis', 10, function() {
    return new Redis();
});

// Use in Swoole coroutine environment
Coroutine\run(function() use ($pool) {
    // Spawn multiple coroutines
    for ($i = 0; $i < 100; $i++) {
        Coroutine::create(function() use ($pool, $i) {
            // Safely get connection
            $pool->use(function($redis) use ($i) {
                $redis->set("key-{$i}", "value-{$i}");
            });
        });
    }
});
```

## Migration Guide

No migration needed! Existing code works without changes:

```php
// Before (still works)
$connection = $pool->pop();
// ... use connection ...
$pool->push($connection);

// Recommended (automatic cleanup)
$pool->use(function($resource) {
    // ... use resource ...
});
```

