<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\InsightTask;
use App\Services\InsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * M12 Sales Growth (বিক্রি বাড়ান) — a Marketing subsection.
 * Page access rides the RouteRegistry 'marketing' key (PermissionGate);
 * mutating actions additionally pass through TokenGate (view-only
 * sellers cannot create drafts or log calls). Revenue estimates are
 * only ever sent to owner/admin roles.
 */
class GrowthController extends Controller
{
    public function __construct(private readonly InsightService $insights) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function canSeeRevenue(Request $request): bool
    {
        $u = $request->user();

        return $u->is_platform_admin || $u->role === 'admin';
    }

    private function ownTask(Request $request, InsightTask $task): InsightTask
    {
        abort_unless((int) $task->business_id === $this->biz($request), 403);

        return $task;
    }

    private function ownConversation(Request $request, Conversation $conversation): Conversation
    {
        abort_unless((int) $conversation->business_id === $this->biz($request), 403);

        return $conversation;
    }

    public function index(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $period = (string) $request->query('period', '30d');
        if (! isset(InsightService::PERIODS[$period])) {
            $period = '30d';
        }

        return Inertia::render('Marketing/Growth', [
            'summary' => $this->insights->summary($biz, $period),
            'tasks' => $this->insights->tasksFor($biz, $this->canSeeRevenue($request)),
            'can_revenue' => $this->canSeeRevenue($request),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', '30d');
        if (! isset(InsightService::PERIODS[$period])) {
            $period = '30d';
        }

        return response()->json($this->insights->summary($this->biz($request), $period));
    }

    public function tasks(Request $request): JsonResponse
    {
        return response()->json(['tasks' => $this->insights->tasksFor($this->biz($request), $this->canSeeRevenue($request))]);
    }

    public function taskStatus(Request $request, InsightTask $task): JsonResponse
    {
        $this->ownTask($request, $task);
        $data = $request->validate(['status' => ['required', 'in:open,done,snoozed']]);
        $task->status = $data['status'];
        $task->save();

        return response()->json(['ok' => true, 'status' => $task->status]);
    }

    public function taskCustomers(Request $request, InsightTask $task): JsonResponse
    {
        $this->ownTask($request, $task);

        return response()->json(['customers' => $this->insights->taskCustomers($task)]);
    }

    public function taskDrafts(Request $request, InsightTask $task): JsonResponse
    {
        $this->ownTask($request, $task);
        $data = $request->validate(['conversation_id' => ['nullable', 'integer']]);
        if (! empty($data['conversation_id'])) {
            $conv = $this->ownConversation($request, Conversation::findOrFail($data['conversation_id']));
            $msg = $this->insights->createDraft($task, $conv, (int) $request->user()->id);

            return response()->json(['created' => $msg ? 1 : 0, 'skipped' => $msg ? 0 : 1]);
        }

        return response()->json($this->insights->createDraftsForTask($task, (int) $request->user()->id));
    }

    public function taskCalls(Request $request, InsightTask $task): JsonResponse
    {
        $this->ownTask($request, $task);
        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'outcome' => ['nullable', 'string', 'max:120'],
        ]);
        $conv = $this->ownConversation($request, Conversation::findOrFail($data['conversation_id']));
        $this->insights->logCall($task, $conv, (int) $request->user()->id, $data['outcome'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function why(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ownConversation($request, $conversation);

        return response()->json($this->insights->why($conversation));
    }
}
