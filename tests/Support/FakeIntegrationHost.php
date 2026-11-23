<?php

// Goes in: tests/Support/FakeIntegrationHost.php (new file). Test double for the wiring adapter.

namespace Tests\Support;

use App\Services\Integrations\IntegrationHost;
use Illuminate\Http\Request;

class FakeIntegrationHost extends IntegrationHost
{
    /** @var array<int,int> business id => points */
    public array $points = [];

    /** @var array<int,int> user id => business id */
    public array $userBusiness = [];

    public array $audits = [];

    public array $tasks = [];

    public function pointsAvailable(int $businessId): int
    {
        return $this->points[$businessId] ?? 0;
    }

    public function currentBusinessId(Request $request): int
    {
        $id = $this->userBusiness[$request->user()?->getKey()] ?? 0;
        abort_if($id <= 0, 403);

        return $id;
    }

    public function businessPublicId(int $businessId): string
    {
        return 'biz_public_'.$businessId;
    }

    public function audit(int $businessId, ?int $userId, string $action, array $meta = []): void
    {
        $this->audits[] = [$businessId, $action, $meta];
    }

    public function workInboxTask(int $businessId, string $title, string $body): void
    {
        $this->tasks[] = [$businessId, $title];
    }
}
