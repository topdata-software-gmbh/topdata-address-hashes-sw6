<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Extension;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash\OrderAddressHashDefinition;

class OrderAddressExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'topdataAddressHash',
                'id',
                'address_id',
                OrderAddressHashDefinition::class,
                false
            ))->addFlags(new ApiAware())
        );
    }

    public function getDefinitionClass(): string
    {
        return OrderAddressDefinition::class;
    }

    public function getEntityName(): string
    {
        return OrderAddressDefinition::ENTITY_NAME;
    }
}
