<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SeekerProfileUpdateRequest;
use App\Http\Resources\SeekerProfileResource;
use App\Models\SeekerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SeekerProfileController extends Controller
{
    /**
     * GET /api/seeker-profile（docs/domain-api-contract.md M2.3）。行が無ければ全 field null。
     */
    public function show(Request $request): SeekerProfileResource
    {
        Gate::authorize('view', SeekerProfile::class);

        return new SeekerProfileResource($request->user()->seekerProfile ?? new SeekerProfile);
    }

    /**
     * PUT /api/seeker-profile（docs/domain-api-contract.md M2.3）。行が無ければ作る。
     */
    public function update(Request $request): JsonResponse
    {
        Gate::authorize('update', SeekerProfile::class);

        // FormRequest は認可の後に解決する。引数で受けると controller より先に validation が動き、
        // 企業担当者にも 403 ではなく 422 を返してしまう（認可 → 入力の順。§5.5.5）。
        $input = app(SeekerProfileUpdateRequest::class);

        $profile = $request->user()->seekerProfile()->updateOrCreate([], $input->profileAttributes());

        // JsonResource は作ったばかりの model だと 201 を返すが、PUT は行の有無によらず 200（契約）。
        return (new SeekerProfileResource($profile))->response()->setStatusCode(Response::HTTP_OK);
    }
}
