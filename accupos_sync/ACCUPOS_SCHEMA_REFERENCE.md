# AccuPOS Database Schema Reference

**Database:** IL45121  
**Host:** cloud.accupos.com:3306  
**Date:** 23.10.2025

## Core Tables

### 1. `apcshead` - Order/Receipt Headers
Заголовки чеков/заказов (аналог ps_orders)

**Key Fields:**
- `Id` (PK) - Internal ID
- `Key` - Order/Receipt number
- `DateInvoiced` - Order completion datetime
- `LocationCode` - Store location (HAIFA, dizengof, etc.)
- `Total` - Order total amount
- `Status` - Order status (NULL=completed, 'Void'=cancelled)
- `InvNum` - Invoice number
- `CloudTimeStamp` - Sync timestamp

**Important:**
- Используется для определения завершённых заказов
- `Status IS NULL OR Status != 'Void'` = active orders
- `LocationCode` → terminal mapping

### 2. `apcsitem` - Order Line Items
Позиции заказов/товары в чеке (аналог ps_order_detail)

**Key Fields:**
- `Id` (PK) - Internal ID
- `HeadKey` - FK to apcshead.Key
- `ItemID` - Product SKU/Code (reference)
- `Quantity` - Qty sold (может быть отрицательным при возврате)
- `Price` - Unit price
- `Ext` - Extended price (total for line)
- `LocationCode` - Store location
- `Status` - Line status ('V'=void)
- `Created` - Unix timestamp (milliseconds)
- `Changed` - Unix timestamp (milliseconds)

**Important:**
- `ItemID` → ps_product.reference (SKU mapping)
- `Quantity < 0` = возврат товара
- `Status = 'V'` = аннулированная позиция (не учитывать)
- `Hidden = 1` = скрытая позиция (не учитывать)

### 3. `apcstend` - Payment Transactions
Платежи/тендеры (методы оплаты)

**Key Fields:**
- `Id` (PK)
- `Tran` - FK to apcshead.Key
- `Code` - Payment method code ('CS'=cash, 'CC'=credit card, etc.)
- `Amount` - Payment amount
- `Date` - Payment datetime
- `LocationCode` - Store location

### 4. `POSStations` - POS Terminals
Терминалы/устройства POS

**Key Fields:**
- `MachineId` - Unique machine ID
- `StationName` - Terminal name
- `Location` - Store location (dizengof, HAIFA)

**Mapping:**
- `Location` (POSStations) → `LocationCode` (apcshead/apcsitem)
- PrestaShop: `MachineId` OR `Location` → ps_warehouse.id_warehouse

### 5. `ItemsMaster` - Product Master Data
Справочник товаров

**Key Fields:**
- `Id` (PK)
- `ItemId` - Product SKU/Code
- `ItemName` - Product name
- `ItemDescription` - Description
- `Cost` - Cost price
- `Inactive` - Is inactive flag

**Mapping:**
- `ItemId` → ps_product.reference

## Sample Query for Synchronization

```sql
-- Get completed sales with items since last sync
SELECT 
    h.Id AS order_id,
    h.Key AS order_key,
    h.DateInvoiced AS sale_date,
    h.LocationCode AS location,
    h.InvNum AS invoice_num,
    i.Id AS item_id,
    i.ItemID AS sku,
    i.Quantity AS qty,
    i.Price AS price,
    i.Ext AS total,
    i.Status AS item_status
FROM apcshead h
INNER JOIN apcsitem i ON h.Key = i.HeadKey
WHERE h.DateInvoiced >= :sync_start_date
    AND h.DateInvoiced IS NOT NULL
    AND (h.Status IS NULL OR h.Status != 'Void')
    AND (i.Status IS NULL OR i.Status != 'V')
    AND i.Hidden = 0
    AND i.Quantity > 0
    AND i.Id > :last_item_id
ORDER BY i.Id ASC
LIMIT 1000
```

## Location → Warehouse Mapping

**Current Locations:**
- `dizengof` (MachineId: 175268bf28)
- `HAIFA` (MachineId: f144e04d7f)

**Mapping to PrestaShop:**
- `dizengof` → STK_1 (Тель-Авив) → warehouse_id = 1
- `HAIFA` → STK_3 (Хайфа) → warehouse_id = 3
- (Jerusalem location not found in current data)

## Transaction Tracking

**Primary Key for Deduplication:**
- Use `apcsitem.Id` as unique transaction identifier
- Store in `ps_accupos_transactions.accupos_transaction_id`

**Timestamp Fields:**
- `apcshead.CloudTimeStamp` - server sync time
- `apcshead.DateInvoiced` - order completion time
- `apcsitem.Created` - Unix timestamp in milliseconds
- `apcsitem.Changed` - Unix timestamp in milliseconds (last modification)

## Data Statistics

**As of 23.10.2025:**
- Total orders (apcshead): 7,211
- Total line items (apcsitem): 21,595
- Total payments (apcstend): 6,877
- Total products (ItemsMaster): 5,666
- Total terminals (POSStations): 2

## Special Cases

### Voided/Cancelled Orders
```sql
WHERE (h.Status IS NULL OR h.Status != 'Void')
```

### Voided Line Items
```sql
WHERE (i.Status IS NULL OR i.Status != 'V') AND i.Hidden = 0
```

### Returns (Negative Quantity)
```sql
-- Returns have negative quantity
WHERE i.Quantity < 0  -- это возврат
```

### Choice Items (Modifiers)
```sql
-- isChoice = 1 означает modifier/option (не основной товар)
WHERE i.isChoice = 0  -- только основные позиции
```

## Notes

1. **Unix Timestamps:** `Created` and `Changed` fields contain Unix timestamps in **milliseconds**, not seconds
2. **Location Codes:** Case-sensitive! "HAIFA" != "haifa"
3. **Item Status:** Empty status = active, 'V' = void
4. **Quantity:** Can be negative (returns), decimal (weighable items)
5. **HeadKey → Key:** Relationship is via `apcsitem.HeadKey = apcshead.Key`, NOT `apcshead.Id`

## Integration Strategy

1. **Sync Window:** Last 7 days by default
2. **Batch Size:** 1000 items per sync
3. **Deduplication:** Track `apcsitem.Id` in ps_accupos_transactions
4. **Location Mapping:** apcsitem.LocationCode → POSStations.Location → ps_warehouse
5. **SKU Matching:** apcsitem.ItemID → ps_product.reference
6. **Stock Deduction:** Use PrestaShop's StockAvailable::updateQuantity()

---

**Last Updated:** 23.10.2025  
**Author:** Aleksei Nekrasov (impserver.ru)

