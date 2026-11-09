<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\ConsoleLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConsoleLockController extends Controller
{
    /**
     * Unlock the service console with a fresh two-factor code.
     */
    #[Audited]
    public function unlock(Request $request, Workspace $workspace, ConsoleLock $lock): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        if ($lock->requiresSetup($request->user())) {
            return response()->json(['error' => 'Turn on two-factor authentication to use the console.', 'code' => 'two_factor_required'], 403);
        }

        if (! $lock->unlock($request->user(), $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code didn’t work. Use the current one from your authenticator app.']);
        }
        Activity::record($workspace, 'console.unlock', meta: ['minutes' => ConsoleLock::MINUTES]);

        return response()->json(['unlockedUntil' => $lock->unlockedUntil()?->toIso8601String()]);
    }

    /**
     * Lock the console again before the window runs out.
     */
    #[NotAudited('Ends the console window early; the unlock is what gets recorded')]
    public function lock(Workspace $workspace, ConsoleLock $lock): JsonResponse
    {
        $lock->lock();

        return response()->json(['unlockedUntil' => null]);
    }
}
