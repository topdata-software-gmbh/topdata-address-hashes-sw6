<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;
use Topdata\TopdataFoundationSW6\Controller\AbstractTopdataApiController;

#[Route(defaults: ['_routeScope' => ['api']])]
class AddressHashApiController extends AbstractTopdataApiController
{
    public function __construct(private readonly HashLogicService $hashLogicService)
    {
    }

    #[Route(
        path: '/api/_action/topdata/address-hash-config',
        name: 'api.action.topdata.address-hash-config',
        methods: ['GET']
    )]
    public function getConfig(): JsonResponse
    {
        return $this->payloadResponse([
            'algorithm'     => 'SHA256',
            'normalization' => 'lowercase, non-alphanumeric removed',
            'fields'        => $this->hashLogicService->getEnabledFields(),
            'sqlTemplate'  => $this->hashLogicService->getSqlExpression('TABLE_ALIAS'),
        ]);
    }


    #[Route(
        path: '/api/_action/topdata/calculate-address-hash',
        name: 'api.action.topdata.calculate-address-hash',
        methods: ['POST']
    )]
    public function calculate(Request $request): JsonResponse
    {
        $data = [];

        if ($request->request->count() > 0) {
            $data = $request->request->all();
        } else {
            $decoded = json_decode((string)$request->getContent(), true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($data === []) {
            return $this->errorResponse('No address data provided', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->hashLogicService->calculateHashDetailed($data);

        return $this->payloadResponse([
            'fingerprint'    => $result['hash'],
            'fieldsUsed'    => $result['used'],
            'fieldsIgnored' => $result['ignored'],
            'fieldsMissing' => $result['missing'],
            'config'         => [
                'enabledFields' => $this->hashLogicService->getEnabledFields(),
            ],
        ]);
    }
}
