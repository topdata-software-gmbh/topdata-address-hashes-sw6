<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Message;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataAddressHashesSW6\Service\HashLogicService;

#[AsMessageHandler]
class RefreshHashesHandler
{
    public function __construct(
        private readonly HashLogicService $hashLogicService
    ) {
    }

    public function __invoke(RefreshHashesMessage $message): void
    {
        $this->hashLogicService->refreshAllHashes();
    }
}
