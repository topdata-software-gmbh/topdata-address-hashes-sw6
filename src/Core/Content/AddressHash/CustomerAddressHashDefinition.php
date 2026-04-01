<?php declare(strict_types=1);

namespace Topdata\TopdataAddressHashesSW6\Core\Content\AddressHash;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class CustomerAddressHashDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdah_customer_address_extension';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('address_id', 'addressId', CustomerAddressDefinition::class))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new ReferenceVersionField(CustomerAddressDefinition::class, 'address_version_id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('fingerprint', 'fingerprint'))->addFlags(new ApiAware(), new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
