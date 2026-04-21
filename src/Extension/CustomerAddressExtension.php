<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Extension;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash\CustomerAddressHashDefinition;

class CustomerAddressExtension extends EntityExtension
{
    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'topdataAddressHash',
                'id',
                'address_id',
                CustomerAddressHashDefinition::class,
                false
            ))->addFlags(new ApiAware())
        );
    }

    public function getEntityName():  string
    {
        return CustomerAddressDefinition::ENTITY_NAME;
    }

//    public function getDefinitionClass(): string
//    {
//        return CustomerAddressDefinition::class;
//    }
}
