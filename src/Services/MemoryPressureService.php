<?php
/**
 * =============================================================================
 * MEMORY PRESSURE SERVICE — Memory Allocation Simulation
 * =============================================================================
 *
 * PURPOSE:
 *   Simulates memory pressure by allocating and retaining data in shared
 *   storage (APCu or temp files). Memory is held until explicitly released
 *   by the user via the DELETE endpoint.
 *
 * PHP vs NODE.JS DIFFERENCES:
 *   - Node.js allocates objects on the V8 heap within a persistent process.
 *   - PHP-FPM workers are request-scoped; process memory is released when
 *     the request completes. For persistent memory pressure, we use APCu
 *     (shared memory segment) or temp files.
 *   - APCu memory is shared across ALL PHP-FPM workers, so allocations
 *     affect the entire application pool.
 *   - When APCu is unavailable, large temp files simulate disk-based
 *     memory pressure (OS page cache / mmap).
 *
 * ALLOCATION STRATEGY:
 *   1. APCu available: Store string blobs in APCu shared memory.
 *      This directly consumes the APCu shared memory segment (apc.shm_size).
 *      Visible in APCu metrics and overall PHP-FPM memory.
 *   2. APCu unavailable: Create temp files filled with random data.
 *      The OS page cache makes these consume physical memory.
 *      Tracked via SharedStorage (file-based).
 *
 * @module src/Services/MemoryPressureService.php
 */

declare(strict_types=1);

namespace PerfSimPhp\Services;

use PerfSimPhp\SharedStorage;

class MemoryPressureService
{
    private const ALLOCATIONS_KEY = 'perfsim_memory_allocs';

    /**
     * Allocates memory and starts a memory pressure simulation.
     *
     * @param array{sizeMb: int} $params
     * @return array The created simulation record
     */
    public static function allocate(array $params): array
    {
        $sizeMb = $params['sizeMb'];

        // Create simulation record (no auto-expiry for memory allocations)
        $simulation = SimulationTrackerService::createSimulation(
            'MEMORY_PRESSURE',
            ['type' => 'MEMORY_PRESSURE', 'sizeMb' => $sizeMb],
            null // No duration — released manually
        );

        $id = $simulation['id'];

        // Log start
        EventLogService::info(
            'MEMORY_ALLOCATING',
            "Starting allocation of {$sizeMb}MB...",
            $id,
            'MEMORY_PRESSURE',
            ['sizeMb' => $sizeMb]
        );

        // Perform the allocation
        $method = self::performAllocation($id, $sizeMb);

        // Track this allocation
        SharedStorage::modify(self::ALLOCATIONS_KEY, function (?array $allocs) use ($id, $sizeMb, $method) {
            $allocs = $allocs ?? [];
            $allocs[$id] = [
                'sizeMb' => $sizeMb,
                'method' => $method,
                'timestamp' => date('c'),
            ];
            return $allocs;
        }, []);

        // Log completion
        EventLogService::info(
            'MEMORY_ALLOCATED',
            "Allocated {$sizeMb}MB via {$method}",
            $id,
            'MEMORY_PRESSURE',
            ['sizeMb' => $sizeMb, 'method' => $method]
        );

        return $simulation;
    }

    /**
     * Releases a memory allocation.
     *
     * @param string $id Simulation/allocation ID
     * @return array|null Release info or null if not found
     */
    public static function release(string $id): ?array
    {
        $allocs = SharedStorage::get(self::ALLOCATIONS_KEY, []);
        $allocInfo = $allocs[$id] ?? null;
        $sizeMb = $allocInfo['sizeMb'] ?? 0;
        $method = $allocInfo['method'] ?? 'unknown';
        $wasAllocated = $allocInfo !== null;

        // Release the actual memory
        if ($wasAllocated) {
            self::performRelease($id, $method);

            // Remove from tracking
            SharedStorage::modify(self::ALLOCATIONS_KEY, function (?array $allocs) use ($id) {
                $allocs = $allocs ?? [];
                unset($allocs[$id]);
                return $allocs;
            }, []);
        }

        // Stop the simulation tracking
        $simulation = SimulationTrackerService::stopSimulation($id);

        if (!$wasAllocated && !$simulation) {
            return null;
        }

        // Log release
        EventLogService::info(
            'MEMORY_RELEASED',
            "Released {$sizeMb}MB of memory ({$method})",
            $id,
            'MEMORY_PRESSURE',
            ['sizeMb' => $sizeMb, 'wasAllocated' => $wasAllocated, 'method' => $method]
        );

        return [
            'simulation' => $simulation,
            'sizeMb' => $sizeMb,
            'wasAllocated' => $wasAllocated,
            'method' => $method,
        ];
    }

    /**
     * Gets all active memory allocations.
     */
    public static function getActiveAllocations(): array
    {
        return SimulationTrackerService::getActiveSimulationsByType('MEMORY_PRESSURE');
    }

    /**
     * Gets total allocated memory in MB.
     */
    public static function getTotalAllocatedMb(): int
    {
        $allocs = SharedStorage::get(self::ALLOCATIONS_KEY, []);
        $total = 0;
        foreach ($allocs as $alloc) {
            $total += $alloc['sizeMb'] ?? 0;
        }
        return $total;
    }

    /**
     * Gets allocation info for a specific simulation.
     */
    public static function getAllocationInfo(string $id): ?array
    {
        $allocs = SharedStorage::get(self::ALLOCATIONS_KEY, []);
        return $allocs[$id] ?? null;
    }

    /**
     * Releases all memory allocations, including orphaned ones.
     * 
     * This is the PRIMARY release method that ensures ALL memory is freed,
     * even if tracking records were lost (e.g., after App Service restart).
     * 
     * @return array{releasedCount: int, releasedMb: int, orphanedFiles: int, orphanedApcu: int}
     */
    public static function releaseAll(): array
    {
        $releasedCount = 0;
        $releasedMb = 0;
        
        // 1. Release all TRACKED allocations first
        $active = self::getActiveAllocations();
        foreach ($active as $alloc) {
            $result = self::release($alloc['id']);
            if ($result) {
                $releasedCount++;
                $releasedMb += $result['sizeMb'] ?? 0;
            }
        }
        
        // 2. Clean up ORPHANED allocations (files/APCu without tracking records)
        $orphanedFiles = self::cleanupOrphanedFiles();
        $orphanedApcu = self::cleanupOrphanedApcu();
        
        // 3. Force clear the allocations tracking key (belt and suspenders)
        SharedStorage::set(self::ALLOCATIONS_KEY, []);
        
        // Log the cleanup
        $orphanedTotal = $orphanedFiles + $orphanedApcu;
        if ($orphanedTotal > 0) {
            EventLogService::info(
                'MEMORY_ORPHANS_CLEANED',
                "Cleaned up {$orphanedTotal} orphaned allocation(s): {$orphanedFiles} files, {$orphanedApcu} APCu keys",
                null,
                'MEMORY_PRESSURE',
                ['orphanedFiles' => $orphanedFiles, 'orphanedApcu' => $orphanedApcu]
            );
        }
        
        return [
            'releasedCount' => $releasedCount,
            'releasedMb' => $releasedMb,
            'orphanedFiles' => $orphanedFiles,
            'orphanedApcu' => $orphanedApcu,
        ];
    }
    
    /**
     * Cleans up orphaned memblock files in storage directory.
     * 
     * These can accumulate when:
     * - App Service restarts (APCu tracking cleared, but files persist)
     * - Unexpected process termination
     * 
     * @return int Number of orphaned files deleted
     */
    private static function cleanupOrphanedFiles(): int
    {
        $storageDir = dirname(__DIR__, 2) . '/storage';
        $deleted = 0;
        
        if (!is_dir($storageDir)) {
            return 0;
        }
        
        // Find all memblock_*.bin files
        $files = glob($storageDir . '/memblock_*.bin');
        if ($files === false) {
            return 0;
        }
        
        foreach ($files as $file) {
            if (is_file($file) && unlink($file)) {
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Cleans up orphaned APCu memory blocks.
     * 
     * APCu stores memory in keys like: perfsim_memblock_{id}_{chunk}
     * This iterates through APCu and deletes all matching keys.
     * 
     * @return int Number of orphaned APCu keys deleted
     */
    private static function cleanupOrphanedApcu(): int
    {
        if (!function_exists('apcu_cache_info') || !function_exists('apcu_delete')) {
            return 0;
        }
        
        $deleted = 0;
        
        try {
            $info = apcu_cache_info();
            if (!isset($info['cache_list']) || !is_array($info['cache_list'])) {
                return 0;
            }
            
            foreach ($info['cache_list'] as $entry) {
                $key = $entry['info'] ?? $entry['key'] ?? null;
                if ($key && str_starts_with($key, 'perfsim_memblock_')) {
                    if (apcu_delete($key)) {
                        $deleted++;
                    }
                }
            }
        } catch (\Exception $e) {
            // APCu may throw exceptions if cache is corrupted
            // Fail silently — we tried our best
        }
        
        return $deleted;
    }

    /**
     * Gets the count of active allocations.
     */
    public static function getActiveCount(): int
    {
        $allocs = SharedStorage::get(self::ALLOCATIONS_KEY, []);
        return count($allocs);
    }

    /**
     * Loads allocated memory INTO the current PHP worker's heap.
     * 
     * This is crucial for realistic memory pressure simulation. Without this,
     * APCu data or temp files don't affect the worker's RSS/heap.
     * 
     * Returns the amount of memory actually loaded (may be capped to prevent OOM).
     * 
     * @param int $maxMb Maximum MB to load (default: 256MB to prevent worker OOM)
     * @return array{loadedMb: int, method: string, workerRssBefore: float, workerRssAfter: float}
     */
    public static function loadIntoWorker(int $maxMb = 256): array
    {
        $allocs = SharedStorage::get(self::ALLOCATIONS_KEY, []);
        if (empty($allocs)) {
            return ['loadedMb' => 0, 'method' => 'none', 'workerRssBefore' => 0, 'workerRssAfter' => 0];
        }

        // Measure RSS before
        $rssBefore = self::getWorkerRss();
        
        $loadedMb = 0;
        $method = 'none';
        $loadedData = []; // Hold references to prevent GC

        foreach ($allocs as $id => $allocInfo) {
            if ($loadedMb >= $maxMb) {
                break; // Cap to prevent worker OOM
            }

            $allocMethod = $allocInfo['method'] ?? 'unknown';
            $sizeMb = $allocInfo['sizeMb'] ?? 0;
            $toLoad = min($sizeMb, $maxMb - $loadedMb);

            if ($allocMethod === 'apcu' && function_exists('apcu_fetch')) {
                // Load APCu chunks into PHP memory
                for ($i = 0; $i < $toLoad && ($loadedMb + $i) < $maxMb; $i++) {
                    $key = "perfsim_memblock_{$id}_{$i}";
                    $data = apcu_fetch($key);
                    if ($data !== false) {
                        // Copy to local variable to force into PHP heap
                        $loadedData[] = $data;
                        $loadedMb++;
                    }
                }
                $method = 'apcu';
            } elseif ($allocMethod === 'file') {
                // Load file into PHP memory
                $file = dirname(__DIR__, 2) . "/storage/memblock_{$id}.bin";
                if (file_exists($file)) {
                    // Read in chunks to load into heap
                    $fp = fopen($file, 'rb');
                    if ($fp) {
                        for ($i = 0; $i < $toLoad && ($loadedMb + $i) < $maxMb; $i++) {
                            $chunk = fread($fp, 1024 * 1024); // 1MB
                            if ($chunk !== false && strlen($chunk) > 0) {
                                $loadedData[] = $chunk;
                                $loadedMb++;
                            } else {
                                break;
                            }
                        }
                        fclose($fp);
                    }
                    $method = 'file';
                }
            }
        }

        // Measure RSS after (data is still in $loadedData)
        $rssAfter = self::getWorkerRss();

        // Keep data alive until end of request (prevent GC optimization)
        // Store in static to ensure it survives this function
        static $memoryHold = null;
        $memoryHold = $loadedData;

        return [
            'loadedMb' => $loadedMb,
            'method' => $method,
            'workerRssBefore' => $rssBefore,
            'workerRssAfter' => $rssAfter,
        ];
    }

    /**
     * Get current worker's RSS in MB.
     */
    private static function getWorkerRss(): float
    {
        if (is_readable('/proc/self/status')) {
            $status = file_get_contents('/proc/self/status');
            if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
                return round((int)$matches[1] / 1024, 2);
            }
        }
        return round(memory_get_usage(true) / 1024 / 1024, 2);
    }

    // =========================================================================
    // INTERNAL: Allocation and Release
    // =========================================================================

    /**
     * Performs the actual memory allocation.
     *
     * @param string $id Allocation ID
     * @param int $sizeMb Size in megabytes
     * @return string The method used ('apcu' or 'file')
     */
    private static function performAllocation(string $id, int $sizeMb): string
    {
        if (function_exists('apcu_store')) {
            // APCu: store a large string blob in shared memory
            // Allocate in 1MB chunks to avoid exceeding single-item limits
            for ($i = 0; $i < $sizeMb; $i++) {
                $key = "perfsim_memblock_{$id}_{$i}";
                $data = str_repeat(chr(mt_rand(65, 90)), 1024 * 1024); // 1MB of random-ish data
                apcu_store($key, $data, 0); // TTL=0 means persistent
            }
            return 'apcu';
        }

        // Fallback: create a temp file
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . "/memblock_{$id}.bin";
        $fp = fopen($file, 'wb');
        if ($fp) {
            // Write in 1MB chunks
            $chunk = str_repeat("\0", 1024 * 1024);
            for ($i = 0; $i < $sizeMb; $i++) {
                fwrite($fp, $chunk);
            }
            fclose($fp);
        }

        return 'file';
    }

    /**
     * Releases the actual memory allocation.
     *
     * @param string $id Allocation ID
     * @param string $method Method used for allocation ('apcu' or 'file')
     */
    private static function performRelease(string $id, string $method): void
    {
        if ($method === 'apcu' && function_exists('apcu_delete')) {
            // Delete all chunks for this allocation
            // We don't know exactly how many chunks, so iterate with a reasonable upper bound
            for ($i = 0; $i < 10000; $i++) {
                $key = "perfsim_memblock_{$id}_{$i}";
                if (!apcu_exists($key)) {
                    break;
                }
                apcu_delete($key);
            }
            return;
        }

        if ($method === 'file') {
            $file = dirname(__DIR__, 2) . "/storage/memblock_{$id}.bin";
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
