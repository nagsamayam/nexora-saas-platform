<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Application\Tenancy\ApproveTenantService;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TenantResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTenantController extends Controller
{
    /**
     * Approve a pending tenant and initiate provisioning (Platform Admin / Super Admin only).
     */
    public function approve(
        string $tenantId,
        Request $request,
        ApproveTenantService $service,
    ): JsonResponse {
        /** @var User $currentUser */
        $currentUser = $request->user();

        // Ensure user has SuperAdmin or Support/Platform admin role
        if (! $currentUser->hasPlatformRole(PlatformRole::SuperAdmin)) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'You do not have permission to perform this action.',
                    'details' => null,
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Tenant not found.',
                    'details' => null,
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $sync = $request->boolean('sync', false);
        $approvedTenant = $service->approve($currentUser, $tenant->id, $sync);

        return ApiResponse::success(
            data: new TenantResource($approvedTenant),
            meta: ['message' => 'Tenant has been approved and provisioning initiated.'],
        );
    }
}
