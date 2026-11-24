import type { Money } from '@/lib/money'

export type Translations = Record<string, string>

export interface Tenant {
  id: number
  type: 'platform' | 'supplier' | 'buyer'
  name: string
  name_translations?: Translations
  description?: string | null
  description_translations?: Translations
  slug: string
  city?: string | null
  phone?: string | null
  whatsapp?: string | null
  logo_url?: string | null
  base_currency: string
  status: string
  accounting_enabled: boolean
  verification_status: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  phone: string | null
  locale: string
  avatar_url?: string | null
  tenant: Tenant | null
}

export interface Category {
  id: number
  parent_id: number | null
  image_url?: string | null
  /** مصغّر بطاقة الصنف. الأصل يبقى في image_url. */
  image_thumb_url?: string | null
  name: string
  name_translations: Translations
  slug: string
  icon: string | null
  is_featured: boolean
  suppliers_count: number
  products_count: number
  children?: Category[]
}

export interface Supplier {
  id: number
  slug: string
  name: string
  description: string | null
  logo_url: string | null
  country_code: string
  city: string | null
  phone: string | null
  whatsapp: string | null
  is_verified: boolean
  rating: { average: number; count: number }
  completed_orders: number
  min_order: Money | null
  products_count?: number
  categories?: { id: number; name: string; is_primary: boolean; brands: string[] }[]
}

export interface Product {
  id: number
  name: string
  name_translations: Translations
  description: string | null
  description_translations?: Translations
  slug: string
  sku: string | null
  brand: string | null
  hs_code: string | null
  country_of_origin: string | null
  /** بلغة القارئ، جاهزة للعرض */
  specifications?: { label: string; value: string }[]
  /** باللغتين، للتحرير في «منتجاتي» */
  specifications_translations?: { label: Translations; value: Translations }[]
  category_id: number
  category?: Category
  supplier?: Supplier
  unit_of_measure: string
  min_order_quantity: number
  lead_time_days: number
  price_visible: boolean
  price: Money | null
  price_tiers?: { min_qty: number; max_qty: number | null; unit_price: Money }[]
  images?: { id: number; url: string; thumb_url?: string | null }[]
  cover_url?: string | null
  /** مصغّر الغلاف لبطاقات السوق. الأصل يبقى في cover_url. */
  cover_thumb_url?: string | null
  status: string
}

export interface RfqItem {
  id: number
  description: string
  quantity: number
  unit: string
  product?: { id: number; name: string; slug: string } | null
}

export interface Rfq {
  id: number
  reference_no: string
  title: string
  description: string | null
  category?: Category
  buyer: { id?: number; name: string }
  target_price: Money | null
  currency: string
  delivery_city: string | null
  expires_at: string | null
  hours_remaining: number | null
  visibility: string
  status: string
  reach: { notified: number; viewed: number; quoted: number }
  items?: RfqItem[]
  has_quoted?: boolean
  created_at: string | null
}

export interface Quotation {
  id: number
  quotation_no: string
  rfq_id: number | null
  supplier?: Supplier
  subtotal: Money
  tax: Money
  shipping: Money
  total: Money
  currency: string
  lead_time_days: number
  payment_terms_days: number
  valid_until: string | null
  is_actionable: boolean
  notes: string | null
  status: string
  discount?: Money
  incoterm?: string | null
  buyer?: Supplier
  rfq_title?: string | null
  signatures?: {
    supplier: QuotationSignature | null
    buyer: QuotationSignature | null
  }
  items?: { id: number; description: string; quantity: number; unit: string; unit_price: Money; line_total: Money }[]
}

export interface QuotationSignature {
  url: string
  by: string
  at: string
}

export interface Order {
  id: number
  order_no: string
  supplier?: Supplier
  buyer?: { id: number; name: string }
  subtotal: Money
  tax: Money
  total: Money
  commission: Money
  currency: string
  status: string
  payment_status: string
  delivered_at: string | null
  items?: { id: number; description: string; quantity: number; unit_price: Money; line_total: Money }[]
  created_at: string | null
}

export interface Account {
  id: number
  parent_id: number | null
  code: string
  name: string
  name_translations: Translations
  type: 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'
  system_key: string | null
  normal_balance: 'debit' | 'credit'
  is_leaf: boolean
  is_system: boolean
  balance: number | null
}

export interface JournalLine {
  account_code: string
  account_name: string
  debit: number
  credit: number
  description: string | null
}

export interface JournalEntry {
  id: number
  entry_no: string
  entry_date: string
  type: string
  description: string | null
  currency: string
  total_debit: number
  total_credit: number
  is_balanced: boolean
  status: string
  source: string | null
  lines: JournalLine[]
}

export interface TrialBalance {
  currency: string
  from: string
  to: string
  accounts: {
    account_id: number
    code: string
    name: Translations
    type: string
    debit: number
    credit: number
    balance: number
    balance_side: string
  }[]
  totals: { debit: number; credit: number; difference: number; is_balanced: boolean }
}

export interface ReportLine {
  code: string
  name: Translations
  amount: number
}

export interface IncomeStatement {
  currency: string
  from: string
  to: string
  revenue: { lines: ReportLine[]; total: number }
  cost_of_sales: { lines: ReportLine[]; total: number }
  gross_profit: number
  gross_margin_pct: number
  operating_expenses: { lines: ReportLine[]; total: number }
  net_profit: number
}

export interface BalanceSheet {
  currency: string
  as_of: string
  assets: { lines: ReportLine[]; total: number }
  liabilities: { lines: ReportLine[]; total: number }
  equity: { lines: ReportLine[]; current_period_result: number; total: number }
  check: { assets: number; liabilities_plus_equity: number; difference: number; is_balanced: boolean }
}

export interface Notification {
  id: string
  event: string
  icon: string
  title: Translations
  body: Translations
  action_url: string | null
  data: Record<string, unknown>
  read_at: string | null
  created_at: string | null
}

export interface Conversation {
  id: number
  subject: string | null
  context: { type: string; id: number } | null
  counterpart: { id: number; name: string; slug: string; type: string } | null
  last_message: { body: string | null; type: string; attachment_mime?: string | null; sender_tenant_id: number; created_at: string | null } | null
  unread_count: number
  last_message_at: string | null
}

export interface Message {
  id: number
  conversation_id: number
  sender_tenant_id: number
  sender_name?: string
  sender_company?: string
  body: string | null
  type: string
  attachment_url: string | null
  attachment_name?: string | null
  attachment_mime?: string | null
  thumbnail_url?: string | null
  is_mine: boolean
  seen?: boolean
  created_at: string | null
}

/** شكل الرسالة كما تصل عبر البث اللحظي */
export interface IncomingMessage {
  id: number
  conversation_id: number
  sender_tenant_id: number
  sender_name: string | null
  sender_company: string | null
  body: string | null
  type: string
  attachment_url: string | null
  attachment_name?: string | null
  attachment_mime?: string | null
  thumbnail_url?: string | null
  created_at: string | null
}

export interface TypingNotice {
  conversation_id: number
  tenant_id: number
  name: string
  typing: boolean
  at: number
}

export interface ReadNotice {
  conversation_id: number
  tenant_id: number
  message_id: number
}

export interface AdminOverview {
  currency: string
  from: string
  to: string
  gmv: { total: number; currency: string; by_currency: { currency: string; amount: number; converted: number; orders: number }[] }
  orders: { total: number; by_status: Record<string, number> }
  commission: { revenue: number; receivable: number; accrued: number; settled: number; currency: string }
  subscriptions: { active: number; mrr: number; arr: number; currency: string }
  suppliers: { total: number; active: number; pending_review: number; verified: number }
  buyers: number
  rfq: { published: number; quoted: number; awarded: number; quote_rate: number; conversion_rate: number }
}

export interface AdminSeries {
  currency: string
  series: { month: string; gmv: number; orders: number; commission: number }[]
}

export interface TopSupplier {
  id: number
  name: string
  slug: string
  currency: string
  orders: number
  gmv: number
  commission: number
}

export interface AdminSupplier {
  id: number
  name: string
  slug: string
  status: string
  city: string | null
  country_code: string
  base_currency: string
  created_at: string | null
  users_count: number | null
  profile: {
    legal_name: string
    display_name: string
    commercial_register_no: string | null
    tax_number: string | null
    phone: string | null
    verification_status: string
    verified_at: string | null
    rejection_reason: string | null
    rating: number
    completed_orders: number
  } | null
  categories: { id: number; name: string; is_primary: boolean }[]
}

export interface CommissionRule {
  id: number
  scope: 'global' | 'category' | 'plan' | 'supplier'
  scope_id: number | null
  target: string | null
  type: 'percentage' | 'fixed' | 'tiered'
  value: number
  tiers: { up_to: number | null; rate: number }[] | null
  accrual_trigger: string
  priority: number
  is_active: boolean
}

export interface AdminPlan {
  id: number
  slug: string
  name: string
  name_translations: Translations
  price_monthly: number
  price_yearly: number
  currency: string
  product_limit: number | null
  user_limit: number
  commission_discount: number
  accounting_enabled: boolean
  is_active: boolean
  subscribers: number
}

export interface CommissionRow {
  id: number
  order_no: string | null
  supplier: { id: number; name: string | null }
  base_amount: number
  rate: number
  amount: number
  currency: string
  status: string
  accrued_at: string | null
  settled_at: string | null
  age_days: number
}

export interface CommissionAging {
  buckets: Record<string, number>
  total: number
  by_supplier: { supplier_id: number; name: string | null; currency: string; total: number; oldest_days: number; count: number }[]
}

export interface AuditEntry {
  id: number
  action: string
  user: { id: number; name: string } | null
  subject: { type: string; id: number } | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

export interface StockItem {
  id: number
  product: { id: number; name: string | null; sku: string | null; unit: string | null }
  variant: { id: number; sku: string } | null
  warehouse: { id: number; name: string | null }
  quantity_on_hand: number
  quantity_reserved: number
  quantity_available: number
  average_cost: number
  value: number
  reorder_point: number
  is_low: boolean
  last_movement_at: string | null
}

export interface StockMovement {
  id: number
  type: string
  product?: string | null
  warehouse?: string | null
  quantity: number
  unit_cost: number
  total_cost: number
  balance_after: number
  average_cost_after?: number
  source: string | null
  journal_entry_no?: string | null
  notes: string | null
  created_at: string | null
}

export interface StockWarehouse {
  id: number
  name: string
  name_translations: Translations
  code: string
  address: string | null
  is_default: boolean
  is_active: boolean
  items_count: number | null
  value: number
}

export interface StockValuation {
  currency: string
  costing_method: string
  total_value: number
  ledger_balance: number
  difference: number
  is_reconciled: boolean
  items: {
    id: number
    product: string | null
    sku: string | null
    warehouse: string | null
    quantity: number
    average_cost: number
    value: number
  }[]
}

export interface StockCountSummary {
  id: number
  reference_no: string
  warehouse: string | null
  count_date: string | null
  status: string
  items_count: number
  created_by: string | null
  approved_at: string | null
}

export interface StockCountDetail {
  id: number
  reference_no: string
  warehouse: { id: number; name: string | null }
  count_date: string | null
  status: string
  notes: string | null
  net_value_difference: number
  approved_at: string | null
  items: {
    id: number
    inventory_item_id: number
    product: string | null
    sku: string | null
    expected_quantity: number
    counted_quantity: number | null
    difference: number
    unit_cost: number
    value_difference: number
    notes: string | null
  }[]
}

export interface Carrier {
  id: number
  name: string
  name_translations: Translations
  code: string
  phone: string | null
  website: string | null
  coverage: string[]
  supports_cod: boolean
  is_platform_wide: boolean
  is_active: boolean
  rates: {
    id: number
    from_city: string | null
    to_city: string | null
    min_weight_kg: number
    max_weight_kg: number | null
    base_price: number
    price_per_kg: number
    currency: string
    transit_days: number
  }[]
}

export interface ShipmentSummary {
  id: number
  shipment_no: string
  order: { id: number; order_no: string | null }
  carrier: string | null
  tracking_number: string | null
  tracking_token: string
  status: string
  packages_count: number
  weight_kg: number | null
  shipping_cost: number
  currency: string
  is_cod: boolean
  estimated_delivery: string | null
  shipped_at: string | null
  delivered_at: string | null
  created_at: string | null
}

export interface ShipmentDetail extends ShipmentSummary {
  incoterm: string | null
  origin_address: Record<string, string> | null
  destination_address: Record<string, string> | null
  cod_amount: number
  received_by: string | null
  notes: string | null
  carrier_tracking_url: string | null
  allowed_transitions: string[]
  items: { id: number; order_item_id: number; description: string | null; quantity: number }[]
  events: {
    id: number
    status: string
    location: string | null
    description: string | null
    occurred_at: string | null
    recorded_by: string | null
  }[]
  documents: { id: number; type: string; url: string; original_name: string | null }[]
}

export interface PublicTracking {
  shipment: {
    shipment_no: string
    order_no: string | null
    carrier: string | null
    carrier_phone: string | null
    carrier_tracking_url: string | null
    tracking_number: string | null
    status: string
    packages_count: number
    destination_city: string | null
    estimated_delivery: string | null
    shipped_at: string | null
    delivered_at: string | null
    received_by: string | null
  }
  events: { status: string; location: string | null; description: string | null; occurred_at: string | null }[]
}

export interface PendingShipmentItems {
  order_no: string
  items: { order_item_id: number; description: string; ordered: number; pending: number; unit: string }[]
}

export interface ReturnSummary {
  id: number
  reference_no: string
  order: { id: number; order_no: string | null }
  counterpart: string | null
  is_mine: boolean
  type: string
  reason: string
  status: string
  requested_amount: number
  approved_amount: number
  currency: string
  items_count: number
  created_at: string | null
}

export interface ReturnDetail extends ReturnSummary {
  description: string | null
  decision_note: string | null
  restock: boolean
  allowed_transitions: string[]
  received_value: number
  decided_at: string | null
  received_at: string | null
  completed_at: string | null
  credit_note: { id: number; note_no: string; total: number; currency: string } | null
  items: {
    id: number
    order_item_id: number
    description: string | null
    quantity: number
    approved_quantity: number
    received_quantity: number
    unit_price: number
    line_total: number
    condition_note: string | null
  }[]
  photos: { id: number; url: string }[]
}

export interface ReturnableItems {
  order_no: string
  currency: string
  items: {
    order_item_id: number
    description: string
    ordered: number
    returnable: number
    unit_price: number
    unit: string
  }[]
}

export interface DisputeSummary {
  id: number
  reference_no: string
  order: { id: number; order_no: string | null }
  buyer: string | null
  supplier: string | null
  opened_by_me: boolean
  reason: string
  status: string
  outcome: string | null
  claimed_amount: number
  resolution_amount: number
  currency: string
  mediator: string | null
  due_at: string | null
  is_overdue: boolean
  created_at: string | null
}

export interface DisputeDetail extends DisputeSummary {
  description: string
  resolution_note: string | null
  return_request: { id: number; reference_no: string } | null
  allowed_transitions: string[]
  resolved_at: string | null
  events: {
    id: number
    type: string
    body: string | null
    file_url: string | null
    meta: Record<string, unknown> | null
    is_internal: boolean
    from_platform: boolean
    author: string | null
    company: string | null
    created_at: string | null
  }[]
}

export interface OutstandingInvoice {
  id: number
  contact_id: number | null
  contact_name: string | null
  invoice_no: string
  issue_date: string
  due_date: string
  total: number
  paid_amount: number
  balance_due: number
  status: string
  age_days: number
  bucket: string
}

export interface OutstandingResponse {
  currency: string
  available_credit: number
  customers: { id: number; name: string | null; invoices_count: number; outstanding: number; overdue: number }[]
  invoices: OutstandingInvoice[]
}

export interface PaymentRecord {
  id: number
  payment_no: string
  direction: 'in' | 'out'
  method: string
  amount: number
  fee_amount: number
  currency: string
  payment_date: string
  status: string
  gateway_reference: string | null
  notes: string | null
  allocated_amount: number
  unallocated_amount: number
  allocations: { invoice_id: number; invoice_no: string | null; amount: number }[]
}

export interface CreditNoteRecord {
  id: number
  note_no: string
  issue_date: string
  invoice_no: string | null
  return_reference: string | null
  subtotal: number
  tax_amount: number
  total: number
  applied_amount: number
  unapplied_amount: number
  currency: string
  reason: string | null
  status: string
}

export interface CustomerStatement {
  currency: string
  contact: { id: number; name: string | null }
  lines: {
    date: string
    type: string
    reference: string
    debit: number
    credit: number
    balance: number
  }[]
  balance: number
  outstanding: number
}

export interface Vendor {
  id: number
  name: string
  code: string
  contact_person: string | null
  phone: string | null
  email: string | null
  country_code: string | null
  city: string | null
  default_currency: string
  payment_terms_days: number
  is_active: boolean
  orders_count: number | null
  outstanding: number
}

export interface PurchaseOrderSummary {
  id: number
  reference_no: string
  vendor: { id: number; name: string | null }
  order_date: string
  expected_date: string | null
  subtotal: number
  tax_amount: number
  total: number
  currency: string
  status: string
  items_count: number
}

export interface PurchaseOrderItem {
  id: number
  product_id: number | null
  description: string
  quantity: number
  received_quantity: number
  invoiced_quantity: number
  pending_receipt: number
  pending_invoice: number
  unit: string
  unit_cost: number
  tax_amount: number
  line_total: number
}

export interface PurchaseOrderDetail extends PurchaseOrderSummary {
  warehouse: { id: number | null; name: string | null }
  incoterm: string | null
  shipping_amount: number
  notes: string | null
  allowed_transitions: string[]
  items: PurchaseOrderItem[]
  receipts: {
    id: number
    reference_no: string
    received_date: string
    total_cost: number
    vendor_note_no: string | null
    items_count: number
  }[]
}

export interface AdminSummary {
  generated_at: string
  highlights: {
    suppliers: number
    buyers: number
    products: number
    categories: number
    completed_orders: number
    gmv_orders: number
  }
  charts: {
    funnel: { key: string; value: number }[]
    accounts: { key: string; value: number }[]
    orders_by_status: { key: string; value: number }[]
  }
  sections: {
    key: string
    items: { key: string; value: number; tone?: 'success' | 'warning' }[]
  }[]
}

export interface AdminProductRow {
  id: number
  name: string
  slug: string
  sku: string | null
  brand: string | null
  status: string
  price: number
  currency: string
  category: string | null
  supplier: { id: number; name: string | null }
  created_at: string | null
}

export interface AdminUserRow {
  id: number
  name: string
  email: string
  phone: string | null
  status: string
  locale: string
  avatar_url: string | null
  last_login_at: string | null
  created_at: string | null
  tenant: { id: number; type: string; name: string; status: string } | null
}

export interface AdminQuotationRow {
  id: number
  quotation_no: string
  status: string
  total: number
  currency: string
  supplier: string | null
  buyer: string | null
  rfq_title: string | null
  signed: { supplier: boolean; buyer: boolean }
  created_at: string | null
}

export interface AdminOrderRow {
  id: number
  order_no: string
  status: string
  payment_status: string
  total: number
  currency: string
  supplier: string | null
  buyer: string | null
  created_at: string | null
}

export interface AdminListMeta {
  current_page: number
  last_page: number
  total: number
}

export interface AdminCategory {
  id: number
  parent_id: number | null
  name: string
  name_translations: Translations
  slug: string
  icon: string | null
  image_url: string | null
  image_thumb_url?: string | null
  is_active: boolean
  is_featured: boolean
  sort_order: number
  products_count: number | null
  children?: AdminCategory[]
}

/** قائمة مصفَّحة كما يُرجعها الخادم: الصفحة الحالية ومعها عدّادها */
export interface Paged<T> {
  data: T[]
  meta?: AdminListMeta
}
