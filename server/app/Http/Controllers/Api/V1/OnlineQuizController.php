<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OnlineQuizException;
use App\Http\Controllers\Controller;
use App\Models\ActiveOnlineQuizSession;
use App\Services\Quiz\OnlineQuizLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnlineQuizController extends Controller
{
    public function __construct(
        protected OnlineQuizLifecycleService $lifecycleService
    ) {}

    /**
     * Start and reserve a new online quiz session for the authenticated guest.
     *
     * POST /api/v1/quiz/online/start
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quiz_mode' => ['nullable', 'string', Rule::in(ActiveOnlineQuizSession::ALLOWED_QUIZ_MODES)],
            'difficulty' => ['nullable', 'string', Rule::in(ActiveOnlineQuizSession::ALLOWED_DIFFICULTIES)],
            'category' => ['nullable', 'string', 'max:50'],
            'testament' => ['nullable', 'string', Rule::in(['OT', 'NT', 'BOTH'])],
            'device_id' => ['nullable', 'string', 'max:64'],
            'question_count' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if (empty($validated['device_id'])) {
            $validated['device_id'] = $request->header('X-Device-Id', 'device_default');
        }

        try {
            $sessionData = $this->lifecycleService->startSession($request->user(), $validated);

            return response()->json([
                'status' => 'success',
                'data' => $sessionData,
            ], 201);
        } catch (OnlineQuizException $e) {
            return response()->json([
                'status' => 'error',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ], $e->statusCode);
        }
    }

    /**
     * Authoritatively validate, score, settle XP, and complete an online quiz session.
     *
     * POST /api/v1/quiz/online/complete
     */
    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'uuid'],
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'string'],
            'answers.*.selected_option_id' => ['required', 'string'],
            'answers.*.time_taken_ms' => ['nullable', 'integer', 'min:0'],
            'event_id' => ['nullable', 'string', 'uuid'],
        ]);

        try {
            $result = $this->lifecycleService->completeSession(
                guest: $request->user(),
                sessionId: $validated['session_id'],
                submittedAnswers: $validated['answers'],
                eventId: $validated['event_id'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ], 200);
        } catch (OnlineQuizException $e) {
            return response()->json([
                'status' => 'error',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ], $e->statusCode);
        }
    }

    /**
     * Abandon an active online quiz session safely.
     *
     * POST /api/v1/quiz/online/abandon
     */
    public function abandon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'uuid'],
        ]);

        try {
            $result = $this->lifecycleService->abandonSession(
                guest: $request->user(),
                sessionId: $validated['session_id']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Quiz session abandoned successfully.',
                'data' => $result,
            ], 200);
        } catch (OnlineQuizException $e) {
            return response()->json([
                'status' => 'error',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ], $e->statusCode);
        }
    }

    /**
     * Return authoritative remaining trial capacity and active reservation status.
     *
     * GET /api/v1/quiz/online/trial-status
     */
    public function trialStatus(Request $request): JsonResponse
    {
        try {
            $status = $this->lifecycleService->getTrialStatus($request->user());

            return response()->json([
                'status' => 'success',
                'data' => $status,
            ], 200);
        } catch (OnlineQuizException $e) {
            return response()->json([
                'status' => 'error',
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ], $e->statusCode);
        }
    }
}
