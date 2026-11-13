<?php

namespace App\Exceptions;

use App\Support\Api\ApiErrorResponse;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 停止中の企業の担当者が正しい password で login しようとした（docs/domain-api-contract.md M2.0・M2.2）。
 *
 * `403 forbidden` は固定文言で理由が伝わらないため、専用の code を返す。
 * render() は bootstrap/app.php の API renderer より先に呼ばれる。
 * 想定内の拒否なので log に error として残さない（ShouldntReport）。
 */
class CompanySuspendedException extends Exception implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return ApiErrorResponse::make(
            'company_suspended',
            'この企業アカウントは現在利用できません。運営にお問い合わせください。',
            Response::HTTP_FORBIDDEN,
        );
    }
}
