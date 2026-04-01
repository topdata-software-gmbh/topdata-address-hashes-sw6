<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Subscriber;

use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class ConfigChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TriggerManager $triggerManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onConfigChange'];
    }

    public function onConfigChange(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== 'TopdataAddressHashesSW6.config.hashFields') {
            return;
        }

        $this->triggerManager->updateAllTriggers();
    }
}
