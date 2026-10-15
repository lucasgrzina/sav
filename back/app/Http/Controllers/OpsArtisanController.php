<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a whitelisted artisan command for hosts without shell access.
 * Disabled (404) unless OPS_ARTISAN_TOKEN is set; see config/ops.php.
 */
class OpsArtisanController extends Controller
{
    public function __invoke(Request $request, string $command): JsonResponse
    {
        $expected = (string) config('ops.artisan_token');
        $provided = (string) $request->bearerToken();

        // 404 (not 401/403) so the endpoint does not reveal itself to scanners.
        abort_if($expected === '' || ! hash_equals($expected, $provided), 404);

        // Exact key lookup: config() dot notation would let "name.0" reach nested values.
        $commands = (array) config('ops.artisan_commands');
        abort_if(! array_key_exists($command, $commands), 404);

        [$artisanCommand, $arguments] = $commands[$command];

        Log::warning('Remote artisan command executed', [
            'command' => $artisanCommand,
            'ip' => $request->ip(),
        ]);

        // Shared hosts often disable proc_open/proc_close, which Symfony Console would use to
        // measure the terminal. With both variables set it never spawns a process.
        putenv('COLUMNS=120');
        putenv('LINES=50');

        try {
            $exitCode = Artisan::call($artisanCommand, $arguments);
        } catch (Throwable $e) {
            // The message can leak paths, SQL or credentials: log it, do not return it.
            report($e);

            return response()->json([
                'command' => $artisanCommand,
                'exit_code' => 1,
                'error' => 'Command failed, check the application log.',
            ], 500);
        }

        return response()->json([
            'command' => $artisanCommand,
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ]);
    }
}
