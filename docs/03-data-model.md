# نموذج البيانات
### منصة B2B + النظام المحاسبي | الإصدار 1.0

> جميع الجداول تتضمن ضمنياً: `id`, `created_at`, `updated_at`, وحيثما يلزم `deleted_at` (حذف ناعم).
> جميع المبالغ تُخزَّن كـ `BIGINT` بالوحدة الفرعية (هللة/سنت) — **لا تُستخدم `FLOAT` أو `DOUBLE` إطلاقاً**.

---

## 1. الهوية والمستأجرون

### `tenants`
| الحقل | النوع | ملاحظات |
|---|---|---|
| type | enum | `platform` \| `supplier` \| `buyer` |
| name | string | |
| slug | string unique | |
| base_currency | char(3) | عملة الدفتر — غير قابلة للتغيير بعد أول قيد |
| fiscal_year_start_month | tinyint | 1–12 |
| costing_method | enum | `wac` \| `fifo` |
| country_code | char(2) | |
| status | enum | `pending`, `active`, `suspended` |

### `users`
`tenant_id`, `name`, `email` unique, `phone`, `password`, `two_factor_secret`, `locale`, `status`, `last_login_at`

### `roles` / `permissions` / `role_user` / `permission_role`
نموذج RBAC قياسي مع `tenant_id` على `roles` لإتاحة أدوار مخصصة لكل مستأجر.

### `supplier_profiles`
`tenant_id`, `legal_name`, `commercial_register_no`, `tax_number`, `export_license_no`, `logo_path`, `description`, `country`, `city`, `address`, `lat`, `lng`, `min_order_value`, `default_payment_terms`, `verification_status`, `verified_at`, `rating_avg`, `rating_count`, `plan_id`

### `supplier_categories` — تخصّص المستورد بالأصناف
`tenant_id`, `category_id`, `is_primary` (bool), `years_experience`, `brands` (JSON — الوكالات والماركات التي يستوردها)
فهرس: `(tenant_id, category_id)` unique

> هذا الجدول هو أساس **توجيه إشعارات طلبات عروض الأسعار** و**دليل المستوردين**: عند نشر RFQ في فئة "قطع سيارات" يُشعَر كل مستورد مسجّل في هذه الفئة تلقائياً.

### `verification_documents`
`tenant_id`, `type`, `file_path`, `status`, `reviewed_by`, `reviewed_at`, `rejection_reason`

---

## 2. الكتالوج

### `categories`
`parent_id`, `name_ar`, `name_en`, `slug`, `icon`, `sort_order`, `is_active`

### `products`
`tenant_id`, `category_id`, `name_ar`, `name_en`, `slug`, `description`, `sku`, `hs_code`, `country_of_origin`, `unit_of_measure`, `min_order_quantity`, `lead_time_days`, `price_visible` (bool), `base_price`, `currency`, `status`, `views_count`

### `product_variants`
`product_id`, `sku` unique, `attributes` (JSON), `price`, `stock_quantity`, `weight`, `dimensions`

### `product_price_tiers`
`product_id`, `variant_id`, `min_qty`, `max_qty`, `unit_price` — التسعير التدرّجي

### `customer_price_lists` / `customer_price_list_items`
قوائم أسعار خاصة بعميل معيّن.

### `product_media`
`product_id`, `path`, `type`, `sort_order`

---

## 3. RFQ والعروض

### `rfqs`
`buyer_tenant_id`, `reference_no`, `title`, `description`, `category_id`, `quantity`, `unit`, `target_price`, `currency`, `delivery_country`, `delivery_deadline`, `expires_at`, `visibility` (`public`\|`targeted`), `status` (`open`,`quoted`,`awarded`,`closed`,`expired`)

### `rfq_items`
`rfq_id`, `product_id?`, `description`, `quantity`, `unit`, `specifications` (JSON)

### `rfq_invitations`
`rfq_id`, `supplier_tenant_id`, `source` (`targeted` \| `broadcast`), `invited_at`, `notified_at`, `viewed_at`, `responded_at`
فهرس: `(rfq_id, supplier_tenant_id)` unique

> يُنشأ سطر هنا لكل مستورد يصله الطلب — سواء اختاره المشتري بالاسم (`targeted`) أو وصله آلياً لأنه مسجّل في الفئة (`broadcast`). هذا يتيح قياس: كم مستورداً وصله الطلب، كم فتحه، كم قدّم عرضاً.

### `quotations`
`rfq_id`, `supplier_tenant_id`, `quotation_no`, `version`, `parent_quotation_id` (للتفاوض), `subtotal`, `tax_amount`, `shipping_amount`, `discount_amount`, `total`, `currency`, `incoterm`, `lead_time_days`, `payment_terms`, `valid_until`, `notes`, `status` (`draft`,`sent`,`countered`,`accepted`,`rejected`,`expired`)

### `quotation_items`
`quotation_id`, `product_id?`, `description`, `quantity`, `unit_price`, `tax_code_id`, `line_total`

---

## 4. الطلبات والشحن

### `orders`
`order_no`, `buyer_tenant_id`, `supplier_tenant_id`, `quotation_id?`, `subtotal`, `tax_amount`, `shipping_amount`, `discount_amount`, `total`, `currency`, `exchange_rate`, `commission_rate`, `commission_amount`, `payment_terms`, `incoterm`, `status`, `confirmed_at`, `shipped_at`, `delivered_at`, `closed_at`

### `order_items`
`order_id`, `product_id`, `variant_id`, `description`, `quantity`, `unit_price`, `tax_code_id`, `tax_amount`, `line_total`, `unit_cost` (للـ COGS)

### `order_status_history`
`order_id`, `from_status`, `to_status`, `user_id`, `note`, `created_at`

### `order_approvals`
`order_id`, `approver_id`, `level`, `status`, `decided_at`, `comment`

### `shipments`
`order_id`, `carrier_id`, `tracking_number`, `incoterm`, `origin_address`, `destination_address`, `shipped_at`, `estimated_arrival`, `delivered_at`, `status`

### `shipment_documents`
`shipment_id`, `type` (`bill_of_lading`,`packing_list`,`commercial_invoice`,`certificate_of_origin`), `file_path`

### `disputes`
`order_id`, `opened_by`, `reason`, `description`, `status`, `resolution`, `resolved_at`

### `reviews`
`order_id`, `reviewer_tenant_id`, `reviewed_tenant_id`, `rating`, `quality_rating`, `delivery_rating`, `communication_rating`, `comment`, `is_published`

---

## 5. ⭐ النواة المحاسبية

### `accounts` — دليل الحسابات
| الحقل | النوع | ملاحظات |
|---|---|---|
| tenant_id | FK | عزل كامل |
| parent_id | FK nullable | هرمية |
| code | string | فريد ضمن المستأجر |
| name_ar / name_en | string | |
| type | enum | `asset`,`liability`,`equity`,`revenue`,`expense` |
| subtype | enum | `current_asset`,`fixed_asset`,`current_liability`,... |
| normal_balance | enum | `debit` \| `credit` |
| is_leaf | bool | الترحيل على الطرفية فقط |
| is_system | bool | حسابات نظامية محمية من الحذف |
| currency | char(3) nullable | حساب بعملة محددة |
| is_active | bool | |
فهرس: `(tenant_id, code)` unique، `(tenant_id, type)`

### `fiscal_years`
`tenant_id`, `name`, `start_date`, `end_date`, `status` (`open`,`closed`)

### `accounting_periods`
`tenant_id`, `fiscal_year_id`, `name`, `start_date`, `end_date`, `status` (`open`,`soft_closed`,`closed`), `closed_by`, `closed_at`

### `journal_entries` — القيود
| الحقل | النوع | ملاحظات |
|---|---|---|
| tenant_id | FK | |
| period_id | FK | يُرفض الترحيل إن كانت مقفلة |
| entry_no | string | متسلسل بلا فجوات ضمن المستأجر |
| entry_date | date | |
| type | enum | `auto`,`manual`,`adjusting`,`closing`,`opening`,`reversal` |
| source_type | string nullable | `Invoice`, `Payment`, `Order`... (Polymorphic) |
| source_id | bigint nullable | |
| idempotency_key | string unique | يمنع التكرار |
| description | text | |
| currency | char(3) | |
| exchange_rate | decimal(18,8) | |
| total_debit / total_credit | bigint | يجب تساويهما |
| status | enum | `draft`,`posted`,`reversed` |
| reversed_by_entry_id | FK nullable | |
| posted_by / posted_at | | |
فهارس: `(tenant_id, entry_date)`, `(source_type, source_id)`, `idempotency_key` unique

### `journal_lines` — أسطر القيد
`journal_entry_id`, `account_id`, `debit` (bigint), `credit` (bigint), `description`, `currency`, `exchange_rate`, `base_debit`, `base_credit`, `cost_center_id?`, `contact_type?`, `contact_id?` (عميل/مورد لتقارير الذمم), `line_no`
> قيد: لا يجوز أن يكون `debit` و`credit` كلاهما > 0 في نفس السطر.

### `account_balances` — أرصدة مُجمّعة (Materialized)
`tenant_id`, `account_id`, `period_id`, `opening_debit`, `opening_credit`, `period_debit`, `period_credit`, `closing_debit`, `closing_credit`
> تُحدَّث عند كل ترحيل داخل نفس المعاملة — تسريع التقارير دون حساب فوري من ملايين الأسطر.

### `cost_centers`
`tenant_id`, `code`, `name`, `parent_id`, `is_active`

### `recurring_journals`
`tenant_id`, `template` (JSON للقيد), `frequency`, `next_run_date`, `end_date`, `is_active`

### `exchange_rates`
`from_currency`, `to_currency`, `rate` decimal(18,8), `rate_date`, `source`
فهرس: `(from_currency, to_currency, rate_date)` unique

---

## 6. ⭐ الفوترة والذمم

### `tax_codes`
`tenant_id?` (null = عام للمنصة), `code`, `name`, `rate` decimal(5,2), `type` (`inclusive`\|`exclusive`), `country_code`, `sales_account_id`, `purchase_account_id`, `effective_from`, `effective_to`, `is_active`

### `invoices`
| الحقل | ملاحظات |
|---|---|
| tenant_id | المُصدِر |
| invoice_no | متسلسل |
| type | `sales`, `purchase`, `subscription`, `commission` |
| contact_type / contact_id | العميل أو المورد |
| order_id? | |
| issue_date / due_date | |
| subtotal, discount_amount, tax_amount, shipping_amount, total | bigint |
| paid_amount, balance_due | bigint |
| currency, exchange_rate | |
| status | `draft`,`issued`,`partially_paid`,`paid`,`overdue`,`cancelled` |
| journal_entry_id? | القيد الناتج |
| uuid, qr_payload, previous_hash, hash | للفوترة الإلكترونية |
| pdf_path | |

### `invoice_items`
`invoice_id`, `product_id?`, `description`, `quantity`, `unit_price`, `discount`, `tax_code_id`, `tax_amount`, `line_total`, `revenue_account_id`

### `credit_notes` / `debit_notes`
نفس بنية الفاتورة + `invoice_id` (المرجع) + `reason` + `journal_entry_id`

### `payments`
`tenant_id`, `payment_no`, `direction` (`in`\|`out`), `contact_type`, `contact_id`, `method` (`bank`,`cash`,`gateway`,`wallet`), `gateway_id?`, `gateway_reference?`, `amount`, `currency`, `exchange_rate`, `fee_amount`, `payment_date`, `status` (`pending`,`completed`,`failed`,`refunded`), `journal_entry_id?`, `notes`

### `payment_allocations`
`payment_id`, `invoice_id`, `amount` — يسمح بتوزيع دفعة واحدة على عدة فواتير (دفعات جزئية).

### `refunds`
`payment_id`, `order_id?`, `amount`, `reason`, `status`, `journal_entry_id?`

---

## 7. ⭐ المخزون والتكلفة

### `warehouses`
`tenant_id`, `name`, `code`, `address`, `lat`, `lng`, `is_default`

### `inventory_items`
`tenant_id`, `product_id`, `variant_id`, `warehouse_id`, `quantity_on_hand`, `quantity_reserved`, `quantity_available` (محسوب), `average_cost` (bigint), `reorder_point`, `last_movement_at`
فهرس: `(tenant_id, variant_id, warehouse_id)` unique

### `inventory_movements`
`tenant_id`, `inventory_item_id`, `type` (`purchase_in`,`sale_out`,`return_in`,`return_out`,`adjustment`,`transfer_in`,`transfer_out`), `quantity` (موجب/سالب), `unit_cost`, `total_cost`, `balance_after`, `average_cost_after`, `source_type`, `source_id`, `journal_entry_id?`, `notes`
> هذا الجدول هو **المصدر الوحيد للحقيقة** لرصيد المخزون وتكلفته؛ `inventory_items` مجرد لقطة مُسرِّعة.

### `inventory_cost_layers` (عند اختيار FIFO)
`inventory_item_id`, `movement_id`, `quantity_in`, `quantity_remaining`, `unit_cost`, `received_at`

### `stock_counts` / `stock_count_items`
الجرد الفعلي وتسوياته.

---

## 8. ⭐ العمولات والاشتراكات والتسويات

### `commission_rules`
`scope` (`global`,`category`,`plan`,`supplier`), `scope_id?`, `type` (`percentage`,`fixed`,`tiered`), `value`, `tiers` (JSON), `min_amount`, `max_amount`, `accrual_trigger` (`confirmed`,`shipped`,`delivered`), `priority`, `is_active`

### `commissions`
`order_id`, `supplier_tenant_id`, `base_amount`, `rate`, `amount`, `currency`, `status` (`accrued`,`settled`,`reversed`), `accrued_at`, `settled_at`, `platform_journal_entry_id`, `supplier_journal_entry_id`

### `subscription_plans`
`name`, `slug`, `price_monthly`, `price_yearly`, `currency`, `features` (JSON), `product_limit`, `user_limit`, `commission_discount`, `is_active`

### `subscriptions`
`tenant_id`, `plan_id`, `billing_cycle`, `amount`, `currency`, `starts_at`, `ends_at`, `renews_at`, `status` (`trial`,`active`,`past_due`,`cancelled`,`expired`), `cancelled_at`

### `wallets`
`tenant_id`, `currency`, `balance` (bigint), `pending_balance`, `account_id` (الحساب المقابل في دليل الحسابات)
> الرصيد يُشتق دائماً من `wallet_transactions` ويُطابق دورياً مع الأستاذ.

### `wallet_transactions`
`wallet_id`, `type` (`credit`,`debit`), `amount`, `balance_after`, `source_type`, `source_id`, `description`, `journal_entry_id?`

### `payouts`
`tenant_id` (المورد), `payout_no`, `amount`, `currency`, `fee_amount`, `net_amount`, `bank_account_id`, `method`, `status` (`requested`,`approved`,`processing`,`completed`,`failed`), `requested_at`, `approved_by`, `approved_at`, `completed_at`, `reference`, `platform_journal_entry_id`, `supplier_journal_entry_id`

### `bank_accounts`
`tenant_id`, `bank_name`, `account_name`, `account_number_encrypted`, `iban_encrypted`, `swift`, `currency`, `is_default`, `is_verified`

---

## 9. المطابقة البنكية

### `bank_statements`
`tenant_id`, `bank_account_id`, `period_from`, `period_to`, `opening_balance`, `closing_balance`, `imported_file_path`, `imported_by`

### `bank_statement_lines`
`statement_id`, `transaction_date`, `description`, `reference`, `debit`, `credit`, `balance`, `status` (`unmatched`,`matched`,`ignored`), `matched_payment_id?`, `matched_journal_entry_id?`

---

## 10. الأصول الثابتة (اختياري — مرحلة 2)

### `fixed_assets`
`tenant_id`, `name`, `category`, `acquisition_date`, `cost`, `salvage_value`, `useful_life_months`, `depreciation_method` (`straight_line`,`declining`), `accumulated_depreciation`, `book_value`, `status`, `disposal_date`, `asset_account_id`, `depreciation_account_id`, `accumulated_account_id`

### `depreciation_schedules`
`fixed_asset_id`, `period_id`, `amount`, `journal_entry_id?`, `posted_at`

---

## 11. التواصل والإشعارات

### `conversations` / `messages` / `message_attachments`
`conversations`: `subject`, `context_type` (`rfq`,`order`,`invoice`), `context_id`, `last_message_at`
`conversation_participants`: `conversation_id`, `tenant_id`, `user_id`, `last_read_at`
`messages`: `conversation_id`, `sender_id`, `body`, `type`, `read_at`

### `notifications`
قياسي Laravel + `channel`, `priority`, `action_url`

---

## 12. التدقيق والنظام

### `audit_logs`
`tenant_id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values` (JSON), `new_values` (JSON), `ip_address`, `user_agent`, `created_at`
> لا تحديث ولا حذف على هذا الجدول — Append Only.

### `document_sequences`
`tenant_id`, `document_type`, `prefix`, `next_number`, `padding`, `reset_frequency` (`never`,`yearly`,`monthly`)
> يُستخدم مع قفل متشائم لضمان ترقيم متسلسل بلا فجوات ولا تكرار تحت التزامن.

### `settings`
`tenant_id?`, `key`, `value` (JSON), `group`

---

## 13. قيود النزاهة الحاسمة (Database Constraints)

```sql
-- 1) القيد يجب أن يكون متوازناً
ALTER TABLE journal_entries
  ADD CONSTRAINT chk_balanced CHECK (total_debit = total_credit);

-- 2) السطر لا يحمل مدين ودائن معاً
ALTER TABLE journal_lines
  ADD CONSTRAINT chk_debit_xor_credit
  CHECK ((debit = 0 AND credit > 0) OR (debit > 0 AND credit = 0));

-- 3) لا مبالغ سالبة
ALTER TABLE journal_lines
  ADD CONSTRAINT chk_non_negative CHECK (debit >= 0 AND credit >= 0);

-- 4) تفرّد مفتاح منع التكرار
ALTER TABLE journal_entries
  ADD UNIQUE KEY uq_idempotency (idempotency_key);

-- 5) تفرّد رقم القيد ضمن المستأجر
ALTER TABLE journal_entries
  ADD UNIQUE KEY uq_tenant_entry_no (tenant_id, entry_no);
```

**على مستوى التطبيق (Triggers / Observers):**
- منع `update` و`delete` على `journal_entries` بحالة `posted`.
- منع الترحيل إذا كانت `accounting_periods.status != 'open'`.
- التحقق من أن كل `account_id` في السطر يخص نفس `tenant_id` القيد.
- التحقق من أن الحساب `is_leaf = true` و`is_active = true`.

---

## 14. الفهارس الموصى بها للأداء

```sql
CREATE INDEX idx_jl_account_entry ON journal_lines (account_id, journal_entry_id);
CREATE INDEX idx_je_tenant_date   ON journal_entries (tenant_id, entry_date, status);
CREATE INDEX idx_je_source        ON journal_entries (source_type, source_id);
CREATE INDEX idx_inv_tenant_status ON invoices (tenant_id, status, due_date);
CREATE INDEX idx_inv_contact      ON invoices (contact_type, contact_id, status);
CREATE INDEX idx_mov_item_date    ON inventory_movements (inventory_item_id, created_at);
CREATE INDEX idx_orders_supplier  ON orders (supplier_tenant_id, status, created_at);
CREATE INDEX idx_orders_buyer     ON orders (buyer_tenant_id, status, created_at);
```

---

## 15. ملاحظات ترحيل وتوسّع

- **الأرشفة:** `journal_lines` ينمو أسرع جدول في النظام. يُخطَّط من البداية لتقسيمه (Partitioning) حسب السنة المالية.
- **نسخة القراءة:** كل التقارير المالية الثقيلة تُوجَّه إلى MySQL Replica.
- **الحساب المسبق:** `account_balances` يُحدَّث لحظياً؛ ومهمة ليلية تعيد احتسابه من الأسطر وتقارن — أي اختلاف = إنذار فوري.
- **التصدير:** واجهة تصدير قياسية (CSV/XLSX) لكل الجداول المالية لتسهيل الانتقال أو التدقيق الخارجي.
