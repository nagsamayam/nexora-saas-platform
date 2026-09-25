<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\OnboardTenantService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenancy\OnboardTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class TenantController extends Controller
{
    /**
     * Handle tenant onboarding request.
     */
    public function onboard(OnboardTenantRequest $request, OnboardTenantService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name: string, slug?: string|null} $validated */
        $validated = $request->validated();

        $tenant = $service->onboard($user, $validated);

        return ApiResponse::created(
            data: new TenantResource($tenant),
            message: 'Tenant organization successfully created.',
        );
    }
}
