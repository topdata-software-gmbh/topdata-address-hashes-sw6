<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Topdata\TopdataAddressHashesSW6\Message\RefreshHashesMessage;
use Topdata\TopdataAddressHashesSW6\Service\TriggerManager;

class ConfigChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TriggerManager $triggerManager,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus
    ) {
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

        $newFields = $event->getValue();
        if (!\is_array($newFields)) {
            return;
        }

        sort($newFields);
        $newFieldsJson = json_encode($newFields, JSON_THROW_ON_ERROR);

        $existingHashFieldsJson = $this->connection->fetchOne(
            'SELECT hash_fields FROM tdah_customer_address_extension WHERE hash_fields IS NOT NULL LIMIT 1'
        );

        if ($existingHashFieldsJson === $newFieldsJson) {
            return;
        }

        $hashFieldsChangedAt = (new \DateTime())->format('Y-m-d H:i:s.v');
        $this->triggerManager->updateAllTriggers($hashFieldsChangedAt);

        $this->messageBus->dispatch(new RefreshHashesMessage());
    }
}
