# Topdata Address Hashes SW6 (ERP Integration)

This plugin provides a persistent address fingerprint (hash) for de-duplication in ERP systems.

## Installation

1. Download the plugin
2. Upload to your Shopware 6 installation
3. Install and activate the plugin

## Requirements

- Shopware 6.7.*
- MySQL 8.0+ or MariaDB 10.11+

## For the ERP Guy 

The hashes are stored in separate extension tables so Shopware core tables remain untouched.

### Get the hash for a customer address

```sql
SELECT ca.*, h.fingerprint
FROM customer_address ca
JOIN tdah_customer_address_extension h ON ca.id = h.address_id
```

### Get the hash for an order delivery address

```sql
SELECT oa.*, h.fingerprint
FROM order_address oa
JOIN tdah_order_address_extension h ON oa.id = h.address_id AND oa.version_id = h.address_version_id
```

### Hash logic

The fingerprint is SHA256 of the concatenation (in order) of the following normalized fields:
- `street`
- `zipcode`
- `city`
- `phone_number`
- `additional_address_line1`
- `additional_address_line2`
- `company`
- `department`
- `salutation_id` (as HEX)
- `first_name`
- `last_name`
- `title`
- `country_id` (as HEX)

All text fields have non-alphanumeric characters removed and are lowercased before hashing. Binary IDs are converted to hex strings.

## Console command

Use this command to backfill hashes for existing data:

```bash
bin/console topdata:address-hashes:refresh
```

## License

MIT