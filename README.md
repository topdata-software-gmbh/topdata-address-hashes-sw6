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

The fingerprint is SHA256 over a configurable list of normalized fields.
Default fields are:
- `street`
- `zipcode`
- `city`
- `lastName`
- `countryId`

All text fields have non-alphanumeric characters removed and are lowercased before hashing.
Binary IDs (`salutationId`, `countryId`) are normalized as hex-compatible strings.

Field selection is configurable in Administration under plugin config `hashFields`.

## Console command

Use this command to backfill hashes for existing data:

```bash
bin/console topdata:address-hashes:refresh
```

## API Documentation

### Get hashing recipe

`GET /api/_action/topdata/address-hash-config`

Returns the currently enabled fields and SQL template used for trigger generation.

### Calculate hash (dry run)

`POST /api/_action/topdata/calculate-address-hash`

Accepts address payload (camelCase or snake_case keys) and returns the resulting fingerprint.

## License

MIT