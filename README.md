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

The hashes are stored in a separate table so Shopware core tables remain untouched.

### Get the hash for a customer address

```sql
SELECT ca.id, h.fingerprint
FROM customer_address ca
JOIN tdah_address_hash h ON ca.id = h.address_id
	AND h.address_version_id = UNHEX('0fa91ce3e96a4ce293c45c795a1ee31f')
WHERE ca.customer_id = UNHEX('...');
```

### Get the hash for an order delivery address

```sql
SELECT oa.id, h.fingerprint
FROM order_address oa
JOIN tdah_address_hash h ON oa.id = h.address_id
	AND oa.version_id = h.address_version_id
WHERE oa.id = UNHEX('...');
```

### Hash logic

The hash is SHA256 of `LOWER(STREET + ZIP + CITY + COUNTRY_HEX_ID)` with all non-alphanumeric characters removed.

## Console command

Use this command to backfill hashes for existing data:

```bash
bin/console topdata:address-hashes:refresh
```

## License

MIT