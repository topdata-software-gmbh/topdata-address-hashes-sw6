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

### Admin API Authentication (2-step process)

Shopware 6 Admin API requires OAuth2 authentication. Here's the complete flow:

**Step 0: Set environment variables**

```bash
export BASEURL="https://your-shop.com"
export ADMIN_API_KEY="YOUR_ADMIN_API_KEY"
export ADMIN_API_SECRET="YOUR_ADMIN_API_SECRET"
```

**Step 1: Get Bearer Token**

```bash
TOKEN=$(curl -s -X POST \
  "$BASEURL/api/oauth/token" \
  -H "Content-Type: application/json" \
  -d "{
    \"grant_type\": \"client_credentials\",
    \"client_id\": \"$ADMIN_API_KEY\",
    \"client_secret\": \"$ADMIN_API_SECRET\"
  }" | jq -r '.access_token')

echo "Token: $TOKEN"
```

**Step 2: Make API Calls**

Use the token in the `Authorization: Bearer $TOKEN` header for all subsequent requests.

---

### Get hashing recipe

```bash
curl -s -X GET \
  "$BASEURL/api/_action/topdata/address-hash-config" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" | jq
```

**Response:**
```json
{
  "algorithm": "SHA256",
  "normalization": "lowercase, non-alphanumeric removed",
  "fields": ["street", "zipcode", "city", "lastName", "countryId"],
  "sql_template": "LOWER(REGEXP_REPLACE(...))"
}
```

### Calculate hash (dry run)

```bash
curl -s -X POST \
  "$BASEURL/api/_action/topdata/calculate-address-hash" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "street": "Musterstraße 12",
    "zipcode": "80331",
    "city": "München",
    "lastName": "Mustermann",
    "countryId": "2f798c64371d4de996cbf0fe15475a18"
  }' | jq
```

**Response:**
```json
{
  "fingerprint": "a3f5c2...",
  "fields_used": {
    "street": { "original": "Musterstraße 12", "normalized": "musterstrae12" },
    "zipcode": { "original": "80331", "normalized": "80331" },
    "city": { "original": "München", "normalized": "mnchen" },
    "lastName": { "original": "Mustermann", "normalized": "mustermann" },
    "countryId": { "original": "2f798c64371d4de996cbf0fe15475a18", "normalized": "2f798c64371d4de996cbf0fe15475a18" }
  },
  "fields_ignored": {},
  "fields_missing": [],
  "config": {
    "enabled_fields": ["street", "zipcode", "city", "lastName", "countryId"]
  }
}
```

### Calculate hash with ignored fields

```bash
curl -s -X POST \
  "$BASEURL/api/_action/topdata/calculate-address-hash" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "street": "Musterstraße 12",
    "zipcode": "80331",
    "city": "München",
    "lastName": "Mustermann",
    "countryId": "2f798c64371d4de996cbf0fe15475a18",
    "extraField": "ignored by hash"
  }' | jq
```

**Relevant payload snippet:**
```json
{
  "fields_ignored": {
    "extraField": "ignored by hash"
  }
}
```

### Calculate hash with missing fields

```bash
curl -s -X POST \
  "$BASEURL/api/_action/topdata/calculate-address-hash" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "street": "Musterstraße 12",
    "city": "München"
  }' | jq
```

**Relevant payload snippet:**
```json
{
  "fields_missing": ["zipcode", "lastName", "countryId"]
}
```

### One-liner: Get token + call config endpoint

```bash
curl -s -X GET "$BASEURL/api/_action/topdata/address-hash-config" \
  -H "Authorization: Bearer $(curl -s -X POST "$BASEURL/api/oauth/token" \
    -H 'Content-Type: application/json' \
    -d "{\"grant_type\":\"client_credentials\",\"client_id\":\"$ADMIN_API_KEY\",\"client_secret\":\"$ADMIN_API_SECRET\"}" \
    | jq -r '.access_token')" \
  -H "Accept: application/json" | jq
```

## License

MIT