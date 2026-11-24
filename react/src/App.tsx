import { lazy, Suspense, useEffect } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { BootSplash, useBootSplash } from '@/components/BootSplash'
import { InstallPrompt } from '@/components/InstallPrompt'
import { Seo } from '@/components/Seo'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { Layout } from '@/components/Layout'
import { Loading } from '@/components/ui'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'

import { Home } from '@/pages/Home'
import { Suppliers } from '@/pages/Suppliers'
import { SupplierProfile } from '@/pages/SupplierProfile'
import { Products } from '@/pages/Products'
import { ProductDetail } from '@/pages/ProductDetail'
import { NotFound } from '@/pages/NotFound'
import { QuotationDetail } from '@/pages/QuotationDetail'
import { Login } from '@/pages/Login'
import { Orders } from '@/pages/Orders'
import { Messages } from '@/pages/Messages'










function Protected({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth()
  const { t } = useI18n()

  if (loading) return <Loading message={t.common.loading} />
  if (!user) return <Navigate to="/login" replace />

  return <>{children}</>
}


/*
 * الإدارة والمحاسبة تُحمَّل عند فتحها لا مع أوّل صفحة: زائرٌ يقرأ
 * منتجاً لا يحتاج رسوم اللوحة ولا دليل الحسابات. والحزمة الأولى كانت
 * 1.1 ميغابايت لأنّ كل شيء فيها.
 */
const AdminLayout = lazy(() => import('@/pages/admin/AdminLayout').then((m) => ({ default: m.AdminLayout })))
const AdminDashboard = lazy(() => import('@/pages/admin/AdminDashboard').then((m) => ({ default: m.AdminDashboard })))
const AdminSummary = lazy(() => import('@/pages/admin/AdminSummary').then((m) => ({ default: m.AdminSummary })))
const AdminProducts = lazy(() => import('@/pages/admin/AdminProducts').then((m) => ({ default: m.AdminProducts })))
const AdminUsers = lazy(() => import('@/pages/admin/AdminUsers').then((m) => ({ default: m.AdminUsers })))
const AdminTrade = lazy(() => import('@/pages/admin/AdminTrade').then((m) => ({ default: m.AdminTrade })))
const AdminSuppliers = lazy(() => import('@/pages/admin/AdminSuppliers').then((m) => ({ default: m.AdminSuppliers })))
const AdminCategories = lazy(() => import('@/pages/admin/AdminCategories').then((m) => ({ default: m.AdminCategories })))
const AdminUnits = lazy(() => import('@/pages/admin/AdminUnits').then((m) => ({ default: m.AdminUnits })))
const AdminEmails = lazy(() => import('@/pages/admin/AdminEmails').then((m) => ({ default: m.AdminEmails })))
const AdminBanners = lazy(() => import('@/pages/admin/AdminBanners').then((m) => ({ default: m.AdminBanners })))
const AdminFinance = lazy(() => import('@/pages/admin/AdminFinance').then((m) => ({ default: m.AdminFinance })))
const AdminCommissions = lazy(() => import('@/pages/admin/AdminCommissions').then((m) => ({ default: m.AdminCommissions })))
const AdminPlans = lazy(() => import('@/pages/admin/AdminPlans').then((m) => ({ default: m.AdminPlans })))
const AdminInbox = lazy(() => import('@/pages/admin/AdminInbox').then((m) => ({ default: m.AdminInbox })))
const AdminAudit = lazy(() => import('@/pages/admin/AdminAudit').then((m) => ({ default: m.AdminAudit })))
const AccountingLayout = lazy(() => import('@/pages/accounting/AccountingLayout').then((m) => ({ default: m.AccountingLayout })))
const TrialBalance = lazy(() => import('@/pages/accounting/TrialBalance').then((m) => ({ default: m.TrialBalance })))
const IncomeStatement = lazy(() => import('@/pages/accounting/IncomeStatement').then((m) => ({ default: m.IncomeStatement })))
const BalanceSheet = lazy(() => import('@/pages/accounting/BalanceSheet').then((m) => ({ default: m.BalanceSheet })))
const Journal = lazy(() => import('@/pages/accounting/Journal').then((m) => ({ default: m.Journal })))
const ChartOfAccounts = lazy(() => import('@/pages/accounting/ChartOfAccounts').then((m) => ({ default: m.ChartOfAccounts })))
const PaymentsLayout = lazy(() => import('@/pages/payments/PaymentsLayout').then((m) => ({ default: m.PaymentsLayout })))
const Collect = lazy(() => import('@/pages/payments/Collect').then((m) => ({ default: m.Collect })))
const PaymentLog = lazy(() => import('@/pages/payments/PaymentLog').then((m) => ({ default: m.PaymentLog })))
const CreditNotes = lazy(() => import('@/pages/payments/CreditNotes').then((m) => ({ default: m.CreditNotes })))
const Statement = lazy(() => import('@/pages/payments/Statement').then((m) => ({ default: m.Statement })))
const ReturnsLayout = lazy(() => import('@/pages/returns/ReturnsLayout').then((m) => ({ default: m.ReturnsLayout })))
const Returns = lazy(() => import('@/pages/returns/Returns').then((m) => ({ default: m.Returns })))
const ReturnDetail = lazy(() => import('@/pages/returns/ReturnDetail').then((m) => ({ default: m.ReturnDetail })))
const CreateReturn = lazy(() => import('@/pages/returns/CreateReturn').then((m) => ({ default: m.CreateReturn })))
const Disputes = lazy(() => import('@/pages/returns/Disputes').then((m) => ({ default: m.Disputes })))
const DisputeDetail = lazy(() => import('@/pages/returns/DisputeDetail').then((m) => ({ default: m.DisputeDetail })))
const CreateDispute = lazy(() => import('@/pages/returns/CreateDispute').then((m) => ({ default: m.CreateDispute })))
const ShippingLayout = lazy(() => import('@/pages/shipping/ShippingLayout').then((m) => ({ default: m.ShippingLayout })))
const Shipments = lazy(() => import('@/pages/shipping/Shipments').then((m) => ({ default: m.Shipments })))
const ShipmentDetail = lazy(() => import('@/pages/shipping/ShipmentDetail').then((m) => ({ default: m.ShipmentDetail })))
const CreateShipment = lazy(() => import('@/pages/shipping/CreateShipment').then((m) => ({ default: m.CreateShipment })))
const Carriers = lazy(() => import('@/pages/shipping/Carriers').then((m) => ({ default: m.Carriers })))
const TrackShipment = lazy(() => import('@/pages/shipping/TrackShipment').then((m) => ({ default: m.TrackShipment })))
const PurchasingLayout = lazy(() => import('@/pages/purchasing/PurchasingLayout').then((m) => ({ default: m.PurchasingLayout })))
const PurchaseOrders = lazy(() => import('@/pages/purchasing/PurchaseOrders').then((m) => ({ default: m.PurchaseOrders })))
const PurchaseOrderDetail = lazy(() => import('@/pages/purchasing/PurchaseOrderDetail').then((m) => ({ default: m.PurchaseOrderDetail })))
const Vendors = lazy(() => import('@/pages/purchasing/Vendors').then((m) => ({ default: m.Vendors })))
const PayVendors = lazy(() => import('@/pages/purchasing/PayVendors').then((m) => ({ default: m.PayVendors })))
const InventoryLayout = lazy(() => import('@/pages/inventory/InventoryLayout').then((m) => ({ default: m.InventoryLayout })))
const StockItems = lazy(() => import('@/pages/inventory/StockItems').then((m) => ({ default: m.StockItems })))
const StockItemDetail = lazy(() => import('@/pages/inventory/StockItemDetail').then((m) => ({ default: m.StockItemDetail })))
const StockMovements = lazy(() => import('@/pages/inventory/StockMovements').then((m) => ({ default: m.StockMovements })))
const StockValuation = lazy(() => import('@/pages/inventory/StockValuation').then((m) => ({ default: m.StockValuation })))
const StockCounts = lazy(() => import('@/pages/inventory/StockCounts').then((m) => ({ default: m.StockCounts })))
const Warehouses = lazy(() => import('@/pages/inventory/Warehouses').then((m) => ({ default: m.Warehouses })))
const CreateRfq = lazy(() => import('@/pages/buyer/CreateRfq').then((m) => ({ default: m.CreateRfq })))
const MyRfqs = lazy(() => import('@/pages/buyer/MyRfqs').then((m) => ({ default: m.MyRfqs })))
const CompareQuotes = lazy(() => import('@/pages/buyer/CompareQuotes').then((m) => ({ default: m.CompareQuotes })))
const RfqInbox = lazy(() => import('@/pages/supplier/RfqInbox').then((m) => ({ default: m.RfqInbox })))
const SendQuote = lazy(() => import('@/pages/supplier/SendQuote').then((m) => ({ default: m.SendQuote })))
const MyProducts = lazy(() => import('@/pages/supplier/MyProducts').then((m) => ({ default: m.MyProducts })))
const MyQuotations = lazy(() => import('@/pages/supplier/MyQuotations').then((m) => ({ default: m.MyQuotations })))
const About = lazy(() => import('@/pages/About').then((m) => ({ default: m.About })))
const Register = lazy(() => import('@/pages/Register').then((m) => ({ default: m.Register })))
const Account = lazy(() => import('@/pages/Account').then((m) => ({ default: m.Account })))

export default function App() {
  const hydrate = useAuth((s) => s.hydrate)
  const boot = useBootSplash()

  useEffect(() => {
    void hydrate()
  }, [hydrate])

  return (
    /* حاجزٌ حول الشجرة: عطبٌ في صفحة لا يُفرغ التطبيق كلّه */
    <ErrorBoundary fallback={<CrashFallback />}>
      <Seo />
      <div inert={boot !== 'done'}>
      <Suspense fallback={<LazyFallback />}>
      <Routes>
      <Route element={<Layout />}>
        <Route index element={<Home />} />
        <Route path="suppliers" element={<Suppliers />} />
        <Route path="suppliers/:slug" element={<SupplierProfile />} />
        <Route path="products" element={<Products />} />
        <Route path="products/:slug" element={<ProductDetail />} />
        <Route path="about" element={<About />} />
        <Route path="login" element={<Login />} />
        <Route path="register" element={<Register />} />

        <Route
          path="rfq/new"
          element={
            <Protected>
              <CreateRfq />
            </Protected>
          }
        />
        <Route
          path="my/rfqs"
          element={
            <Protected>
              <MyRfqs />
            </Protected>
          }
        />
        <Route
          path="rfqs/:rfqId/quotations"
          element={
            <Protected>
              <CompareQuotes />
            </Protected>
          }
        />
        <Route
          path="rfq-inbox"
          element={
            <Protected>
              <RfqInbox />
            </Protected>
          }
        />
        <Route
          path="rfqs/:rfqId/quote"
          element={
            <Protected>
              <SendQuote />
            </Protected>
          }
        />
        <Route
          path="my/products"
          element={
            <Protected>
              <MyProducts />
            </Protected>
          }
        />
        <Route
          path="account"
          element={
            <Protected>
              <Account />
            </Protected>
          }
        />
        <Route
          path="quotations/:quotationId"
          element={
            <Protected>
              <QuotationDetail />
            </Protected>
          }
        />
        <Route
          path="my/quotations"
          element={
            <Protected>
              <MyQuotations />
            </Protected>
          }
        />
        <Route
          path="orders"
          element={
            <Protected>
              <Orders />
            </Protected>
          }
        />

        <Route
          path="messages"
          element={
            <Protected>
              <Messages />
            </Protected>
          }
        />
        <Route
          path="messages/:conversationId"
          element={
            <Protected>
              <Messages />
            </Protected>
          }
        />

        <Route
          path="accounting"
          element={
            <Protected>
              <AccountingLayout />
            </Protected>
          }
        >
          <Route index element={<Navigate to="/accounting/trial-balance" replace />} />
          <Route path="trial-balance" element={<TrialBalance />} />
          <Route path="income-statement" element={<IncomeStatement />} />
          <Route path="balance-sheet" element={<BalanceSheet />} />
          <Route path="journal" element={<Journal />} />
          <Route path="accounts" element={<ChartOfAccounts />} />
        </Route>

        {/* تتبّع عام — بلا تسجيل دخول */}
        <Route path="track" element={<TrackShipment />} />
        <Route path="track/:token" element={<TrackShipment />} />

        <Route
          path="orders/:orderId/ship"
          element={
            <Protected>
              <CreateShipment />
            </Protected>
          }
        />

        <Route
          path="payments"
          element={
            <Protected>
              <PaymentsLayout />
            </Protected>
          }
        >
          <Route index element={<Collect />} />
          <Route path="log" element={<PaymentLog />} />
          <Route path="credit-notes" element={<CreditNotes />} />
        </Route>

        <Route
          path="payments/statement/:customerId"
          element={
            <Protected>
              <Statement />
            </Protected>
          }
        />

        <Route
          path="orders/:orderId/return"
          element={
            <Protected>
              <CreateReturn />
            </Protected>
          }
        />

        <Route
          path="returns"
          element={
            <Protected>
              <ReturnsLayout />
            </Protected>
          }
        >
          <Route index element={<Returns />} />
          <Route path="disputes" element={<Disputes />} />
        </Route>

        <Route
          path="returns/disputes/new/:orderId"
          element={
            <Protected>
              <CreateDispute />
            </Protected>
          }
        />
        <Route
          path="returns/disputes/:disputeId"
          element={
            <Protected>
              <DisputeDetail />
            </Protected>
          }
        />
        <Route
          path="returns/:returnId"
          element={
            <Protected>
              <ReturnDetail />
            </Protected>
          }
        />

        <Route
          path="shipping"
          element={
            <Protected>
              <ShippingLayout />
            </Protected>
          }
        >
          <Route index element={<Shipments />} />
          <Route path="carriers" element={<Carriers />} />
        </Route>

        <Route
          path="shipping/:shipmentId"
          element={
            <Protected>
              <ShipmentDetail />
            </Protected>
          }
        />

        <Route
          path="purchasing"
          element={
            <Protected>
              <PurchasingLayout />
            </Protected>
          }
        >
          <Route index element={<PurchaseOrders />} />
          <Route path="vendors" element={<Vendors />} />
          <Route path="pay" element={<PayVendors />} />
        </Route>

        <Route
          path="purchasing/orders/:orderId"
          element={
            <Protected>
              <PurchaseOrderDetail />
            </Protected>
          }
        />

        <Route
          path="inventory"
          element={
            <Protected>
              <InventoryLayout />
            </Protected>
          }
        >
          <Route index element={<StockItems />} />
          <Route path="items/:itemId" element={<StockItemDetail />} />
          <Route path="movements" element={<StockMovements />} />
          <Route path="valuation" element={<StockValuation />} />
          <Route path="counts" element={<StockCounts />} />
          <Route path="warehouses" element={<Warehouses />} />
        </Route>

        <Route
          path="admin"
          element={
            <Protected>
              <AdminLayout />
            </Protected>
          }
        >
          <Route index element={<AdminDashboard />} />
          <Route path="summary" element={<AdminSummary />} />
          <Route path="products" element={<AdminProducts />} />
          <Route path="users" element={<AdminUsers />} />
          <Route path="trade" element={<AdminTrade />} />
          <Route path="suppliers" element={<AdminSuppliers />} />
          <Route path="categories" element={<AdminCategories />} />
          <Route path="units" element={<AdminUnits />} />
          <Route path="emails" element={<AdminEmails />} />
          <Route path="banners" element={<AdminBanners />} />
          <Route path="finance" element={<AdminFinance />} />
          <Route path="commissions" element={<AdminCommissions />} />
          <Route path="plans" element={<AdminPlans />} />
          <Route path="inbox" element={<AdminInbox />} />
          <Route path="audit" element={<AdminAudit />} />
        </Route>

        {/* المسار المجهول يُقال للمستخدم لا يُبتلع: الإعادة الصامتة تُربكه */}
        <Route path="*" element={<NotFound />} />
        </Route>
        </Routes>
      </Suspense>
      </div>
      {boot !== 'done' && <BootSplash leaving={boot === 'out'} />}
      <InstallPrompt />
    </ErrorBoundary>
  )
}

function CrashFallback() {
  const { t } = useI18n()

  return (
    <div className="mx-auto w-full max-w-md px-4 py-16 text-center">
      <h1 className="text-lg font-bold">{t.common.errorTitle}</h1>
      <p className="muted mt-2 text-sm">{t.common.error}</p>

      <a href="/" className="mt-5 inline-block text-sm font-medium text-accent hover:underline">
        {t.nav.home}
      </a>
    </div>
  )
}

function LazyFallback() {
  const { t } = useI18n()

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-8">
      <Loading message={t.common.loading} />
    </div>
  )
}
