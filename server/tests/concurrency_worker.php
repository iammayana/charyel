<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\CompetitiveXpAccountingService;
use Carbon\Carbon;
use Tests\Support\DatabaseSafetyGuard;

// Enforce fail-closed database safety guard before any database mutation
DatabaseSafetyGuard::assertDestructiveOperationsAllowed();

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$requestedXp = isset($argv[2]) ? (int) $argv[2] : 0;
$sourceId = $argv[3] ?? 'test_src';
$eventId = $argv[4] ?? '';
$dateStr = $argv[5] ?? '2026-10-16 12:00:00';

$service = new CompetitiveXpAccountingService();

try {
    $result = $service->awardCompetitiveXp(
        userId: $userId,
        guestIdentityId: null,
        requestedXp: $requestedXp,
        rewardSource: 'quiz_completion',
        sourceId: $sourceId,
        eventId: $eventId,
        receivedAt: Carbon::parse($dateStr, 'UTC')
    );
    echo json_encode(['status' => 'ok', 'result' => $result]);
    exit(0);
} catch (\Throwable $e) {
    $sanitize = static function (?string $msg): ?string {
        if ($msg === null) {
            return null;
        }
        return preg_replace('/(password[:=]\s*)[^\s;,\)]+/i', '$1[REDACTED]', $msg);
    };

    echo json_encode([
        'status' => 'error',
        'message' => $sanitize($e->getMessage()),
        'exception' => get_class($e),
        'file' => basename($e->getFile()) . ':' . $e->getLine(),
        'previous' => $sanitize($e->getPrevious()?->getMessage()),
    ]);
    exit(1);
}
